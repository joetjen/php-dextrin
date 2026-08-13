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
        $d = new DXNDuration(years: 1, weeks: 2, microseconds: 3);
        $mask = Tags::durationBitmask($d);
        // bit 0 (years) + bit 2 (weeks) + bit 6 (microseconds) = 0b1000101 = 69
        self::assertSame(0b1000101, $mask);
        self::assertSame(['years', 'weeks', 'microseconds'], Tags::durationFieldsPresent($mask));
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
            Tags::T_TIME, Tags::T_DATETIME_OFFSET, Tags::T_DURATION, Tags::T_REGEX, Tags::T_CUSTOM,
        ] as $tag) {
            self::assertGreaterThanOrEqual(200, $tag);
            self::assertLessThanOrEqual(214, $tag);
        }
    }
}
