<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

use FioApi\Exceptions\TooGreedyException;

class RateLimiter
{
    private const WINDOW_SECONDS = 30;

    private RateLimitStoreInterface $store;
    private bool $waitMode;

    public function __construct(RateLimitStoreInterface $store, bool $waitMode = true)
    {
        $this->store = $store;
        $this->waitMode = $waitMode;
    }

    public function isWaitMode(): bool
    {
        return $this->waitMode;
    }

    /**
     * Waits for (or, without wait mode, fails on) the rate limit window and
     * reserves the slot in one atomic step, so concurrent processes sharing
     * the store never send a request within the same window.
     *
     * Stores that are not atomic fall back to check followed by record.
     *
     * @throws TooGreedyException if the rate limit is exceeded and wait mode is disabled
     */
    public function acquire(string $tokenId): void
    {
        if (!$this->store instanceof AtomicRateLimitStoreInterface) {
            $this->checkBeforeRequest($tokenId);
            $this->recordRequest($tokenId);

            return;
        }

        while (($wait = $this->store->tryAcquire($tokenId, 'request', (float) self::WINDOW_SECONDS)) > 0.0) {
            if (!$this->waitMode) {
                throw new TooGreedyException(
                    sprintf(
                        'You can use one token for API call every %d seconds. Wait %d more seconds.',
                        self::WINDOW_SECONDS,
                        (int) ceil($wait)
                    )
                );
            }

            usleep((int) ceil($wait * 1000000));
        }
    }

    /**
     * Ensures the token is allowed to make the next request by enforcing the 30-second rate limit.
     *
     * If the window hasn't elapsed and wait mode is enabled, sleeps for the remaining time;
     * otherwise throws TooGreedyException.
     *
     * @throws TooGreedyException if the rate limit is exceeded and wait mode is disabled
     */
    public function checkBeforeRequest(string $tokenId): void
    {
        $entry = $this->store->get($tokenId, 'request');

        if ($entry === null) {
            return;
        }

        $now = time();
        $elapsed = $now - $entry['timestamp'];

        if ($elapsed < self::WINDOW_SECONDS) {
            $wait = self::WINDOW_SECONDS - $elapsed;

            if ($this->waitMode) {
                sleep($wait);
            } else {
                throw new TooGreedyException(
                    sprintf(
                        'You can use one token for API call every %d seconds. Wait %d more seconds.',
                        self::WINDOW_SECONDS,
                        $wait
                    )
                );
            }
        }
    }

    /**
     * Records the current time as the last request timestamp for the token.
     */
    public function recordRequest(string $tokenId): void
    {
        $this->store->set($tokenId, 'request', time());
    }
}
