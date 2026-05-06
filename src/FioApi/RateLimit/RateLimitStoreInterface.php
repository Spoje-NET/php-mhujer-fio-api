<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

interface RateLimitStoreInterface
{
    /**
     * Returns stored data for the given token and window.
     *
     * @return array{timestamp: int}|null
     */
    public function get(string $tokenId, string $window): ?array;

    /**
     * Stores the timestamp for the token and window.
     */
    public function set(string $tokenId, string $window, int $timestamp): void;

    /**
     * Returns all data for one token (for debugging).
     */
    public function allForClient(string $tokenId): array;
}
