<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Core\Json;
use Accord\Server\Sync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Lone surrogates in a pushed op are found where the TypeScript server's `lonePath` finds them. */
final class LonePathTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> op JSON → path */
    public static function ops(): iterable
    {
        yield 'none' => ['{"op_id":"d:1","value":{"a":"ok 😀","b":["😀"]}}', null];
        yield 'value' => ['{"op_id":"d:1","value":"a\ud800b"}', 'op.value'];
        yield 'low half alone' => ['{"op_id":"d:1","value":"\udc00"}', 'op.value'];
        yield 'element' => ['{"op_id":"d:1","element":"c\udfffd"}', 'op.element'];
        yield 'record' => ['{"op_id":"d:1","record":"dossier:e\ud800f"}', 'op.record'];
        yield 'nested array' => ['{"op_id":"d:1","value":{"x":[1,"ok","\ud800"]}}', 'op.value.x[2]'];
        yield 'key' => ['{"op_id":"d:1","value":{"\ud800":1}}', 'op.value (a key)'];
        yield 'index keys first, ascending' => ['{"op_id":"d:1","value":{"b":"\ud800","10":"\ud800","2":"\ud800"}}', 'op.value.2'];
        yield 'deps' => ['{"op_id":"d:1","deps":["d:0","\ud800"]}', 'op.deps[1]'];
    }

    #[DataProvider('ops')]
    public function testFindsTheSamePathAsTheTypeScriptServer(string $json, ?string $path): void
    {
        self::assertSame($path, Sync::lonePath(Json::decode($json)));
    }
}
