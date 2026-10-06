<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\AccordException;
use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\Utf16;
use PHPUnit\Framework\TestCase;

/**
 * Canonical JSON must equal what `JSON.stringify` gives in the TypeScript core. The random vectors
 * cover most of it; these are the cases JSON input cannot carry, and the PHP traps (plan §6).
 */
final class JsonTest extends TestCase
{
    /** A JSON `\u` escape prefix, kept apart from its hex digits. */
    private const string U = '\\u';

    public function testNumbersPrintAsJavaScriptPrintsThem(): void
    {
        $cases = [
            ['0', -0.0], ['0', 0.0], ['0', 0], ['1', 1.0], ['-2', -2.0], ['0.1', 0.1], ['1e+21', 1e21],
            ['100000000000000000000', 1e20], ['1e-7', 1e-7], ['0.000001', 1e-6], ['123456789.125', 123456789.125],
            ['5e-324', 5e-324], ['-1.5e+300', -1.5e300], ['1.7976931348623157e+308', 1.7976931348623157e308],
            ['0.30000000000000004', 0.1 + 0.2], ['9007199254740991', 9007199254740991], ['9007199254740992', 2.0 ** 53],
            ['9007199254740992', 9007199254740992], ['1.5', 1.5], ['-0.5', -0.5], ['1.2345e-7', 1.2345e-7],
            ['1.23e+27', 1.23e27],
            ['null', NAN], ['null', INF], ['null', -INF],
        ];
        foreach ($cases as [$expected, $n]) {
            self::assertSame($expected, Json::canonical($n), var_export($n, true));
        }
    }

    public function testKeysPutArrayIndicesFirstInNumericOrderThenCodeUnitOrder(): void
    {
        $o = new JsonObject(['b' => 1, 'a' => 2, '10' => 3, '9' => 4, '01' => 5, '4294967295' => 6, 'B' => 7, '4294967294' => 8, '-1' => 9]);
        self::assertSame('{"9":4,"10":3,"4294967294":8,"-1":9,"01":5,"4294967295":6,"B":7,"a":2,"b":1}', Json::canonical($o));
    }

    public function testIntegerLikeKeysStayStrings(): void
    {
        $o = new JsonObject(['10' => 1]);
        self::assertSame(['10'], $o->keys());
        foreach ($o as $k => $_) {
            self::assertSame('10', $k);
        }
        $parsed = Json::decode('{"10":1,"9":{}}');
        self::assertInstanceOf(JsonObject::class, $parsed);
        self::assertSame(['10', '9'], $parsed->keys());
        // "01" and "1" are different keys; only canonical array indices move to the front.
        self::assertSame('{"1":2,"01":1,"1.0":3}', Json::canonical(Json::decode('{"01":1,"1":2,"1.0":3}')));
    }

    public function testEmptyObjectAndEmptyArrayStayApart(): void
    {
        self::assertSame('{}', Json::canonical(new JsonObject()));
        self::assertSame('[]', Json::canonical([]));
        self::assertSame('[{},[],{"a":[]}]', Json::canonical(Json::decode('[{},[],{"a":[]}]')));
    }

    public function testRefusesPhpArraysThatAreNotLists(): void
    {
        $this->expectException(AccordException::class);
        Json::canonical(['a' => 1]);
    }

    public function testStringsEscapeLikeJsonStringify(): void
    {
        $in = '"' . self::U . '0000\n\"\\\\ ' . self::U . 'd800' . "\u{1F600}" . '"';
        self::assertSame($in, Json::canonical(Json::decode($in)));
        self::assertSame('"\b\f\n\r\t' . self::U . '0001' . self::U . '001f' . "\x7f" . '"', Json::canonical("\x08\f\n\r\t\x01\x1f\x7f"));
        // U+2028/U+2029 and slashes are left raw, non-ASCII too.
        self::assertSame("\"\u{2028}\u{2029}/é\"", Json::canonical("\u{2028}\u{2029}/é"));
        // Lone surrogates, high and low, in lowercase hex; a pair becomes the character.
        self::assertSame('"' . "\u{10FC00}" . 'x' . self::U . 'd83d"', Json::canonical(Json::decode('"' . self::U . 'DBFF' . self::U . 'DC00x' . self::U . 'D83D"')));
        self::assertSame('"' . self::U . 'dc00' . self::U . 'd800"', Json::canonical(Json::decode('"' . self::U . 'dc00' . self::U . 'd800"')));
        self::assertSame('"' . "\u{1F600}" . '"', Json::canonical(Json::decode('"' . self::U . 'd83d' . self::U . 'de00"')));
    }

    public function testParsesNumbersLikeJavaScript(): void
    {
        self::assertSame(1, Json::decode('1'));
        self::assertSame(0, Json::decode('-0'));
        self::assertSame(1.0, Json::decode('1.0'));
        self::assertSame(9007199254740991, Json::decode('9007199254740991'));
        self::assertSame('9007199254740992', Json::canonical(Json::decode('9007199254740993')));
        self::assertSame('1e+21', Json::canonical(Json::decode('1000000000000000000000')));
        self::assertSame('null', Json::canonical(Json::decode('1e400')));
    }

    public function testRejectsInvalidJson(): void
    {
        foreach (['', '{', '[1,]', '{"a":1,}', '01', '1.', '.5', '"a', "\"\x01\"", '"\x"', '"\u12"', 'tru', '{a:1}', '1 2', "\"\xff\"", "'a'", 'NaN'] as $bad) {
            try {
                Json::decode($bad);
                self::fail("accepted $bad");
            } catch (AccordException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStringsCompareByUtf16CodeUnit(): void
    {
        // U+E000 (one unit, 0xE000) sorts after U+1F600 (units 0xD83D 0xDE00); UTF-8 bytes disagree.
        self::assertSame(1, Utf16::compare("\u{E000}", '😀'));
        self::assertSame(-1, Utf16::compare('😀', "\u{FFFF}"));
        // A lone low surrogate sorts after a pair starting with a high one.
        $lowAlone = Json::decode('"' . self::U . 'dc00"');
        self::assertIsString($lowAlone);
        self::assertSame(1, Utf16::compare($lowAlone, '😀'));
        self::assertSame(-1, Utf16::compare('B', '_'));
        self::assertSame(-1, Utf16::compare('_', 'a'));
        self::assertSame(1, Utf16::compare("\u{E9}", "e\u{301}")); // never normalised
        self::assertSame(1, Utf16::length('😀') - 1);
        $o = new JsonObject(["\u{E000}" => 1, '😀' => 2, 'z' => 3]);
        self::assertSame('{"z":3,"😀":2,"' . "\u{E000}" . '":1}', Json::canonical($o));
    }
}
