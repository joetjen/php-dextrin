<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Convert;

use JOetjen\Dextrin\Value\DXNInteger;

/**
 * A small JSON reader for the JSON Schema converter: an object is a
 * {@see JsonObject}, its keys in the order written; an array a PHP list;
 * an integer an `int`, or a {@see DXNInteger} past 64 bits; a number with
 * a fraction or an exponent a `float` (so `1.0` stays a float); strings,
 * booleans and `null` as PHP has them. `json_decode()` keeps neither the
 * difference between `{}` and `[]` in an array, nor a key like `"1"` as a
 * string, nor an integer past 64 bits.
 */
final class Json
{
    private int $at = 0;

    private function __construct(private readonly string $text)
    {
    }

    /**
     * `$text` read as one JSON value, with nothing but white space after it.
     *
     * @param string $text the JSON text
     * @return mixed the value
     * @throws JsonSchemaError for text that is not JSON
     */
    public static function decode(string $text): mixed
    {
        $reader = new self($text);
        $value = $reader->value();
        $reader->space();
        if ($reader->at < strlen($text)) {
            throw new JsonSchemaError('text after the JSON value');
        }
        return $value;
    }

    private function space(): void
    {
        $this->at += strspn($this->text, " \t\r\n", $this->at);
    }

    private function fail(string $what): never
    {
        throw new JsonSchemaError("not JSON: {$what} at byte {$this->at}");
    }

    private function value(): mixed
    {
        $this->space();
        $c = $this->text[$this->at] ?? '';
        return match (true) {
            $c === '{' => $this->object(),
            $c === '[' => $this->array(),
            $c === '"' => $this->string(),
            $c === '-' || ctype_digit($c) => $this->number(),
            default => $this->word(),
        };
    }

    private function object(): JsonObject
    {
        $this->at++;
        $object = new JsonObject();
        $this->space();
        if (($this->text[$this->at] ?? '') === '}') {
            $this->at++;
            return $object;
        }
        while (true) {
            $this->space();
            if (($this->text[$this->at] ?? '') !== '"') {
                $this->fail('expected a key');
            }
            $key = $this->string();
            $this->space();
            if (($this->text[$this->at] ?? '') !== ':') {
                $this->fail('expected ":"');
            }
            $this->at++;
            $object->set($key, $this->value());
            $this->space();
            $c = $this->text[$this->at] ?? '';
            $this->at++;
            if ($c === '}') {
                return $object;
            }
            if ($c !== ',') {
                $this->fail('expected "," or "}"');
            }
        }
    }

    /** @return list<mixed> */
    private function array(): array
    {
        $this->at++;
        $items = [];
        $this->space();
        if (($this->text[$this->at] ?? '') === ']') {
            $this->at++;
            return $items;
        }
        while (true) {
            $items[] = $this->value();
            $this->space();
            $c = $this->text[$this->at] ?? '';
            $this->at++;
            if ($c === ']') {
                return $items;
            }
            if ($c !== ',') {
                $this->fail('expected "," or "]"');
            }
        }
    }

    private function string(): string
    {
        if (preg_match('/\G"(?:[^"\\\\\x00-\x1F]|\\\\(?:["\\\\\/bfnrt]|u[0-9A-Fa-f]{4}))*+"/', $this->text, $match, 0, $this->at) !== 1) {
            $this->fail('a malformed string');
        }
        $this->at += strlen($match[0]);
        try {
            $decoded = json_decode($match[0], false, 1, JSON_THROW_ON_ERROR);
        } catch (\JsonException $err) {
            $this->fail($err->getMessage());
        }
        return is_string($decoded) ? $decoded : $this->fail('a malformed string');
    }

    private function number(): int|float|DXNInteger
    {
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/', $this->text, $match, 0, $this->at) !== 1) {
            $this->fail('a malformed number');
        }
        $this->at += strlen($match[0]);
        if (($match[1] ?? '') === '' && ($match[2] ?? '') === '') {
            return DXNInteger::represent($match[0]);
        }
        return (float) $match[0];
    }

    private function word(): bool|null
    {
        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $value) {
            if (substr_compare($this->text, $word, $this->at, strlen($word)) === 0) {
                $this->at += strlen($word);
                return $value;
            }
        }
        $this->fail('an unexpected character');
    }
}
