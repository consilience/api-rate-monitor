<?php

namespace Consilience\Api\RateMonitor\MonitorStrategy;

/**
 * Rolling window monitor.
 */

use Psr\Cache\CacheItemInterface;
use Psr\Http\Message\RequestInterface;
use Consilience\Api\RateMonitor\MonitorStrategyInterface;

class RollingWindow implements MonitorStrategyInterface
{
    // TODO: monitor resolution.

    /**
     * @var int the size of the window to monitor
     */
    protected $windowSeconds;

    /**
     * @var int the number of requests allowed in t rolling window
     */
    protected $windowAllocation;

    public function __construct(int $windowSeconds = 60, int $windowAllocation = 60)
    {
        $this->windowSeconds = $windowSeconds;
        $this->windowAllocation = $windowAllocation;
    }

    /**
     * Add a request to the time series.
     *
     * @inherit
     */
    public function addRequest(
        CacheItemInterface $cacheItem,
        RequestInterface $request,
        int $requestCount = 1
    ) {
        $timeSeries = $this->timeSeries($cacheItem);

        $now = time();

        // TODO: set the resolution of the time point.
        // If we are monitoring longer periods, we don't want to store
        // too much data in the cache, so this will group them into bigger
        // time chunks.

        // Add or update an entry for this request or requests.

        $timeSeries[$now] = ($timeSeries[$now] ?? 0) + $requestCount;

        // Remove any older entries.
        // The expiredTime is how far back we look when keeping entries.
        // For speed, only do this if the time key of the first element
        // has expired.

        $expiredTime = $now - $this->windowSeconds;

        if (array_key_first($timeSeries) < $expiredTime) {
            $timeSeries = array_filter($timeSeries, function ($key) use ($expiredTime) {
                return $key >= $expiredTime;
            }, ARRAY_FILTER_USE_KEY);
        }

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
    public function getAllocationUsed(CacheItemInterface $cacheItem): int
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
     *
     * @inherit
     */
    public function getWaitSeconds(CacheItemInterface $cacheItem, int $requestCount = 1): int
    {
        $timeSeries = $this->timeSeries($cacheItem);

        $now = time();

        $outsideWindow = $now - $this->windowSeconds;

        $lastBlockingRequestTime = null;

        $requestSum = 0;

        foreach ($timeSeries as $time => $count) {
            if ($time < $outsideWindow) {
                // Out of scope (too old), skip.

                continue;
            }

            $requestSum += $count;

            if ($requestSum >= ($this->windowSeconds - $requestCount)) {
                $lastBlockingRequestTime = $time;
                break;
            }
        }

        if ($lastBlockingRequestTime !== null) {
            return $lastBlockingRequestTime + $this->windowSeconds - $now;
        } else {
            return 0;
        }
    }

    /**
     * Returns the data series array in the cache item.
     *
     * Will throw an exception if the cache item contains data other
     * than an array. If that happens, when we are probbaly trampling
     * over cache items beloning to other processes, so it's a good
     * thing, but probably needs to be formalised a little more.
     *
     * @param CacheItemInterface $cacheItem the PSR-6 cache item containing the time series
     *
     * @return array the cached time series or a new empty array
     */
    protected function timeSeries(CacheItemInterface $cacheItem): array
    {
        return $timeSeries = $cacheItem->get() ?? [];
    }
}
