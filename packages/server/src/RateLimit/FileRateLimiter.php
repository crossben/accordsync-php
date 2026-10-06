<?php

declare(strict_types=1);

namespace Accord\Server\RateLimit;

use Accord\Server\RateLimit;

/**
 * Buckets in one JSON file under an exclusive `flock`, shared by every PHP worker on this machine
 * (PHP-FPM, `php -S` with PHP_CLI_SERVER_WORKERS). Fine for one host and modest traffic; several
 * hosts need a shared cache (the framework bridges).
 */
final class FileRateLimiter implements RateLimiter
{
    public function __construct(private readonly string $path, private readonly int $maxKeys = 100_000) {}

    public function take(string $key, RateLimit $limit, int $nowMs): int
    {
        $fh = fopen($this->path, 'c+');
        if ($fh === false) {
            throw new \RuntimeException("cannot open {$this->path}");
        }
        try {
            flock($fh, LOCK_EX);
            $text = stream_get_contents($fh);
            $all = \is_string($text) && $text !== '' ? json_decode($text, true) : [];
            if (!\is_array($all)) {
                $all = [];
            }
            /** @var array<string, array{0: float, 1: int}> $all */
            if (!isset($all[$key]) && \count($all) >= $this->maxKeys) {
                // Without each key's limit at hand, drop buckets idle for over an hour (all full by then
                // for any limit of at least one request per hour).
                $all = array_filter($all, static fn(array $b): bool => $nowMs - $b[1] < 3_600_000);
            }
            $old = $all[$key] ?? null;
            [$wait, $b] = TokenBucket::take($old === null ? null : ['tokens' => (float) $old[0], 'at' => (int) $old[1]], $limit, $nowMs);
            $all[$key] = [$b['tokens'], $b['at']];
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($all, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            fflush($fh);

            return $wait;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function clear(): void
    {
        $fh = fopen($this->path, 'c+');
        if ($fh === false) {
            return;
        }
        flock($fh, LOCK_EX);
        ftruncate($fh, 0);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
