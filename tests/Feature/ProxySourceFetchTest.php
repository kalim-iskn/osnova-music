<?php

namespace Tests\Feature;

use App\Console\Commands\RefreshGeniusProxiesCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use App\Services\Genius\GeniusClient;
use Tests\TestCase;

class ProxySourceFetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxy_check_records_the_kind_of_connection_failure(): void
    {
        $method = (new \ReflectionClass(RefreshGeniusProxiesCommand::class))->getMethod('proxyCheckError');
        $command = new RefreshGeniusProxiesCommand();
        $this->assertSame('Connection timed out', $method->invoke($command, new ConnectionException('cURL error 28: timeout')));
        $this->assertSame('TLS verification failed', $method->invoke($command, new ConnectionException('cURL error 60: certificate')));
    }

    public function test_refresh_uses_new_sources_and_does_not_query_disabled_python_sources(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        config()->set('services.genius.proxy_check_limit', 10);
        Http::preventStrayRequests();
        Http::fake([
            'https://raw.githubusercontent.com/Moleway/Free-Proxy-List/main/https.txt' => Http::response('1.1.1.1:8080 127.0.0.1:80'),
            'https://raw.githubusercontent.com/MrMarble/proxy-list/main/socks5.txt' => Http::response('1.0.0.1:1080'),
            'https://genius.com/api/search*' => Http::response(['response' => ['hits' => []]]),
            '*' => Http::response(''),
        ]);
        $this->artisan('genius:refresh-proxies')->assertSuccessful();
        $addresses = \Illuminate\Support\Facades\DB::table('genius_proxies')->pluck('address')->all();
        $this->assertEqualsCanonicalizing(['http://1.1.1.1:8080', 'socks5h://1.0.0.1:1080'], $addresses);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'proxy-source-fetch'));
    }

    public function test_proxy_check_uses_the_same_headers_as_the_genius_client(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'genius.com/api/search')) {
                return $request->hasHeader('User-Agent', GeniusClient::defaultRequestHeaders()['User-Agent'])
                    && $request->hasHeader('Accept-Language', GeniusClient::defaultRequestHeaders()['Accept-Language'])
                    ? Http::response(['response' => ['hits' => []]]) : Http::response([], 403);
            }
            return str_contains($request->url(), 'Moleway/Free-Proxy-List/main/https.txt')
                ? Http::response('1.1.1.1:8080') : Http::response('');
        });
        $this->artisan('genius:refresh-proxies')->assertSuccessful();
        $this->assertDatabaseHas('genius_proxies', ['address' => 'http://1.1.1.1:8080', 'working' => true]);
    }

    public function test_source_only_check_never_contacts_genius(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => str_contains($request->url(), 'proxy-source-fetch')
            ? Http::response(['ok' => false, 'error' => 'offline'])
            : Http::response(''));
        $this->artisan('genius:refresh-proxies', ['--sources-only' => true])->assertSuccessful();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'genius.com'));
        $this->assertDatabaseCount('genius_proxies', 0);
    }

    public function test_empty_pool_refill_checks_at_most_fifty_candidates(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        config()->set('services.genius.proxy_check_limit', 200);
        Http::preventStrayRequests();
        $addresses = implode("\n", array_map(fn ($port) => '1.1.1.1:'.$port, range(1000, 1069)));
        Http::fake(function (Request $request) use ($addresses) {
            if (str_contains($request->url(), 'genius.com/api/search')) return Http::response([], 403);
            return str_contains($request->url(), 'Moleway/Free-Proxy-List/main/https.txt')
                ? Http::response($addresses) : Http::response('');
        });
        $this->artisan('genius:refresh-proxies', ['--refill' => true])->assertSuccessful();
        $this->assertDatabaseCount('genius_proxies', 50);
    }

    public function test_empty_pool_refill_stops_after_a_successful_batch(): void
    {
        config()->set('services.genius.proxy_enabled', true);
        config()->set('services.genius.proxy_check_limit', 200);
        config()->set('services.genius.proxy_check_concurrency', 8);
        Http::preventStrayRequests();
        $addresses = implode("\n", array_map(fn ($port) => '1.1.1.1:'.$port, range(1000, 1069)));
        Http::fake(function (Request $request) use ($addresses) {
            if (str_contains($request->url(), 'genius.com/api/search')) return Http::response(['response' => ['hits' => []]]);
            return str_contains($request->url(), 'Moleway/Free-Proxy-List/main/https.txt')
                ? Http::response($addresses) : Http::response('');
        });
        $this->artisan('genius:refresh-proxies', ['--refill' => true])->assertSuccessful();
        $this->assertDatabaseCount('genius_proxies', 8);
    }
}
