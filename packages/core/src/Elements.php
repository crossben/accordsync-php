<?php

declare(strict_types=1);

namespace Accord\Core;

/** Set elements: strings or finite numbers, compared like JavaScript's `===`. */
final class Elements
{
    /** `1` and `1.0` are the same element (JavaScript has one number type); `"1"` and `1` are not. */
    public static function same(mixed $a, mixed $b): bool
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return (float) $a === (float) $b;
        }

        return \is_string($a) && $a === $b;
    }

    /** Numbers first (ascending), then strings (by UTF-16 code unit), as in the TypeScript core. */
    public static function compare(string|int|float $a, string|int|float $b): int
    {
        $an = !\is_string($a);
        $bn = !\is_string($b);
        if ($an !== $bn) {
            return $an ? -1 : 1;
        }
        if (\is_string($a) && \is_string($b)) {
            return Utf16::compare($a, $b);
        }

        return (float) $a <=> (float) $b;
    }

    /** @phpstan-assert-if-true string|int|float $e */
    public static function isElement(mixed $e): bool
    {
        return \is_string($e) || \is_int($e) || (\is_float($e) && is_finite($e));
    }
}
