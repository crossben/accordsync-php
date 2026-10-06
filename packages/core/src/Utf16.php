<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * String order and length as JavaScript sees them: by UTF-16 code unit.
 *
 * PHP compares UTF-8 bytes, which orders characters above U+FFFF after U+E000–U+FFFF, while
 * JavaScript orders them before (their high surrogate, U+D800–U+DBFF, is smaller). Strings are WTF-8
 * (ADR-P04), so a lone surrogate is the 3-byte sequence ED A0..BF xx and is compared as that unit.
 */
final class Utf16
{
    /** -1, 0 or 1, like JavaScript's `<` on strings. */
    public static function compare(string $a, string $b): int
    {
        // Below byte 0xED, UTF-8 byte order and UTF-16 code unit order agree.
        if (strpbrk($a, self::HIGH) === false && strpbrk($b, self::HIGH) === false) {
            return $a <=> $b;
        }

        return self::units($a) <=> self::units($b);
    }

    /** The number of UTF-16 code units (JavaScript's `length`). */
    public static function length(string $s): int
    {
        return \strlen(self::units($s)) >> 1;
    }

    /** The string as UTF-16BE bytes: comparing these bytewise compares code units. */
    public static function units(string $s): string
    {
        if (preg_match('//u', $s) === 1) {
            return mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
        }
        // WTF-8: decode by hand so lone surrogates become one code unit each.
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n;) {
            $c = \ord($s[$i]);
            if ($c < 0x80) {
                $cp = $c;
                $i += 1;
            } elseif ($c < 0xE0) {
                $cp = (($c & 0x1F) << 6) | (\ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($c < 0xF0) {
                $cp = (($c & 0x0F) << 12) | ((\ord($s[$i + 1]) & 0x3F) << 6) | (\ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $cp = (($c & 0x07) << 18) | ((\ord($s[$i + 1]) & 0x3F) << 12)
                    | ((\ord($s[$i + 2]) & 0x3F) << 6) | (\ord($s[$i + 3]) & 0x3F);
                $i += 4;
            }
            if ($cp >= 0x10000) {
                $cp -= 0x10000;
                $out .= pack('nn', 0xD800 | ($cp >> 10), 0xDC00 | ($cp & 0x3FF));
            } else {
                $out .= pack('n', $cp);
            }
        }

        return $out;
    }

    /** Lead bytes from which UTF-8 and UTF-16 orders can disagree (surrogates, U+E000+, 4-byte). */
    private const string HIGH = "\xED\xEE\xEF\xF0\xF1\xF2\xF3\xF4";
}
