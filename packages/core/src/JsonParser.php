<?php

declare(strict_types=1);

namespace Accord\Core;

/** A strict RFC 8259 parser into the core's JSON value model. Internal to {@see Json::decode}. */
final class JsonParser
{
    private int $pos = 0;

    public function __construct(private readonly string $text) {}

    public function document(): mixed
    {
        $v = $this->value();
        $this->ws();
        if ($this->pos !== \strlen($this->text)) {
            $this->fail('trailing characters');
        }

        return $v;
    }

    private function value(): mixed
    {
        $this->ws();
        $c = $this->text[$this->pos] ?? '';

        return match (true) {
            $c === '{' => $this->object(),
            $c === '[' => $this->list(),
            $c === '"' => $this->string(),
            $c === '-' || ctype_digit($c) => $this->number(),
            default => $this->literal(),
        };
    }

    private function object(): JsonObject
    {
        $o = new JsonObject();
        $this->pos++;
        $this->ws();
        if (($this->text[$this->pos] ?? '') === '}') {
            $this->pos++;

            return $o;
        }
        while (true) {
            $this->ws();
            if (($this->text[$this->pos] ?? '') !== '"') {
                $this->fail('expected a key');
            }
            $key = $this->string();
            $this->ws();
            $this->expect(':');
            $o->set($key, $this->value());
            $this->ws();
            $c = $this->text[$this->pos] ?? '';
            $this->pos++;
            if ($c === '}') {
                return $o;
            }
            if ($c !== ',') {
                $this->fail('expected , or }');
            }
        }
    }

    /** @return list<mixed> */
    private function list(): array
    {
        $xs = [];
        $this->pos++;
        $this->ws();
        if (($this->text[$this->pos] ?? '') === ']') {
            $this->pos++;

            return $xs;
        }
        while (true) {
            $xs[] = $this->value();
            $this->ws();
            $c = $this->text[$this->pos] ?? '';
            $this->pos++;
            if ($c === ']') {
                return $xs;
            }
            if ($c !== ',') {
                $this->fail('expected , or ]');
            }
        }
    }

    private function string(): string
    {
        $this->pos++; // opening quote
        $out = '';
        while (true) {
            // Raw run up to the next quote, backslash or control character.
            if (preg_match('/[^"\\\\\x00-\x1f]*/A', $this->text, $m, 0, $this->pos) === 1) {
                $out .= $m[0];
                $this->pos += \strlen($m[0]);
            }
            $c = $this->text[$this->pos] ?? '';
            if ($c === '"') {
                $this->pos++;

                return $out;
            }
            if ($c !== '\\') {
                $this->fail('unterminated string or raw control character');
            }
            $e = $this->text[$this->pos + 1] ?? '';
            $this->pos += 2;
            $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t"];
            if (isset($simple[$e])) {
                $out .= $simple[$e];
                continue;
            }
            if ($e !== 'u') {
                $this->fail('bad escape');
            }
            $unit = $this->hex4();
            if ($unit >= 0xD800 && $unit <= 0xDBFF && substr($this->text, $this->pos, 2) === '\u') {
                $save = $this->pos;
                $this->pos += 2;
                $low = $this->hex4();
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $out .= self::utf8(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00));
                    continue;
                }
                $this->pos = $save; // a lone high surrogate followed by another escape
            }
            $out .= self::utf8($unit); // lone surrogates come out as WTF-8
        }
    }

    private function hex4(): int
    {
        $h = substr($this->text, $this->pos, 4);
        if (\strlen($h) !== 4 || !ctype_xdigit($h)) {
            $this->fail('bad \u escape');
        }
        $this->pos += 4;

        return (int) hexdec($h);
    }

    /** UTF-8 for a code point, or the WTF-8 bytes of a surrogate. */
    private static function utf8(int $cp): string
    {
        return match (true) {
            $cp < 0x80 => \chr($cp),
            $cp < 0x800 => \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F)),
            $cp < 0x10000 => \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F)),
            default => \chr(0xF0 | ($cp >> 18)) . \chr(0x80 | (($cp >> 12) & 0x3F))
                . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F)),
        };
    }

    private function number(): int|float
    {
        if (preg_match('/-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/A', $this->text, $m, 0, $this->pos) !== 1) {
            $this->fail('bad number');
        }
        $this->pos += \strlen($m[0]);
        // JavaScript has one number type; whole numbers within 2^53 are kept as int (exact either way).
        if (($m[1] ?? '') === '' && ($m[2] ?? '') === '' && \strlen($m[0]) < 20) {
            $i = (int) $m[0];
            if (abs($i) <= Json::MAX_SAFE_INTEGER) {
                return $i;
            }
        }

        return (float) $m[0];
    }

    private function literal(): mixed
    {
        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $v) {
            if (substr($this->text, $this->pos, \strlen($word)) === $word) {
                $this->pos += \strlen($word);

                return $v;
            }
        }
        $this->fail('unexpected character');
    }

    private function ws(): void
    {
        $this->pos += strspn($this->text, " \t\n\r", $this->pos);
    }

    private function expect(string $c): void
    {
        if (($this->text[$this->pos] ?? '') !== $c) {
            $this->fail("expected $c");
        }
        $this->pos++;
    }

    private function fail(string $why): never
    {
        throw new AccordException("invalid JSON at byte {$this->pos}: $why");
    }
}
