<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * JSON in and out, the way JavaScript reads and writes it (ADR-P04).
 *
 * `decode` parses into the core's value model (see {@see JsonObject}): objects stay objects even
 * when empty, keys stay strings, and strings are WTF-8 so a lone surrogate escape like `\ud800`
 * survives. `json_decode` cannot do any of the three.
 *
 * `canonical` is byte-for-byte the TypeScript `canonicalJson`: `JSON.stringify` of an object built
 * from key-sorted entries. That means reproducing JavaScript, not just sorting keys:
 * - keys that are array indices ("0", "9", "10"…) come first, in numeric order, because JavaScript
 *   objects always order them that way; the other keys follow by UTF-16 code unit;
 * - numbers print as JavaScript prints them: `1` not `1.0`, `0` for `-0.0`, `1e+21`, `1e-7`;
 * - strings escape exactly like `JSON.stringify`.
 */
final class Json
{
    /** The largest integer JavaScript represents exactly. */
    public const int MAX_SAFE_INTEGER = 9007199254740991;

    private const int MAX_ARRAY_INDEX = 4294967294;

    public static function decode(string $text): mixed
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new AccordException('JSON text is not valid UTF-8');
        }
        $p = new JsonParser($text);

        return $p->document();
    }

    public static function canonical(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            \is_int($value) => self::number($value),
            \is_float($value) => self::number($value),
            \is_string($value) => self::string($value),
            $value instanceof JsonObject => self::object($value),
            \is_array($value) && array_is_list($value) => self::list($value),
            default => throw new AccordException('canonicalJson: not a JSON value: ' . get_debug_type($value)),
        };
    }

    /** A number as `JSON.stringify` prints it. */
    public static function number(int|float $n): string
    {
        if (\is_int($n)) {
            // Beyond 2^53 JavaScript would already hold a rounded double.
            return abs($n) <= self::MAX_SAFE_INTEGER ? (string) $n : self::number((float) $n);
        }
        if (!is_finite($n)) {
            return 'null';
        }
        if ($n === 0.0) {
            return '0'; // also -0.0
        }
        $sign = $n < 0 ? '-' : '';
        $a = abs($n);
        // Shortest digits that read back as the same double (sprintf rounds correctly, so the first
        // precision that round-trips gives JavaScript's digits).
        $s = '';
        for ($p = 0; $p <= 16; $p++) {
            $s = \sprintf('%.' . $p . 'e', $a);
            if ((float) $s === $a) {
                break;
            }
        }
        [$mantissa, $exp] = explode('e', $s);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $k = \strlen($digits);
        $n10 = (int) $exp + 1; // value = 0.digits × 10^n10
        // Number::toString, ECMA-262 §6.1.6.1.20.
        if ($k <= $n10 && $n10 <= 21) {
            return $sign . $digits . str_repeat('0', $n10 - $k);
        }
        if (0 < $n10 && $n10 <= 21) {
            return $sign . substr($digits, 0, $n10) . '.' . substr($digits, $n10);
        }
        if (-6 < $n10 && $n10 <= 0) {
            return $sign . '0.' . str_repeat('0', -$n10) . $digits;
        }
        $e = $n10 - 1;
        $m = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);

        return $sign . $m . 'e' . ($e < 0 ? '-' : '+') . abs($e);
    }

    /** A string as `JSON.stringify` writes it. Lone surrogates (WTF-8) become `\udXXX`. */
    public static function string(string $s): string
    {
        if (preg_match('/[\x00-\x1f"\\\\]|\xED[\xA0-\xBF]/', $s) !== 1) {
            return '"' . $s . '"';
        }
        $out = preg_replace_callback(
            '/[\x00-\x1f"\\\\]|\xED[\xA0-\xBF][\x80-\xBF]/',
            static function (array $m): string {
                $c = $m[0];
                if (\strlen($c) === 3) {
                    $unit = ((\ord($c[0]) & 0x0F) << 12) | ((\ord($c[1]) & 0x3F) << 6) | (\ord($c[2]) & 0x3F);

                    return \sprintf('\u%04x', $unit);
                }

                return match ($c) {
                    '"' => '\"',
                    '\\' => '\\\\',
                    "\x08" => '\b',
                    "\f" => '\f',
                    "\n" => '\n',
                    "\r" => '\r',
                    "\t" => '\t',
                    default => \sprintf('\u%04x', \ord($c)),
                };
            },
            $s,
        );

        return '"' . $out . '"';
    }

    /**
     * The order JavaScript gives the keys of an object built from code-unit-sorted entries.
     *
     * @param iterable<string> $keys
     * @return list<string>
     */
    public static function keyOrder(iterable $keys): array
    {
        $indices = [];
        $others = [];
        foreach ($keys as $k) {
            if (self::isArrayIndex($k)) {
                $indices[] = $k;
            } else {
                $others[] = $k;
            }
        }
        usort($indices, static fn(string $a, string $b): int => (int) $a <=> (int) $b);
        usort($others, Utf16::compare(...));

        return [...$indices, ...$others];
    }

    private static function isArrayIndex(string $k): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $k) === 1 && (int) $k <= self::MAX_ARRAY_INDEX;
    }

    private static function object(JsonObject $o): string
    {
        $parts = [];
        foreach (self::keyOrder($o->keys()) as $k) {
            $parts[] = self::string($k) . ':' . self::canonical($o->get($k));
        }

        return '{' . implode(',', $parts) . '}';
    }

    /** @param list<mixed> $xs */
    private static function list(array $xs): string
    {
        return '[' . implode(',', array_map(self::canonical(...), $xs)) . ']';
    }
}
