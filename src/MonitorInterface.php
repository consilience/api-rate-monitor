<?php

namespace Consilience\Api\RateMonitor;

/**
 * Interface for monitoring requests, keeping track of the time series.
 */

use Psr\Cache\CacheItemInterface;
use Psr\Http\Message\RequestInterface;

interface MonitorInterface
{
    /**
     * Add a request to the time series.
     * Given a cache item, which it can use as it likes, and the request
     * in case any information in the request is useful for the monitoring
     * strategy.
     *
     */
    public function addRequest(CacheItemInterface $cacheItem, RequestInterface $request);

    /**
     * Get the total number of requests used in the allocated unit.
     * Examples include the requests in a fixed day, or in a rolling minute.
     */
    public function getAllocationUsed(CacheItemInterface $cacheItem);

    /**
     * Calculate how long we need to wait until we can make N requests
     * without blowing the allocation.
     */
    public function getWaitSeconds(int $requestCount = 1): int;
}
