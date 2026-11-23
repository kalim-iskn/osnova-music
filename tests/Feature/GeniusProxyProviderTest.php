<?php

namespace Tests\Feature;

use App\Console\Commands\RefreshGeniusProxiesCommand;
use App\Services\Genius\GeniusProxyProvider;
use App\Services\Genius\GeniusClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeniusProxyProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_proxy_is_cooled_down_and_the_request_uses_the_next_one(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        foreach (['http://8.8.8.8:8080', 'http://1.1.1.1:8080'] as $i => $address) {
            DB::table('genius_proxies')->insert(['address' => $address, 'working' => true, 'checked_at' => now(), 'latency_ms' => 100 + $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $provider = new GeniusProxyProvider();
        $seen = [];
        $handler = function ($request, $options) use (&$seen) {
            $seen[] = $options['proxy'];
            return Create::promiseFor(new Response(count($seen) === 1 ? 403 : 200));
        };
        $send = ($provider->middleware())($handler);
        $response = $send(new Request('GET', 'https://genius.test/api/search'), [])->wait();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['http://8.8.8.8:8080', 'http://1.1.1.1:8080'], $seen);
        $this->assertNotNull(DB::table('genius_proxies')->where('address', $seen[0])->value('failed_until'));
        $this->assertFalse((bool) DB::table('genius_proxies')->where('address', $seen[0])->value('working'));
        $this->assertSame($seen[1], $provider->select());
    }

    public function test_previous_failed_proxy_is_not_ready_until_it_passes_a_new_check(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        DB::table('genius_proxies')->insert(['address' => 'http://8.8.8.8:8080', 'working' => true, 'checked_at' => now(), 'failed_until' => now()->subMinute(), 'last_error' => 'Connection failed', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(RuntimeException::class);
        (new GeniusProxyProvider())->select();
    }

    public function test_no_proxy_never_sends_the_request_directly(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        $called = false;
        $send = ((new GeniusProxyProvider())->middleware())(function () use (&$called) {
            $called = true;
            return Create::promiseFor(new Response(200));
        });
        try {
            $send(new Request('GET', 'https://genius.test'), [])->wait();
            $this->fail('The request must fail when no proxy is available.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('proxy unavailable', $exception->getMessage());
        }
        $this->assertFalse($called);
    }

    public function test_rate_limit_does_not_mark_proxy_as_bad(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        DB::table('genius_proxies')->insert(['address' => 'http://8.8.8.8:8080', 'working' => true, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $send = ((new GeniusProxyProvider())->middleware())(fn () => Create::promiseFor(new Response(429)));
        $this->assertSame(429, $send(new Request('GET', 'https://genius.test'), [])->wait()->getStatusCode());
        $this->assertNull(DB::table('genius_proxies')->value('failed_until'));
    }

    public function test_address_extraction_deduplicates_and_rejects_private_addresses(): void
    {
        $addresses = (new RefreshGeniusProxiesCommand())->extractAddresses('8.8.8.8:8080 8.8.8.8:8080 127.0.0.1:80 192.168.1.1:80 socks5://1.1.1.1:1080');
        $this->assertSame(['http://8.8.8.8:8080', 'socks5h://1.1.1.1:1080'], $addresses);
        $html = '<table><tr><td>1</td><td>8.8.8.8</td><td>8080</td><td>HTTP</td></tr>'
            .'<tr><td data-ip="MS4xLjEuMQ=="></td><td data-port="MTA4MA=="></td><td>SOCKS5</td></tr></table>';
        $this->assertSame(['http://8.8.8.8:8080', 'socks5h://1.1.1.1:1080'], (new RefreshGeniusProxiesCommand())->extractAddresses($html));
    }

    public function test_genius_client_does_not_send_directly_if_no_proxy_is_available(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        config()->set('services.genius.base_url', 'https://genius.test/api');
        config()->set('services.genius.retry_times', 1);
        Http::fake(['*' => Http::response(['response' => ['hits' => []]])]);
        try {
            (new GeniusClient())->searchSongs('an uncached title');
            $this->fail('No proxy should cause a connection failure.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('proxy unavailable', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_refresh_accepts_only_genius_json_and_deduplicates_sources(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        config()->set('services.genius.proxy_check_limit', 10);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'genius.com/api/search')) {
                return Http::response('<html>Cloudflare</html>', 200);
            }
            return Http::response('8.8.8.8:8080 8.8.8.8:8080');
        });
        $this->artisan('genius:refresh-proxies')->assertSuccessful();
        $this->assertSame(2, DB::table('genius_proxies')->count()); // HTTP and SOCKS5 are distinct.
        $this->assertSame(0, DB::table('genius_proxies')->where('working', true)->count());
    }

    public function test_refresh_records_a_valid_genius_response(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        Http::fake(fn ($request) => str_contains($request->url(), 'genius.com/api/search')
            ? Http::response(['response' => ['hits' => []]])
            : Http::response('8.8.8.8:8080'));
        $this->artisan('genius:refresh-proxies')->assertSuccessful();
        $this->assertSame(2, DB::table('genius_proxies')->where('working', true)->count());
    }

    public function test_private_genius_credentials_are_not_used_with_public_proxies(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        config()->set('services.genius.access_token', 'private-token');
        config()->set('services.genius.cookie', 'private-cookie=value');
        $client = new GeniusClient();
        $reflection = new \ReflectionClass($client);
        $this->assertNull($reflection->getProperty('accessToken')->getValue($client));
        $this->assertSame([], $reflection->getProperty('cookieJar')->getValue($client)->toArray());
    }

    public function test_batch_and_apple_player_requests_do_not_bypass_missing_proxy(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        config()->set('services.genius.base_url', 'https://genius.test/api');
        config()->set('services.genius.retry_times', 1);
        Http::fake(['*' => Http::response(['response' => []])]);
        $client = new GeniusClient();
        $this->assertNull($client->songAppleMusicDurationSeconds(123));
        $this->assertNull($client->songDetails([123])[123]);
        $client->albumTracksMany([123]);
        Http::assertNothingSent();
    }
}
