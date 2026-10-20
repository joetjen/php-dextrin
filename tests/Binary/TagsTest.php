<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Tests\Binary;

use JOetjen\Dextrin\Binary\Tags;
use JOetjen\Dextrin\Value\DXNDuration;
use PHPUnit\Framework\TestCase;

final class TagsTest extends TestCase
{
    public function testRegexFlagsToByteAndBack(): void
    {
        $flags = ['i' => true, 'm' => false, 's' => true, 'u' => false, 'x' => false, 'f' => false, 'r' => true];
        $byte = Tags::regexFlagsToByte($flags);
        // bit 0 (i) + bit 2 (s) + bit 6 (r) = 0b1000101 = 69
        self::assertSame(0b1000101, $byte);
        self::assertSame($flags, Tags::regexByteToFlags($byte));
    }

    public function testRegexFlagsAllFalse(): void
    {
        $flags = ['i' => false, 'm' => false, 's' => false, 'u' => false, 'x' => false, 'f' => false, 'r' => false];
        self::assertSame(0, Tags::regexFlagsToByte($flags));
    }

    public function testRegexFlagsAllTrue(): void
    {
        $flags = ['i' => true, 'm' => true, 's' => true, 'u' => true, 'x' => true, 'f' => true, 'r' => true];
        self::assertSame(0b1111111, Tags::regexFlagsToByte($flags));
    }

    public function testDurationBitmaskAndFieldsPresent(): void
    {
        $d = new DXNDuration(years: 1, weeks: 2, nanoseconds: 3);
        $mask = Tags::durationBitmask($d);
        // bit 0 (years) + bit 2 (weeks) + bit 6 (nanoseconds) = 0b1000101 = 69
        self::assertSame(0b1000101, $mask);
        self::assertSame(['years', 'weeks', 'nanoseconds'], Tags::durationFieldsPresent($mask));
    }

    public function testDurationBitmaskEmpty(): void
    {
        self::assertSame(0, Tags::durationBitmask(new DXNDuration()));
        self::assertSame([], Tags::durationFieldsPresent(0));
    }

    public function testPrivateTagBlockRange(): void
    {
        foreach ([
            Tags::T_CHAR, Tags::T_SYMBOL, Tags::T_KEYWORD, Tags::T_TUPLE, Tags::T_ARRAY,
            Tags::T_ORDERED, Tags::T_SET, Tags::T_SORTED_SET, Tags::T_STRUCT, Tags::T_DATE,
            Tags::T_TIME_US, Tags::T_DATETIME_US, Tags::T_DURATION_US, Tags::T_REGEX, Tags::T_CUSTOM,
            Tags::T_EXTRA, Tags::T_BITS, Tags::T_LOCAL_DATETIME, Tags::T_TIME, Tags::T_DATETIME, Tags::T_DURATION,
            Tags::T_OBJECTID,
        ] as $tag) {
            self::assertGreaterThanOrEqual(200, $tag);
            self::assertLessThanOrEqual(221, $tag);
        }
    }

    // BSON's ObjectId takes 221 (Jan, 2026-10-20); an ip is RFC 9164's.
    public function testObjectIdAndIpHaveTheirTags(): void
    {
        self::assertSame([221, 52, 54], [Tags::T_OBJECTID, Tags::T_IPV4, Tags::T_IPV6]);
    }

    // A tag never changes its meaning (DXN.md §2.3): the nanosecond forms
    // took new numbers, and 0.2's microsecond ones keep theirs.
    public function testTheNanosecondFormsHaveTagsOfTheirOwn(): void
    {
        self::assertSame(
            [217, 218, 219, 220, 1, 210, 211, 212],
            [Tags::T_LOCAL_DATETIME, Tags::T_TIME, Tags::T_DATETIME, Tags::T_DURATION, Tags::T_TIMESTAMP_US, Tags::T_TIME_US, Tags::T_DATETIME_US, Tags::T_DURATION_US],
        );
    }
}
