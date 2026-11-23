<?php

namespace App\Services\Genius;

use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** Selects only previously checked proxies; never falls back to a direct connection. */
class GeniusProxyProvider
{
    private ?string $active = null;
    private int $activeValidatedUntil = 0;

    public function enabled(): bool
    {
        return (bool) config('services.genius.proxy_enabled', true);
    }

    public function select(array $excluded = []): string
    {
        if ($this->active !== null && ! in_array($this->active, $excluded, true)) {
            if ($this->activeValidatedUntil > time()) {
                return $this->active;
            }
            $stillValid = DB::table('genius_proxies')->where('address', $this->active)->where('working', true)
                ->whereNull('last_error')
                ->where('checked_at', '>=', now()->subHours(2))
                ->where(fn ($q) => $q->whereNull('failed_until')->orWhere('failed_until', '<=', now()))->exists();
            if ($stillValid) {
                $this->activeValidatedUntil = time() + 30;
                return $this->active;
            }
            $this->active = null;
        }

        $address = DB::table('genius_proxies')->where('working', true)
            ->whereNull('last_error')
            ->where('checked_at', '>=', now()->subHours(2))
            ->where(fn ($q) => $q->whereNull('failed_until')->orWhere('failed_until', '<=', now()))
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('address', $excluded))
            ->orderBy('latency_ms')->orderBy('id')->value('address');

        if (! is_string($address)) {
            throw new RuntimeException('Genius proxy unavailable: no checked proxy is currently available.');
        }

        $this->activeValidatedUntil = time() + 30;
        Log::channel((string) config('services.genius.log_channel', 'genius_matching'))
            ->info('Genius proxy selected.', ['proxy' => $address]);
        return $this->active = $address;
    }

    public function failed(string $address, string $reason): void
    {
        if ($this->active === $address) {
            $this->active = null;
        }
        DB::table('genius_proxies')->where('address', $address)->update([
            'working' => false,
            'failed_until' => now()->addMinutes(10),
            'last_error' => mb_substr($reason, 0, 255),
            'updated_at' => now(),
        ]);
        Log::channel((string) config('services.genius.log_channel', 'genius_matching'))
            ->warning('Genius proxy temporarily unavailable.', ['proxy' => $address, 'reason' => mb_substr($reason, 0, 255)]);
    }

    /** Guzzle middleware runs for both ordinary requests and concurrent pools. */
    public function middleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                if (! $this->enabled()) {
                    return $handler($request, $options);
                }

                $send = function (array $tried) use (&$send, $handler, $request, $options) {
                    try {
                        $proxy = $this->select($tried);
                    } catch (Throwable $exception) {
                        return Create::rejectionFor($exception);
                    }
                    $tried[] = $proxy;
                    $requestOptions = $options;
                    $requestOptions['proxy'] = $proxy;

                    return $handler($request, $requestOptions)->then(
                        function (ResponseInterface $response) use ($proxy, $tried, $request, &$send) {
                            $invalidJson = $response->getStatusCode() === 200
                                && str_contains($request->getHeaderLine('Accept'), 'application/json')
                                && ! is_array(json_decode((string) $response->getBody(), true));
                            if (in_array($response->getStatusCode(), [403, 407], true) || $invalidJson) {
                                $this->failed($proxy, $invalidJson ? 'Invalid JSON response' : 'HTTP '.$response->getStatusCode());
                                if (count($tried) < 3) {
                                    return $send($tried);
                                }
                            }
                            return $response;
                        },
                        function ($reason) use ($proxy, $tried, &$send) {
                            $this->failed($proxy, $reason instanceof Throwable ? $reason->getMessage() : 'Connection failed');
                            return count($tried) < 3 ? $send($tried) : Create::rejectionFor($reason);
                        },
                    );
                };

                return $send([]);
            };
        };
    }
}
