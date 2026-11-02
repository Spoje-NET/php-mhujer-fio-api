<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

class JsonRateLimitStore implements AtomicRateLimitStoreInterface
{
    private string $filename;

    /** @var array<string, array<string, array{timestamp: int, microtime?: float}>> */
    private array $data = [];

    public function __construct(string $filename)
    {
        $this->filename = $filename;

        if (file_exists($filename)) {
            $this->load();
        }
    }

    public function get(string $tokenId, string $window): ?array
    {
        $this->load();
        return $this->data[$tokenId][$window] ?? null;
    }

    public function set(string $tokenId, string $window, int $timestamp): void
    {
        $this->modify(static function (array &$data) use ($tokenId, $window, $timestamp): void {
            $data[$tokenId][$window] = ['timestamp' => $timestamp, 'microtime' => (float) $timestamp];
        });
    }

    public function tryAcquire(string $tokenId, string $window, float $windowSeconds): float
    {
        $wait = 0.0;

        $this->modify(static function (array &$data) use ($tokenId, $window, $windowSeconds, &$wait): void {
            $now = microtime(true);
            $entry = $data[$tokenId][$window] ?? null;
            $last = $entry === null ? null : (float) ($entry['microtime'] ?? $entry['timestamp']);

            if ($last !== null && $now - $last < $windowSeconds) {
                $wait = $windowSeconds - ($now - $last);

                return;
            }

            $data[$tokenId][$window] = ['timestamp' => (int) $now, 'microtime' => $now];
        });

        return $wait;
    }

    public function allForClient(string $tokenId): array
    {
        return $this->data[$tokenId] ?? [];
    }

    private function load(): void
    {
        if (!file_exists($this->filename)) {
            return;
        }

        $handle = fopen($this->filename, 'c+b');

        if ($handle && flock($handle, \LOCK_SH)) {
            $this->data = $this->decode((string) stream_get_contents($handle));
            flock($handle, \LOCK_UN);
        }

        if ($handle) {
            fclose($handle);
        }
    }

    /**
     * Read-modify-write of the whole file under one exclusive lock, always on
     * the current file content (never on a stale in-memory copy).
     *
     * @param callable(array): void $change receives the data by reference
     *
     * @throws \RuntimeException when the file cannot be opened, locked or written
     */
    private function modify(callable $change): void
    {
        $dir = dirname($this->filename);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create rate limit directory %s', $dir));
        }

        $handle = fopen($this->filename, 'c+b');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open rate limit store %s', $this->filename));
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException(sprintf('Cannot lock rate limit store %s', $this->filename));
            }

            $data = $this->decode((string) stream_get_contents($handle));
            $change($data);

            $json = json_encode($data, \JSON_PRETTY_PRINT);
            if ($json === false || !ftruncate($handle, 0) || rewind($handle) === false
                || fwrite($handle, $json) === false || !fflush($handle)) {
                throw new \RuntimeException(sprintf('Cannot write rate limit store %s', $this->filename));
            }

            $this->data = $data;
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<string, array{timestamp: int, microtime?: float}>>
     */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
