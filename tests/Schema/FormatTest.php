<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Schema;

use JOetjen\Dextrin\DXNError;
use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\HasCategory;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Schema\SchemaValidationError;
use PHPUnit\Framework\TestCase;

/**
 * `format:`, the superset's group 5 (design/schema-superset.md, Jan,
 * 2026-10-20; as dextrin 71fbec0): a string in one of a closed set of
 * formats. Each is checked the same way in every implementation: by the
 * reader DXN has for the tagged value, or by the grammar DXN.md §4.2a
 * gives.
 */
final class FormatTest extends TestCase
{
    private static function compiled(string $type): Registry
    {
        return Schema::compile(Dextrin::decode("%{T: {$type}}"), new Registry());
    }

    private static function registry(string $format): Registry
    {
        return self::compiled("{:refine :string %{format: :{$format}}}");
    }

    /** Whether `$value` is a `T` of `$registry`; only a type mismatch answers false. */
    private static function takes(mixed $value, Registry $registry): bool
    {
        try {
            return Schema::check($value, $registry, 'T');
        } catch (SchemaValidationError $err) {
            self::assertSame('schema/type-mismatch', $err->category());
            return false;
        }
    }

    /**
     * That `$format` takes every string of `$good` and refuses every one of `$bad`.
     *
     * @param list<string> $good
     * @param list<string> $bad
     */
    private static function check(string $format, array $good, array $bad): void
    {
        $r = self::registry($format);
        foreach ($good as $text) {
            self::assertTrue(self::takes($text, $r), "{$format} should take " . json_encode($text, JSON_UNESCAPED_UNICODE));
        }
        foreach ($bad as $text) {
            self::assertFalse(self::takes($text, $r), "{$format} should refuse " . json_encode($text, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testDatesAndTimesAsDxnReadsTheirTags(): void
    {
        self::check('date', ['2026-10-20'], ['2026-13-01', '2026-10-20T00:00:00Z', '']);
        self::check('time', ['14:30:00', '14:30:00.123456789'], ['24:00:00', '14:30']);
        self::check('datetime', ['2026-10-20T14:30:00Z', '2026-10-20T14:30:00+02:00'], [
            '2026-10-20T14:30:00',
            '2026-10-20 14:30:00Z',
        ]);
        self::check('local-datetime', ['2026-10-20T14:30:00'], ['2026-10-20T14:30:00Z']);
        self::check('duration', ['P1D', 'PT2.5S'], ['P', '1D']);
    }

    public function testUuidItselfReadsOnly8_4_4_4_12HexDigits(): void
    {
        foreach (['123e4567e89b12d3a456426614174000', '123e4567-e89b12d3-a456-4266-14174000'] as $bad) {
            try {
                Dextrin::decode("@uuid \"{$bad}\"");
                self::fail("@uuid should refuse {$bad}");
            } catch (DXNError $err) {
                self::assertSame('syntax', $err->category);
            }
        }
    }

    public function testUuidIpv4AndIpv6AsDxnReadsUuidAndIp(): void
    {
        self::check('uuid', ['123e4567-e89b-12d3-a456-426614174000'], ['123e4567e89b12d3a456426614174000']);
        self::check('ipv4', ['192.0.2.1'], ['2001:db8::1', '10.0.0.0/8', '1.2.3']);
        self::check('ipv6', ['2001:db8::1', '::ffff:192.0.2.1'], [
            '192.0.2.1',
            '2001:db8::/32',
            'fe80::1%eth0',
        ]);
    }

    public function testHostnameRfc1123Labels253CharactersAtMost(): void
    {
        self::check('hostname', ['example.com', 'a', 'xn--bcher-kva.example', 'a-b.c'], [
            '-a.com',
            'a-.com',
            'a..com',
            'example.com.',
            'ex_ample.com',
            'bücher.example',
            str_repeat('a', 64) . '.com',
            implode('.', array_fill(0, 4, str_repeat('a', 63))),
        ]);
    }

    public function testIdnHostnameLabelsOfLettersDigitsMarksAndHyphensInAnyScript(): void
    {
        self::check('idn-hostname', ['bücher.example', '例え.テスト', 'example.com'], [
            '-bücher.example',
            'bü cher.example',
            'a..b',
        ]);
    }

    public function testEmailADotAtomOrQuotedLocalPartAtAHostnameOrAnAddressLiteral(): void
    {
        self::check(
            'email',
            [
                'joe@example.com',
                'first.last+tag@example.com',
                '"joe smith"@example.com',
                'joe@[192.0.2.1]',
                'joe@[IPv6:2001:db8::1]',
            ],
            [
                'joe',
                'joe@',
                '@example.com',
                '.joe@example.com',
                'jo..e@example.com',
                'joe@example..com',
                'jöe@example.com',
                'joe@[2001:db8::1]',
                str_repeat('a', 65) . '@example.com',
            ],
        );
    }

    public function testIdnEmailAnEmailThatMayHoldCharactersOfAnyScript(): void
    {
        self::check('idn-email', ['jöe@bücher.example', 'joe@example.com'], ['jöe', 'jöe@-bücher.example']);
    }

    public function testUriAndUriReferenceRfc3986(): void
    {
        self::check(
            'uri',
            [
                'https://example.com/a/b?q=1#f',
                'mailto:joe@example.com',
                'urn:isbn:0451450523',
                'http://[2001:db8::1]:8080/',
                'http://user:pw@host/%20x',
            ],
            [
                '/relative/path',
                '//example.com',
                'http://exa mple.com',
                'http://[nope]/',
                '1http://x',
                'http://x/%zz',
            ],
        );
        self::check('uri-reference', ['/relative/path', '?q', '#f', '', '../a', 'https://example.com'], [
            'a:b c',
            'http://exa mple.com',
            '%zz',
        ]);
    }

    public function testIriAndIriReferenceAUriThatMayHoldCharactersOfAnyScript(): void
    {
        self::check('iri', ['https://bücher.example/straße'], ['/straße', 'https://bü cher.example']);
        self::check('iri-reference', ['/straße', 'https://bücher.example'], ['a b']);
    }

    public function testUriTemplateRfc6570(): void
    {
        self::check(
            'uri-template',
            ['https://example.com/{id}', '/s{?q,lang}', '{+path:6}/x', '{list*}', 'plain'],
            ['{', '{}', '{a b}', 'x}', '{?}', '{a:0}'],
        );
    }

    public function testJsonPointerAndRelativeJsonPointerRfc6901AndItsDraft(): void
    {
        self::check('json-pointer', ['', '/a/b', '/a~0b/c~1d', '/'], ['a', '/a~2', '/~']);
        self::check('relative-json-pointer', ['0', '1/a', '2#', '0/'], ['', '/a', '01', '-1', '1#/a']);
    }

    public function testSemverSemanticVersioning200(): void
    {
        self::check('semver', ['1.2.3', '0.0.0', '1.0.0-alpha.1', '1.0.0+build.5', '1.0.0-rc.1+sha.abc'], [
            '1.2',
            '01.2.3',
            '1.2.3-',
            '1.2.3-01',
            'v1.2.3',
        ]);
    }

    public function testMacSixOrEightPairsOfHexDigitsOneSeparatorThroughout(): void
    {
        self::check('mac', ['00:1a:2B:3c:4d:5e', '00-1a-2b-3c-4d-5e', '00:1a:2b:3c:4d:5e:6f:70'], [
            '00:1a:2b-3c:4d:5e',
            '001a2b3c4d5e',
            '00:1a:2b:3c:4d',
            '00:1a:2b:3c:4d:5e:6f',
        ]);
    }

    public function testHostPortAHostnameAnIpv4AddressOrABracketedIpv6OneAndAPort(): void
    {
        self::check('host-port', ['example.com:443', '192.0.2.1:0', '[2001:db8::1]:65535'], [
            'example.com',
            'example.com:65536',
            'example.com:08',
            '2001:db8::1:80',
            ':80',
        ]);
    }

    public function testAFormatRefusesAValueThatIsNoString(): void
    {
        $value = Dextrin::decode('@uuid "123e4567-e89b-12d3-a456-426614174000"');
        self::assertFalse(self::takes($value, self::registry('uuid')));
    }

    public function testAnUnknownFormatIsRefusedWhenTheSchemaCompiles(): void
    {
        try {
            self::compiled('{:refine :string %{format: :regex}}');
            self::fail('format: :regex should be refused');
        } catch (\Throwable $err) {
            $category = match (true) {
                $err instanceof HasCategory => $err->category(),
                $err instanceof DXNError => $err->category,
                default => null,
            };
            self::assertSame('schema/compile', $category);
            self::assertStringContainsString('format', $err->getMessage());
        }
    }
}
