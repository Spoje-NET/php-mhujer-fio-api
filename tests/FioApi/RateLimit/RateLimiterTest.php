<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

use FioApi\Exceptions\TooGreedyException;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    public function testFirstRequestIsNotBlocked(): void
    {
        $store = new JsonRateLimitStore($this->getTempFile());
        $limiter = new RateLimiter($store, false);

        // Should not throw
        $limiter->checkBeforeRequest('test-token');
        $this->assertTrue(true);
    }

    public function testThrowsWhenRateLimitExceededInNonWaitMode(): void
    {
        $store = new JsonRateLimitStore($this->getTempFile());
        $limiter = new RateLimiter($store, false);

        $limiter->recordRequest('test-token');

        $this->expectException(TooGreedyException::class);
        $limiter->checkBeforeRequest('test-token');
    }

    public function testAllowsRequestAfterWindowExpires(): void
    {
        $file = $this->getTempFile();
        $store = new JsonRateLimitStore($file);
        $limiter = new RateLimiter($store, false);

        // Manually write an old timestamp
        $store->set('test-token', 'request', time() - 31);

        // Should not throw since 31 seconds > 30 window
        $limiter->checkBeforeRequest('test-token');
        $this->assertTrue(true);
    }

    public function testRecordRequestStoresTimestamp(): void
    {
        $file = $this->getTempFile();
        $store = new JsonRateLimitStore($file);
        $limiter = new RateLimiter($store);

        $limiter->recordRequest('test-token');

        $entry = $store->get('test-token', 'request');
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertEqualsWithDelta(time(), $entry['timestamp'], 2);
    }

    public function testIsWaitModeReturnsConstructorValue(): void
    {
        $store = new JsonRateLimitStore($this->getTempFile());

        $limiter = new RateLimiter($store, true);
        $this->assertTrue($limiter->isWaitMode());

        $limiter2 = new RateLimiter($store, false);
        $this->assertFalse($limiter2->isWaitMode());
    }

    public function testDifferentTokensAreIndependent(): void
    {
        $store = new JsonRateLimitStore($this->getTempFile());
        $limiter = new RateLimiter($store, false);

        $limiter->recordRequest('token-a');

        // token-b should not be blocked
        $limiter->checkBeforeRequest('token-b');
        $this->assertTrue(true);
    }

    private function getTempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fio_rate_test_');
        // Ensure the file is cleaned up
        register_shutdown_function(static function () use ($file) {
            if (file_exists($file)) {
                unlink($file);
            }
        });
        return $file;
    }
}
