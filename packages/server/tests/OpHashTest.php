<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\Wire;
use Accord\Server\Sync;
use Accord\Testing\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `op_hash` is stored in the database: it must equal the TypeScript server's `opHash` exactly. */
final class OpHashTest extends TestCase
{
    /** @return iterable<string, array{string, string}> wire op JSON → hash computed by app/packages/server `opHash` */
    public static function ops(): iterable
    {
        yield 'assign' => ['{"op_id":"alice-phone:1","record":"dossier:1","field":"agent","hlc":"1700000000000:00000:alice-phone","kind":"assign","value":"alice","deps":[]}', '2f6cf54e113b3aedbbae6706955ec1816df0a866bed02830c3e5921d29630364'];
        yield 'non-ASCII, int-like key, numbers, empty object' => ['{"op_id":"d:7","record":"dossier:é","field":"client_name","hlc":"1700000000001:00003:d","kind":"assign","value":{"10":[1.5,1e+21,0,null,true],"名前":"Zoë 😀","b":{}},"deps":["d:6"]}', '865a8f7060ed82cb3d859c561a87d812b67e86c2347d1b3b1c4542a5eb8b6b14'];
        yield 'inc' => ['{"op_id":"d:8","record":"dossier:x","field":"visits","hlc":"1700000000002:00000:d","kind":"inc","by":-3}', '79afed57da10de5c6d7c9de46eb31a912c4e8ad8bd1c36b2afab9d9462a5f618'];
        yield 'first add (no deps)' => ['{"op_id":"d:9","record":"dossier:x","field":"docs","hlc":"1700000000003:00000:d","kind":"add","element":"cni.pdf"}', 'e74b3a17443d95df35dfaf88591dfa26ce31d8c6054e05e8ca015f51915c8a0f'];
        yield 'remove a number' => ['{"op_id":"d:10","record":"dossier:x","field":"docs","hlc":"1700000000004:00000:d","kind":"remove","element":2.5,"deps":["d:9"]}', 'd6c9e504b53699f18a7e2074942c662699c36df6f4ad11b7a846f9be0fec85e2'];
    }

    #[DataProvider('ops')]
    public function testMatchesTheTypeScriptServer(string $json, string $hash): void
    {
        self::assertSame($hash, Sync::opHash(Wire::encode(Wire::decode(Json::decode($json)))));
    }

    public function testKeyOrderDoesNotMatterAndContentDoes(): void
    {
        $a = Wire::encode(Wire::decode(Json::decode('{"kind":"inc","by":-3,"op_id":"d:8","record":"dossier:x","field":"visits","hlc":"1700000000002:00000:d"}')));
        self::assertSame('79afed57da10de5c6d7c9de46eb31a912c4e8ad8bd1c36b2afab9d9462a5f618', Sync::opHash($a));
        $b = Wire::encode(Wire::decode(Json::decode('{"kind":"inc","by":3,"op_id":"d:8","record":"dossier:x","field":"visits","hlc":"1700000000002:00000:d"}')));
        self::assertNotSame(Sync::opHash($a), Sync::opHash($b));
    }

    /** @return iterable<string, array{JsonObject, string, string}> contract/vectors/op-hash/op-hash.json */
    public static function vectors(): iterable
    {
        $file = Contract::dir() . '/vectors/op-hash/op-hash.json';
        $doc = Json::decode((string) file_get_contents($file));
        self::assertInstanceOf(JsonObject::class, $doc);
        self::assertSame(1, $doc->get('version'));
        $cases = $doc->get('cases');
        self::assertIsArray($cases);
        foreach ($cases as $case) {
            self::assertInstanceOf(JsonObject::class, $case);
            $op = $case->get('op');
            self::assertInstanceOf(JsonObject::class, $op);
            yield (string) $case->get('name') => [$op, (string) $case->get('canonical'), (string) $case->get('hash')];
        }
    }

    #[DataProvider('vectors')]
    public function testGoldenVector(JsonObject $op, string $canonical, string $hash): void
    {
        self::assertSame($canonical, Json::canonical($op));
        self::assertSame($hash, Sync::opHash($op));
        // As the server stores it: decoded and re-encoded, unchanged for these ops.
        $wire = Wire::encode(Wire::decode($op));
        self::assertSame($canonical, Json::canonical($wire));
        self::assertSame($hash, Sync::opHash($wire));
    }
}
