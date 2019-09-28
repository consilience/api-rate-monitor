<?php

namespace Consilience\Api\RateMonitor;

/**
 * Rolling window monitor.
 */

use Psr\Cache\CacheItemInterface;
use Psr\Http\Message\RequestInterface;

class RollingWindow implements MonitorInterface
{
    // TODO: period.
    // TODO: monitor resolution.

    /**
     * @var int the size of the window to monitor
     */
    protected $windowSeconds;

    public function __construct(int $windowSeconds = 60)
    {
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Add a request to the time series.
     *
     * @inherit
     */
    public function addRequest(CacheItemInterface $cacheItem, RequestInterface $request)
    {
        $timeSeries = $cacheItem->get() ?? [];

        $time = time();

        // TODO: set the resolution of the time point.

        // Add an entry for this call.

        $timeSeries[$time] = ($timeSeries[$time] ?? 0) + 1;

        // Remove any older entries.
        // The expiredTime is how far back we look when keeping entries.

        $expiredTime = $time - $this->windowSeconds;

        // CHECKME: would it be more effecient to treat this as a queue,
        // and unshift older values from the back of the array, until
        // we reach a value in range?

        $timeSeries = array_filter($timeSeries, function ($key) use ($expiredTime) {
            return $key > $expiredTime;
        }, ARRAY_FILTER_USE_KEY);

        $cacheItem->set($timeSeries);

        // Keep the expiry a whole time period away, so if
        // we are not back within a monitoring period, the
        // call history expires and frees up cache.

        $cacheItem->expiresAfter($this->windowSeconds);
    }

    /**
     * List how many requests have been made in the rolling period.
     * This would be compared to the allocation we have in that period.
     */
    public function getAllocationUsed(CacheItemInterface $cacheItem)
    {
        $expiredTime = time() - $this->windowSeconds;

        $total = 0;

        foreach ($this->timeSeries($cacheItem) as $time => $count) {
            if ($time > $expiredTime) {
                $total += $count;
            }
        }

        return $total;
    }

    /**
     * Calculate how long we need to wait until we can make N requests
     * without blowing the rolling window allocation.
     */
    public function getWaitSeconds(int $requestCount = 1): int
    {
        // TODO
    }

    /**
     * FIXME: Will throw an exceptino if the cache item contains data other
     * than an array.
     */
    protected function timeSeries(CacheItemInterface $cacheItem): array
    {
        return $timeSeries = $cacheItem->get() ?? [];
    }
}
