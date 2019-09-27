<?php

namespace Consilience\Api\RateMonitor;

/**
 * PSR-12 client decorator to count requests over a rolling window.
 */

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Cache\CacheItemPoolInterface;

class MonitorClient implements ClientInterface
{
    /**
     * This PSR-7 header sets the key to monitor the rate for,
     * and triggers the monitoring.
     */
    const RATE_LIMIT_KEY_HEADER_NAME  = 'X-Rate-Limit-Key';

    const RATE_LIMIT_WINDOW_SIZE = 'X-Rate-Limit-Window-Size';

    /**
     * @var string
     */
    protected $keyValue;

    /**
     * @var int
     */
    protected $monitorWindowSeconds;

    /**
     * @var array
     */
    protected $timeSeries;

    /**
     * @var string
     */
    protected $lastKey;

    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var CacheItemPoolInterface
     */
    protected $cache;

    /**
     *
     */
    public function __construct(ClientInterface $client, CacheItemPoolInterface $cache)
    {
        $this->client = $client;
        $this->cache = $cache;
    }

    /**
     *
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastKey = $this->key ?? $request->getHeaderLine(static::RATE_LIMIT_KEY_HEADER_NAME);
        $monitorWindowSeconds = $this->monitorWindowSeconds ??
            $request->getHeaderLine(static::RATE_LIMIT_WINDOW_SIZE);

        // Send the request first, then we have a response to put any
        // monitor details into.

        $response = $this->client->sendRequest($request);

        if ($this->key && $this->monitorWindowSeconds) {
            $cacheItem = $this->cache->getItem($this->lastKey);

            $this->timeSeries = $cacheItem->get() ?? [];

            $time = time();

            // Add an entry for this call.

            $this->timeSeries[] = $time;

            // Remove any older entries.

            $oldestTime = $time - $monitorWindowSeconds;

            // CHEKME: would it be more effecient to treat this as a queue,
            // and unshift older values from the back of the array, until
            // we reach a value in range?

            array_filter($this->timeSeries, function ($requestTime) use ($oldestTime) {
                return $requestTime > $oldestTime;
            });

            $cacheItem->set($this->timeSeries);

            // Keep the expiry a whole time period away, so if
            // we are not back within a monitoring period, the
            // call history expires and is freed up.

            $cacheItem->expiresAfter($monitorWindowSeconds);

            // Save it back to the cache.

            $this->cache->save($cacheItem);
        }

        return $response;
    }

    /**
     *
     */
    protected function setKey(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    /**
     *
     */
    public function withKey(string $key): self
    {
        return (clone $this)->setKey($key);
    }

    /**
     *
     */
    protected function setMonitorWindowSize(int $monitorWindowSeconds): self
    {
        $this->monitorWindowSeconds = $monitorWindowSeconds;
        return $this;
    }

    /**
     *
     */
    public function withMonitorWindowSize(string $monitorWindowSeconds): self
    {
        return (clone $this)->setMonitorWindowSize($monitorWindowSeconds);
    }

    /**
     * @return int the number of calls for the given key, or last used key in the window.
     */
    public function getWindowCount(string $key = null): int
    {
        if ($key) {
            $cacheItem = $this->cache->getItem($key);

            return count($cacheItem->get() ?? []);
        }

        return count($this->timeSeries ?? []);
    }

    /**
     * @return ?string the last used key
     */
    public function getLastKey(): ?string
    {
        return $this->lastKey;
    }
}
