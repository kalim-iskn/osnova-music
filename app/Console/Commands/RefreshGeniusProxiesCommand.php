<?php

namespace App\Console\Commands;

use App\Services\Genius\GeniusProxyProvider;
use App\Services\Genius\GeniusClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class RefreshGeniusProxiesCommand extends Command
{
    protected $signature = 'genius:refresh-proxies
        {--sources-only : Check sources without contacting Genius or saving proxies}
        {--refill : Run a smaller check when no working proxies are available}';

    protected $description = 'Find public proxies that can actually reach the Genius API';

    private const SOURCES = [
        // Temporarily disabled: the site currently requires a JavaScript browser check.
        // 'https://best-proxies.ru/proxylist/free/',
        // Disabled at the user's request while alternative lists are evaluated.
        // 'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt',
        // 'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/socks5.txt',
        // 'https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/http/data.txt',
        // 'https://raw.githubusercontent.com/proxifly/free-proxy-list/main/proxies/protocols/socks5/data.txt',
        // 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt',
        // 'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/socks5.txt',
        // 'https://raw.githubusercontent.com/FusionProxies/free-proxy-list/main/proxies/http.txt',
        // 'https://raw.githubusercontent.com/FusionProxies/free-proxy-list/main/proxies/socks5.txt',
        // 'https://raw.githubusercontent.com/proxyscrape/free-proxy-list/main/proxies/protocols/http/data.txt',
        // 'https://raw.githubusercontent.com/proxyscrape/free-proxy-list/main/proxies/protocols/socks5/data.txt',
        // 'https://raw.githubusercontent.com/MADEVAL/free-proxy-list/main/http.txt',
        // 'https://raw.githubusercontent.com/MADEVAL/free-proxy-list/main/socks5.txt',
        // New sources: HTTPS-capable HTTP proxies and SOCKS5, checked separately against Genius.
        'https://raw.githubusercontent.com/Moleway/Free-Proxy-List/main/https.txt',
        'https://raw.githubusercontent.com/Moleway/Free-Proxy-List/main/socks5.txt',
        'https://raw.githubusercontent.com/MrMarble/proxy-list/main/socks5.txt',
        'https://raw.githubusercontent.com/Dahono-com/free-proxy-list/main/proxies/https.txt',
        'https://raw.githubusercontent.com/proxmint/free-proxy-list/main/proxies/socks5.txt',
    ];

    public function handle(GeniusProxyProvider $provider): int
    {
        if (! $provider->enabled()) {
            $this->info('Genius proxies are disabled.');
            return self::SUCCESS;
        }
        // An OS lock is released automatically if the container or process stops.
        $lock = fopen(storage_path('framework/cache/genius-proxy-refresh.lock'), 'c');
        if ($lock === false) {
            $this->error('Cannot open the proxy refresh lock file.');
            return self::FAILURE;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->info('Proxy refresh is already running.');
            return self::SUCCESS;
        }
        Cache::put('genius-proxy-refresh-status', ['started_at' => now()->toIso8601String()], 1500);
        try {
            $lists = $this->fetchFreshProxyCheckSources();
            // Disabled at the user's request: 2ip, Advanced and Geonix.
            // $lists = $this->fetchPythonSources();
            foreach (self::SOURCES as $source) {
                try {
                    $response = Http::connectTimeout(4)->timeout(10)->get($source);
                    if ($response->successful()) {
                        $body = $response->body();
                        if (strlen($body) > 300000) {
                            $body = substr($body, random_int(0, strlen($body) - 300000), 300000);
                        }
                        $addresses = $this->extractAddresses($body, str_contains($source, 'socks5') ? 'socks5h' : 'http');
                        $lists[] = $this->prioritizeAddresses($addresses);
                        $this->line(sprintf('Source %s: %d addresses', $source, count($addresses)));
                    } else {
                        $this->warn(sprintf('Source %s: HTTP %d', $source, $response->status()));
                    }
                } catch (Throwable $exception) {
                    $this->warn('Proxy source unavailable: '.$source);
                }
            }

            if ($this->option('sources-only')) {
                $this->info('Source-only check finished; Genius was not contacted.');
                return self::SUCCESS;
            }

            // Round-robin prevents the first large source from monopolising the checks.
            $candidates = [];
            $limit = max(1, min(1000, (int) config('services.genius.proxy_check_limit', 200)));
            if ($this->option('refill')) {
                $limit = min($limit, 50);
            }
            while (count($candidates) < $limit && $lists !== []) {
                foreach ($lists as $index => &$list) {
                    $candidate = array_shift($list);
                    if ($candidate === null) {
                        unset($lists[$index]);
                    } else {
                        $candidates[$candidate] = $candidate;
                    }
                    if (count($candidates) >= $limit) {
                        break;
                    }
                }
                unset($list);
            }

            $concurrency = max(1, min(20, (int) config('services.genius.proxy_check_concurrency', 8)));
            $good = 0;
            $checked = 0;
            foreach (array_chunk(array_values($candidates), $concurrency) as $chunk) {
                $times = [];
                $responses = Http::pool(function (Pool $pool) use ($chunk, &$times): void {
                    foreach ($chunk as $address) {
                        $pool->as($address)->connectTimeout(6)->timeout(10)
                            ->withOptions(['proxy' => $address, 'verify' => true, 'on_stats' => function ($stats) use (&$times, $address): void {
                                $times[$address] = (int) round($stats->getTransferTime() * 1000);
                            }])
                            ->acceptJson()->withHeaders(GeniusClient::defaultRequestHeaders())
                            ->get('https://genius.com/api/search', ['q' => 'SALUKI']);
                    }
                }, $concurrency);
                foreach ($chunk as $address) {
                    $checked++;
                    $response = $responses[$address] ?? null;
                    $valid = $response instanceof Response && $response->successful()
                        && is_array($response->json('response'));
                    $good += (int) $valid;
                    $error = $this->proxyCheckError($response);
                    DB::table('genius_proxies')->upsert([[
                        'address' => $address, 'working' => $valid, 'checked_at' => now(),
                        'failed_until' => $valid ? null : now()->addMinutes(10),
                        'last_error' => $valid ? null : $error,
                        'latency_ms' => $times[$address] ?? null, 'created_at' => now(), 'updated_at' => now(),
                    ]], ['address'], ['working', 'checked_at', 'failed_until', 'last_error', 'latency_ms', 'updated_at']);
                }
                if ($this->option('refill') && $good >= 2) {
                    break;
                }
            }
            DB::table('genius_proxies')->where('checked_at', '<', now()->subDays(2))->delete();
            $this->info(sprintf('Checked %d proxies; %d reached Genius.', $checked, $good));
            Cache::put('genius-proxy-last-refresh', ['finished_at' => now()->toIso8601String(), 'checked' => $checked, 'working' => $good], 86400);
            return self::SUCCESS;
        } finally {
            Cache::forget('genius-proxy-refresh-status');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<int, array<int, string>> */
    private function fetchFreshProxyCheckSources(): array
    {
        $lists = [];
        foreach (['https' => 'http', 'socks5' => 'socks5h'] as $protocol => $scheme) {
            try {
                $response = Http::timeout(10)->acceptJson()->get('https://proxycheck.app/api/v1/public-proxies', [
                    'protocol' => $protocol,
                    'fresh_minutes' => 30,
                    'limit' => 100,
                    'sort' => 'uptime_desc',
                ]);
                $items = $response->json('items');
                if (! $response->successful() || ! is_array($items)) {
                    $this->warn(sprintf('Source proxycheck %s unavailable: HTTP %d or invalid response.', $protocol, $response->status()));
                    continue;
                }
                $addresses = [];
                foreach ($items as $item) {
                    if (! is_array($item) || strtolower((string) ($item['protocol'] ?? '')) !== $protocol
                        || strtolower((string) ($item['status'] ?? '')) !== 'live'
                        || ! is_string($item['proxy_norm'] ?? null)
                        || ! preg_match('~^(?:https?|socks5)://((?:\d{1,3}\.){3}\d{1,3}):(\d{1,5})$~i', $item['proxy_norm'], $match)) {
                        continue;
                    }
                    $addresses = array_merge($addresses, $this->extractAddresses($match[1].':'.$match[2], $scheme));
                }
                $addresses = array_values(array_unique($addresses));
                $lists[] = $this->prioritizeAddresses($addresses);
                $this->line(sprintf('Source proxycheck %s: %d addresses', $protocol, count($addresses)));
            } catch (Throwable $exception) {
                $this->warn(sprintf('Source proxycheck %s unavailable.', $protocol));
            }
        }
        return $lists;
    }

    private function proxyCheckError(mixed $response): string
    {
        if ($response instanceof Response) {
            return $response->successful() ? 'Invalid Genius JSON' : 'HTTP '.$response->status();
        }
        $message = $response instanceof Throwable ? $response->getMessage() : '';
        return match (true) {
            str_contains($message, 'cURL error 28') => 'Connection timed out',
            str_contains($message, 'cURL error 7') => 'Proxy connection failed',
            str_contains($message, 'cURL error 60') => 'TLS verification failed',
            str_contains($message, 'cURL error 97') => 'SOCKS negotiation failed',
            default => 'Connection failed',
        };
    }

    /** @param list<string> $addresses @return list<string> */
    private function prioritizeAddresses(array $addresses): array
    {
        // These lists are ranked by their maintainers: try fresh high-ranked entries,
        // while sampling the rest so a bad first page does not dominate every run.
        $ranked = array_slice($addresses, 0, 20);
        $others = array_slice($addresses, 20);
        shuffle($others);
        $result = [];
        foreach ($ranked as $index => $address) {
            $result[] = $address;
            if (isset($others[$index])) {
                $result[] = $others[$index];
            }
        }
        return array_merge($result, array_slice($others, count($ranked)));
    }

    /** @return array<int, array<int, string>> */
    private function fetchPythonSources(): array
    {
        $base = rtrim((string) config('services.genius.proxy_source_url', 'http://proxy-source-fetch:8765'), '/');
        $lists = [];
        foreach (['2ip', 'advanced', 'geonix'] as $source) {
            try {
                $response = Http::connectTimeout(4)->timeout(90)->acceptJson()->get($base.'/sources/'.$source);
                $payload = $response->json();
                if (! $response->successful() || ! is_array($payload) || ($payload['ok'] ?? false) !== true) {
                    $error = is_array($payload) && is_string($payload['error'] ?? null)
                        ? $payload['error'] : 'HTTP '.$response->status();
                    $this->warn(sprintf('Source %s unavailable: %s', $source, $error));
                    continue;
                }
                if ($source !== 'geonix') {
                    $content = $payload['content'] ?? null;
                    if (! is_string($content)) {
                        $this->warn(sprintf('Source %s returned invalid content.', $source));
                        continue;
                    }
                    $addresses = $this->extractAddresses($content);
                    shuffle($addresses);
                    $lists[] = $addresses;
                    $this->line(sprintf('Source %s: %d addresses', $source, count($addresses)));
                    continue;
                }

                $groups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
                foreach (['HTTP' => 'http', 'HTTPS' => 'http', 'SOCKS4' => 'socks4', 'SOCKS5' => 'socks5h'] as $protocol => $scheme) {
                    if (! is_array($groups[$protocol] ?? null)) {
                        $this->warn(sprintf('Source geonix %s unavailable.', $protocol));
                        continue;
                    }
                    $values = array_filter($groups[$protocol], 'is_string');
                    $addresses = $this->extractAddresses(implode("\n", $values), $scheme);
                    shuffle($addresses);
                    $lists[] = $addresses;
                    $this->line(sprintf('Source geonix %s: %d addresses', $protocol, count($addresses)));
                }
            } catch (Throwable $exception) {
                $this->warn(sprintf('Source %s unavailable: fetch service error.', $source));
            }
        }
        return $lists;
    }

    /** @return list<string> */
    public function extractAddresses(string $text, string $defaultProtocol = 'http'): array
    {
        if (stripos($text, '<tr') !== false) {
            $previous = libxml_use_internal_errors(true);
            $document = new \DOMDocument();
            if ($document->loadHTML($text, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
                foreach ($document->getElementsByTagName('tr') as $row) {
                    $ip = null;
                    $port = null;
                    foreach ($row->getElementsByTagName('td') as $cell) {
                        $value = trim($cell->textContent);
                        foreach (['data-ip', 'data-port'] as $attribute) {
                            if ($cell->hasAttribute($attribute)) {
                                $encoded = $cell->getAttribute($attribute);
                                $value = base64_decode($encoded, true) ?: $encoded;
                            }
                        }
                        if ($ip === null && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $ip = $value;
                        } elseif ($ip !== null && $port === null && ctype_digit($value) && (int) $value <= 65535) {
                            $port = $value;
                        }
                    }
                    if ($ip !== null && $port !== null) {
                        $protocol = stripos($row->textContent, 'socks5') !== false ? 'socks5h' : $defaultProtocol;
                        $text .= ' '.$protocol.'://'.$ip.':'.$port;
                    }
                }
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        preg_match_all('~(?:(https?|socks5h?|socks4)://)?((?:\d{1,3}\.){3}\d{1,3}):(\d{1,5})\b~i', $text, $matches, PREG_SET_ORDER);
        $addresses = [];
        foreach ($matches as $match) {
            $ip = $match[2];
            $port = (int) $match[3];
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || $port < 1 || $port > 65535) {
                continue;
            }
            $protocol = strtolower($match[1] ?: $defaultProtocol);
            $protocol = $protocol === 'socks5' ? 'socks5h' : $protocol;
            $address = $protocol.'://'.$ip.':'.$port;
            $addresses[$address] = $address;
        }
        return array_values($addresses);
    }
}
