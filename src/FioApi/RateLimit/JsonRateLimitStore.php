<?php

declare(strict_types=1);

namespace FioApi\RateLimit;

class JsonRateLimitStore implements RateLimitStoreInterface
{
    private string $filename;

    /** @var array<string, array<string, array{timestamp: int}>> */
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
        $this->data[$tokenId][$window] = [
            'timestamp' => $timestamp,
        ];

        $this->save();
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
            $json = stream_get_contents($handle);

            if ($json === false || $json === '') {
                $this->data = [];
            } else {
                $decoded = json_decode($json, true);

                if ($decoded === null && json_last_error() !== \JSON_ERROR_NONE) {
                    $this->data = [];
                } else {
                    $this->data = $decoded ?? [];
                }
            }

            flock($handle, \LOCK_UN);
        }

        if ($handle) {
            fclose($handle);
        }
    }

    private function save(): void
    {
        $dir = dirname($this->filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $handle = fopen($this->filename, 'cb');

        if ($handle && flock($handle, \LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($this->data, \JSON_PRETTY_PRINT));
            fflush($handle);
            flock($handle, \LOCK_UN);
        }

        if ($handle) {
            fclose($handle);
        }
    }
}
