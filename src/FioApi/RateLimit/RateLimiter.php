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
