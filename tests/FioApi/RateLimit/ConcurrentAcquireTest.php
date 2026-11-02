<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

use FioApi\Exceptions\TooGreedyException;
use PHPUnit\Framework\TestCase;

class ConcurrentAcquireTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'fio_rate_conc_');
        unlink($this->file);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->file)) {
            unlink($this->file);
        }
    }

    public function testSecondAcquireInWindowIsRejected(): void
    {
        $limiter = new RateLimiter(new JsonRateLimitStore($this->file), false);

        $limiter->acquire('tok');

        $this->expectException(TooGreedyException::class);
        $limiter->acquire('tok');
    }

    public function testAcquireReservesSlotBeforeTheRequestIsSent(): void
    {
        $store = new JsonRateLimitStore($this->file);
        (new RateLimiter($store, false))->acquire('tok');

        $this->assertGreaterThan(0.0, $store->tryAcquire('tok', 'request', 30.0));
        $this->assertSame(0.0, $store->tryAcquire('other', 'request', 30.0), 'tokens are independent');
    }

    public function testExpiredWindowAllowsAcquire(): void
    {
        $store = new JsonRateLimitStore($this->file);
        $store->set('tok', 'request', time() - 31);

        $this->assertSame(0.0, $store->tryAcquire('tok', 'request', 30.0));
    }

    public function testSubSecondWindowIsRespected(): void
    {
        $store = new JsonRateLimitStore($this->file);

        $this->assertSame(0.0, $store->tryAcquire('tok', 'request', 0.3));
        $wait = $store->tryAcquire('tok', 'request', 0.3);
        $this->assertGreaterThan(0.0, $wait);
        $this->assertLessThanOrEqual(0.3, $wait);

        usleep(350000);
        $this->assertSame(0.0, $store->tryAcquire('tok', 'request', 0.3));
    }

    public function testWaitModeWaitsForWindow(): void
    {
        $store = new JsonRateLimitStore($this->file);
        $limiter = new RateLimiter($store, true);
        $store->tryAcquire('tok', 'request', 30.0);
        // Pretend the previous request happened 29.6 s ago.
        $data = json_decode((string) file_get_contents($this->file), true);
        $data['tok']['request']['microtime'] = microtime(true) - 29.6;
        file_put_contents($this->file, json_encode($data));

        $start = microtime(true);
        $limiter->acquire('tok');
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(0.3, $elapsed);
        $this->assertLessThan(1.5, $elapsed);
    }

    public function testConcurrentProcessesGetExactlyOneSlot(): void
    {
        $root = dirname(__DIR__, 3).'/src/FioApi';
        $script = tempnam(sys_get_temp_dir(), 'fio_rate_proc_');
        file_put_contents($script, '<?php
            require "'.$root.'/Exceptions/TooGreedyException.php";
            require "'.$root.'/RateLimit/RateLimitStoreInterface.php";
            require "'.$root.'/RateLimit/AtomicRateLimitStoreInterface.php";
            require "'.$root.'/RateLimit/JsonRateLimitStore.php";
            require "'.$root.'/RateLimit/RateLimiter.php";
            while (microtime(true) < (float) $argv[2]) { usleep(200); }
            try {
                (new FioApi\RateLimit\RateLimiter(new FioApi\RateLimit\JsonRateLimitStore($argv[1]), false))->acquire("tok");
                echo "PASS";
            } catch (FioApi\Exceptions\TooGreedyException $e) {
                echo "BLOCKED";
            }
        ');

        $startAt = microtime(true) + 0.6;
        $procs = [];
        $streams = [];
        for ($i = 0; $i < 8; ++$i) {
            $pipes = [];
            $procs[$i] = proc_open(
                [PHP_BINARY, $script, $this->file, (string) $startAt],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $streams[$i] = $pipes[1];
        }

        $results = [];
        foreach ($streams as $i => $stream) {
            $results[] = stream_get_contents($stream);
            fclose($stream);
            proc_close($procs[$i]);
        }
        unlink($script);

        $counts = array_count_values($results);
        $this->assertSame(1, $counts['PASS'] ?? 0, 'exactly one process may pass: '.json_encode($results));
        $this->assertSame(7, $counts['BLOCKED'] ?? 0, json_encode($results));
    }

    public function testSetDoesNotOverwriteOtherTokensWrittenByAnotherInstance(): void
    {
        $a = new JsonRateLimitStore($this->file);
        $b = new JsonRateLimitStore($this->file);

        $a->get('A', 'request');
        $b->set('B', 'request', time());
        $a->set('A', 'request', time());

        $fresh = new JsonRateLimitStore($this->file);
        $this->assertNotNull($fresh->get('A', 'request'));
        $this->assertNotNull($fresh->get('B', 'request'), 'stale copy must not erase token B');
    }

    public function testUnwritableStoreThrowsInsteadOfSilentlyDisablingTheLimit(): void
    {
        $this->expectException(\RuntimeException::class);
        (new JsonRateLimitStore('/proc/nonexistent/dir/store.json'))->tryAcquire('tok', 'request', 30.0);
    }
}
