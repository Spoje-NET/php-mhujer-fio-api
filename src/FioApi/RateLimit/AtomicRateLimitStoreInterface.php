<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

/**
 * A store able to check the window and reserve the slot in one atomic step.
 *
 * Separate check-then-record is racy: concurrent processes all see an expired
 * window, all pass and all hit the API within the same 30 s.
 */
interface AtomicRateLimitStoreInterface extends RateLimitStoreInterface
{
    /**
     * Reserves the slot for the token when the window since the last
     * reservation has elapsed.
     *
     * @return float 0.0 when the slot was reserved (the caller may send the
     *               request now), otherwise the seconds to wait; nothing is
     *               reserved in that case
     */
    public function tryAcquire(string $tokenId, string $window, float $windowSeconds): float;
}
