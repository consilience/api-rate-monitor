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
     * @var string
     */
    protected $key;

    /**
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
        MonitorInterface $monitor
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

        // TODO: here any throttling strategies, such as sleeping, aborting,
        // or even just warning.

        // Send the request.

        $response = $this->client->sendRequest($request);

        // TODO: Here any post request strategies, which may include adding
        // metadata to the response for upstream handling.

        return $response;
    }

    /**
     * @param string $key set the key the rolling window represents
     * @return $this
     */
    protected function setKey(?string $key): self
    {
        $this->key = $key;
        return $this;
    }

    /**
     * @string $key set the key the rolling window represents
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
     * @return int the number of calls for the given key, or last used key in the window.
     */
    public function getAllocationUsed(string $key = null): int
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
}
