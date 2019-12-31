<?php

declare(strict_types=1);

namespace Consilience\Api\RateMonitor;

/**
 * PSR-18 client decorator to count requests over a rolling window.
 */

use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpClient implements ClientInterface
{
    /**
     * @var string
     */
    protected $key;

    /**
     * The base client object, or a parent decorator if many are chained.
     *
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var CacheItemPoolInterface
     */
    protected $cache;

    /**
     * @var TBC class to monitor the rate
     */
    protected $monitor;

    /**
     * @param ClientInterface $client a PSR-18 client
     * @param CacheItemPoolInterface $cache a PSR-6 cache pool
     */
    public function __construct(
        ClientInterface $client,
        CacheItemPoolInterface $cache,
        MonitorStrategyInterface $monitor
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->monitor = $monitor;
    }

    /**
     * Send a request, maintaining the request count for the key over
     * the rolling window.
     *
     * @inherit
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Record the request to the time series first.
        // This bit should really involve locking the key, but we will treat
        // that as out-of-scope for now.

        if ($this->key) {
            $cacheItem = $this->cache->getItem($this->key);

            $this->monitor->addRequest($cacheItem, $request);

            // Save it back to the cache.

            $this->cache->save($cacheItem);
        }

        // Here put any throttling strategies, such as sleeping, aborting,
        // or even just warning.

        // Send the request.

        return $this->client->sendRequest($request);

        // Before returning the response, any post request strategies,
        // which may include adding metadata to the response for upstream handling.
    }

    /**
     * @string $key set the key the rolling window represents
     *
     * @return static clone of $this
     */
    public function withKey(?string $key): self
    {
        return (clone $this)->setKey($key);
    }

    /**
     * @return ?string the last used key
     */
    public function getKey(): ?string
    {
        return $this->key;
    }

    /**
     * Returns the number of calls for the given key, or last used key in
     * the rolling window.
     *
     * @inherit
     */
    public function getAllocationUsed(?string $key = null): int
    {
        if ($key === null) {
            $key = $this->key;
        }

        if ($key) {
            $cacheItem = $this->cache->getItem($key);

            return $this->monitor->getAllocationUsed($cacheItem);
        }

        return 0;
    }

    /**
     * Returns the seconds we must wait until the rolling window clears enough
     * previous requests to enable a burst of $requestCount requests.
     *
     * @inherit
     */
    public function getWaitSeconds(
        ?string $key = null,
        int $requestCount = 1
    ): int {
        if ($key === null) {
            $key = $this->key;
        }

        if ($key) {
            $cacheItem = $this->cache->getItem($key);

            return $this->monitor->getWaitSeconds($cacheItem, $requestCount);
        }

        return 0;
    }

    /**
     * @param string $key set the key the rolling window represents
     *
     * @return $this
     */
    protected function setKey(?string $key): self
    {
        $this->key = $key;
        return $this;
    }

    /**
     * Pass any unknown methods up the chain to the parent decorator,
     * if there is one.
     */
    public function __call($method, $parameters)
    {
        $result = $this->client->$method(...$parameters);

        return ($result === $this->client) ? $this : $result;
    }
}
