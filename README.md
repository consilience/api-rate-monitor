# PSR-18 Client Request Rate Monitor

Written initially for a Xero client, this package is a PSR-18 decorator
that monitors and counts API requests, and provides the data needed to
implement throttling.

This package does not perform the throttling itself, but provides details
of how close to a rate limit the requests are, so action can be taken.
Action may include sleeping a process. It may involve redispatching a
process at a later time.

Being a PSR-18 decorator, multiple rules can be layered, so it is possible
for example to monitor both minute-based rate limits and hour-based rate
limits at the same time.

A PSR-6 cache pool is used to cache any time series used to record the requests.
The cache key is provided by the application.
At the moment a client decorator instance handles just one immutable cache key.
A future release may allow the key to be set dynamically by metadata in the PSR-7
request, and that can be provided by the application from any source.

Written for the Xero API, rate limits are set for each organisation an API
makes requests against. So in this case it makes sense to use the organisation
ID as the cache key.
Any further profixes needed to separate these cache keys from other cach items
is the responsibility of the application.

## Rolling Window Monitor Strategy

Only the rolling window rate limit strategy is implemented for now,
but other strategies can be plugged in and pull requests are welcome.

This strategy keeps track of requests at each second within a rolling time window.
Xero allows 60 requests to be sent in any 60 second rolling window.
If 60 requests have been made in the last 60 seconds, then another request will
result in a rate limit rejection.

Let's jump into some code to see how we can protect the application from this.

First we need a cache to keep track of requests, and we use a
[PSR-6](https://www.php-fig.org/psr/psr-6/) cache.
If using Laravel, the
[madewithlove/illuminate-psr-cache-bridge](https://github.com/madewithlove/illuminate-psr-cache-bridge)
package bridges the Laravel cache to a `PSR-6` interface nicely.

So we get the cache pool:

```php
$cachePool = new MyFavouriteCachePool();

// or inject it into your class if using laravel:

public function __construct(CacheItemPoolInterface $cachePool) {
   ...
}

```

Now we need the `PSR-18` client.
The client can be instantiated however you like.
I use [this Xero API client](https://github.com/consilience/xero-api-client)
to handle authentication against Xero, but it makes no difference which `PSR-18`
client you use.

We will set up the client as `$httpClient`.

Now we use the decorator:

```php
use Consilience\Api\RateMonitor\HttpClient;
use Consilience\Api\RateMonitor\MonitorStrategy\RollingWindow;

// $xeroOrganisationId is the Xero organisation we are connecting to.
// 60 = size of rolling window, in seconds.
// 60 = number of requests that can be made in that window.
// A bit of a safety margin could see the number of requests
// set to a lower figure, 55 for example.

$httpClient = new HttpClient(
   $httpClient,
   $cachePool,
   new RollingWindow(60, 60)
)->withKey($xeroOrganisationId);
```

The decorated `$httpClient` can be used as before, but has some additional
methods above the `Psr\Http\Client\ClientInterface` interface:

* `getAllocationUsed()` - returns how many requests have been sent in the
   current rolling window.
* `getWaitSeconds()` - tells us how many seconds we much wait before sending
   more requests.

For `getWaitSeconds()` you can tell it how many request you would like to burst,
and it will return the wait time needed to burst that number of requests on one go.

So one strategy for using this may be sleeping before sending the next request:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

function sendRequest(
    ClientInterface $httpClient,
    RequestInterface $request
): ResponseInterface
{
    if ($waitSeconds = $httpClient->getWaitSeconds()) {
        sleep($waitSeconds);
    }

    return $httpClient->send($request);
}
```

That is a naive approach, and assumes a process can simply sleep
for up to 60 seconds without database connections dropping etc.
but it is just a simple example.

Another strategy could be to spread the requests more evenly, with a minimum
time between each, and keep adjusting the sleep delay to hover around a half
allocated rolling window. That would keep the sleeps to a minimum length,
but allow quick short bursts for processes that only need a few dozen requests
at most.

### How the rolling window logging works

In short, each key points to an array in cache.
The array contains a count of requests made for each second that in which
requests were made.
So indexed by a timestamp, we can see when all the requests in the last
rolling window were made.

At any time, the counts of requests for the last rolling window period
can be summed to get the number of reqeusts made in the current rolling
window. That tells us how may requests can be made *now* before the API
rate limiting kicks in.

Given that, if we want to make say ten requests right now, we can check if
there are enough free slots on the current rolling window to do so.
If there are, then we are fine and can just go ahead and make those requests.

Now, if there are not enough slots left - the rolling windwo may allow 60 requests
per minute, and in the last minute we have made 55 reqests, so we need to
find out when five slots will be freed up before we can burst those ten requets
to the API.

We do that by counting the reqeusts from the start of the current rolling
window, in this case 60 seconds ago. When we count the number of slots we
need to free up, we can see what time that represents.
Supposing those oldest five slots were taken up 30 seconds ago,
then it means those slots will not be fully release until 60 seconds later,
which will be 30 seconds into the future.
So, the process needs to wait 30 seconds before it can send those ten requests
*in a burst*.

If the process only wanted to send one request, then it is likely it would need
to wait a much shorter time. However, that really does depend on the past
pattern of requests, i.e. how they were spread out or bunched up.

# TODO

* Tests.
* Support dynamic key detection. A single client with no key set can then
  support requests against many different keys on a request-by-request basis.
  Otherwise we are creating a new decorator class for each key.
  That may also be fine, depending on how the application organises its requests.
* Support throttling strategy plugins. Allow this package to do the throttling
  as defined by rules in a class.
* Handle locking of cache items when they are being updated.
