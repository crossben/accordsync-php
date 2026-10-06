<?php

declare(strict_types=1);

namespace Accord\Testing;

/** The shared contract (golden vectors, protocol schemas), committed in `contract/`. */
final class Contract
{
    public static function dir(): string
    {
        return \dirname(__DIR__, 2) . '/contract';
    }

    /** @return list<string> top-level golden vector files, sorted */
    public static function vectorFiles(): array
    {
        $files = glob(self::dir() . '/vectors/*.json') ?: [];
        sort($files);

        return $files;
    }
}
