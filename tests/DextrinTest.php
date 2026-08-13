<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests;

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\DXNError;
use PHPUnit\Framework\TestCase;

final class DextrinTest extends TestCase
{
    public function testDecodeEncodeRoundTrip(): void
    {
        self::assertSame(['x' => 1, 'y' => 2], Dextrin::decode('%{x: 1, y: 2}'));
        self::assertSame('%{x:1,y:2}', Dextrin::encode(['x' => 1, 'y' => 2]));
    }

    public function testEncodePrettyAndIndent(): void
    {
        $value = ['x' => 1];
        self::assertSame("%{\n  x: 1\n}", Dextrin::encode($value, pretty: true));
        self::assertSame("%{\n    x: 1\n}", Dextrin::encode($value, pretty: true, indent: 4));
    }

    public function testDecodeThrowsDXNErrorWithStageAndOffsetOnMalformedInput(): void
    {
        try {
            Dextrin::decode('%{x: }');
            self::fail('expected DXNError');
        } catch (DXNError $e) {
            self::assertSame('parse', $e->stage);
            self::assertIsInt($e->offset);
        }
    }

    public function testEncodeThrowsDXNErrorForUnrepresentableValue(): void
    {
        try {
            Dextrin::encode(fn () => 1);
            self::fail('expected DXNError');
        } catch (DXNError $e) {
            self::assertSame('encode', $e->stage);
        }
    }

    public function testDXNErrorWrapsAndPreservesOriginalAsPrevious(): void
    {
        try {
            Dextrin::decode('"unterminated');
            self::fail('expected DXNError');
        } catch (DXNError $e) {
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testDXNErrorWrapDoesNotDoubleWrap(): void
    {
        $original = new DXNError('already wrapped', 'parse', 5);
        $wrapped = DXNError::wrap($original, 'encode');
        self::assertSame($original, $wrapped);
    }

    public function testDecodeBinaryEncodeBinaryRoundTrip(): void
    {
        $value = ['x' => 1, 'y' => 2];
        $binary = Dextrin::encodeBinary($value);
        self::assertSame('DX', substr($binary, 0, 2));
        self::assertSame($value, Dextrin::decodeBinary($binary));
    }

    public function testDecodeBinaryThrowsDXNErrorWithBinaryStage(): void
    {
        try {
            Dextrin::decodeBinary('not a dxnb document');
            self::fail('expected DXNError');
        } catch (DXNError $e) {
            self::assertSame('binary', $e->stage);
        }
    }

    public function testEncodeBinaryThrowsDXNErrorForUnrepresentableValue(): void
    {
        try {
            Dextrin::encodeBinary(fn () => 1);
            self::fail('expected DXNError');
        } catch (DXNError $e) {
            self::assertSame('binary', $e->stage);
        }
    }
}
