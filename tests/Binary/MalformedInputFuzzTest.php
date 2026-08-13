<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Binary\BinaryError;
use JOetjen\Dextrin\Binary\Decoder;
use PHPUnit\Framework\TestCase;

/**
 * Random bytes after a valid `.dxnb` envelope must always fail as a
 * clean {@see BinaryError}, never an uncaught crash (a `TypeError`, a
 * segfault-style fatal, an infinite loop) -- this project's
 * hand-rolled fuzz-testing convention (see `AGENTS.md`), applied to
 * the one pipeline where malformed input is adversarial-shaped bytes
 * rather than adversarial-shaped text.
 */
final class MalformedInputFuzzTest extends TestCase
{
    private const ITERATIONS = 500;

    public function testRandomBytesAfterValidEnvelopeNeverCrashUncleanly(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20260814));
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $len = $rng->getInt(0, 32);
            $garbage = $len === 0 ? '' : $rng->getBytes($len);
            $bytes = "DX\x01" . $garbage;
            try {
                Decoder::decode($bytes);
                // A small minority of random byte strings happen to be
                // valid CBOR (e.g. a single 0x00-0x17 byte is a valid
                // small integer) -- that's a legitimate success, not a
                // test failure; only an uncaught non-BinaryError
                // exception/crash is.
            } catch (BinaryError) {
                // Expected outcome for malformed input.
            } catch (\Throwable $e) {
                self::fail("iteration {$i} threw an uncaught " . $e::class . ' instead of BinaryError: ' . $e->getMessage() . ' (bytes: ' . bin2hex($bytes) . ')');
            }
        }
        // Reaching here without a self::fail() call means every
        // iteration either decoded successfully or failed cleanly.
        $this->addToAssertionCount(1);
    }

    public function testTruncatedValidDocumentsFailCleanly(): void
    {
        // Take a real, valid .dxnb document and truncate it at every
        // possible byte boundary -- every prefix except the full
        // document itself must fail as a clean BinaryError.
        $full = \JOetjen\Dextrin\Dextrin::encodeBinary(['a' => 1, 'b' => [1, 2, 3], 'c' => 'hello world']);
        for ($len = 0; $len < strlen($full); $len++) {
            try {
                Decoder::decode(substr($full, 0, $len));
                self::fail("truncation to {$len} bytes unexpectedly succeeded");
            } catch (BinaryError) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
