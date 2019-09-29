<?php

namespace Consilience\Api\RateMonitor;

/**
 * Interface for monitoring requests, keeping track of the time series.
 */

use Psr\Cache\CacheItemInterface;
use Psr\Http\Message\RequestInterface;

interface MonitorStrategyInterface
{
    /**
     * Add a request to the time series.
     * Given a cache item, which it can use as it likes, and the request
     * in case any information in the request is useful for the monitoring
     * strategy.
     *
     * @param CacheItemInterface $cacheItem The PSR-6 cache item containing the time series
     * @param RequestInterface $request the request that is about to be sent
     *
     * @return void
     */
    public function addRequest(
        CacheItemInterface $cacheItem,
        RequestInterface $request,
        int $requestCount = 1
    );

    /**
     * Get the total number of requests used in the allocated unit.
     * Examples include the requests in a fixed day, or in a rolling minute.
     * This is the number used now, without reference to when they expire,
     * so it can be used to calculate how big a burst of requests is
     * acceptable by the client now.
     *
     * @param CacheItemInterface $cacheItem The PSR-6 cache item containing the time series
     *
     * @return int the number of reqeusts made in the rolling window defined by the time series
     */
    public function getAllocationUsed(CacheItemInterface $cacheItem): int;

    /**
     * Calculate how long we need to wait until we can make N requests
     * without blowing the allocation.
     * The requests are assumed to be a "burst" i.e. sent in rapid succession.
     *
     * @param CacheItemInterface $cacheItem The PSR-6 cache item containing the time series
     * @param int $requestCount The number of requests we would like to burst
     *
     * @return int the number of seconds we must wait before a request burst
     */
    public function getWaitSeconds(CacheItemInterface $cacheItem, int $requestCount = 1): int;
}
