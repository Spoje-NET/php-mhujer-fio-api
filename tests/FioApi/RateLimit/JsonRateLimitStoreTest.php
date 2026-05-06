<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

use PHPUnit\Framework\TestCase;

class JsonRateLimitStoreTest extends TestCase
{
    public function testGetReturnsNullForUnknownKey(): void
    {
        $store = new JsonRateLimitStore($this->getTempFile());

        $this->assertNull($store->get('unknown-token', 'request'));
    }

    public function testSetAndGet(): void
    {
        $file = $this->getTempFile();
        $store = new JsonRateLimitStore($file);

        $now = time();
        $store->set('my-token', 'request', $now);

        $entry = $store->get('my-token', 'request');
        $this->assertNotNull($entry);
        $this->assertSame($now, $entry['timestamp']);
    }

    public function testPersistsAcrossInstances(): void
    {
        $file = $this->getTempFile();
        $now = time();

        $store1 = new JsonRateLimitStore($file);
        $store1->set('my-token', 'request', $now);

        $store2 = new JsonRateLimitStore($file);
        $entry = $store2->get('my-token', 'request');

        $this->assertNotNull($entry);
        $this->assertSame($now, $entry['timestamp']);
    }

    public function testAllForClientReturnsAllWindows(): void
    {
        $file = $this->getTempFile();
        $store = new JsonRateLimitStore($file);

        $store->set('my-token', 'request', 1000);

        $all = $store->allForClient('my-token');
        $this->assertArrayHasKey('request', $all);
        $this->assertSame(1000, $all['request']['timestamp']);
    }

    public function testAllForClientReturnsEmptyForUnknown(): void
    {
        $store = new JsonRateLimitStore($this->getTempFile());
        $this->assertSame([], $store->allForClient('unknown'));
    }

    public function testMultipleTokensStored(): void
    {
        $file = $this->getTempFile();
        $store = new JsonRateLimitStore($file);

        $store->set('token-a', 'request', 100);
        $store->set('token-b', 'request', 200);

        $this->assertSame(100, $store->get('token-a', 'request')['timestamp']);
        $this->assertSame(200, $store->get('token-b', 'request')['timestamp']);
    }

    public function testOverwritesExistingEntry(): void
    {
        $file = $this->getTempFile();
        $store = new JsonRateLimitStore($file);

        $store->set('token-a', 'request', 100);
        $store->set('token-a', 'request', 999);

        $this->assertSame(999, $store->get('token-a', 'request')['timestamp']);
    }

    private function getTempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fio_store_test_');
        register_shutdown_function(static function () use ($file) {
            if (file_exists($file)) {
                unlink($file);
            }
        });
        return $file;
    }
}
