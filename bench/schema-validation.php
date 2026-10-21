<?php

/*
 * How long decoding and a schema check take, stage by stage, on a schema
 * set more complex than any one would meet in practice
 * (bench/schemas/universe.dxns) -- the same benchmark as the Elixir
 * reference's bench/schema_validation.exs, on the very same data sets,
 * which the reference's generator writes:
 *
 *     (cd ../dextrin && mix run bench/generate_universes.exs 2026 2>/dev/null) \
 *       | php bench/schema-validation.php            # for 10 minutes
 *     ... | php bench/schema-validation.php 60       # for 60 seconds
 *
 * In a loop until the time is up: read a data set (one Universe as DXN
 * text, about 30% of them with exactly one error of 44 kinds), then time
 * it, stage by stage:
 *
 * - lex:    the text into tokens (`Lexer::tokenize`);
 * - parse:  the tokens into values (`Dextrin::decode`, less the lexing):
 *           this parser builds the values as it parses;
 * - decode: text to values, the two together;
 * - check:  `Schema::check` against Universe;
 * - total:  decode and check.
 *
 * A stage's time is a difference of two runs, so a short one can come out
 * slightly below zero. Reading is not timed. The first 200 data sets warm
 * up, untimed, as in the reference.
 *
 * Every verdict is compared with what the generator meant; a wrong one is
 * counted as a mismatch and the run exits 1. Six data sets are written to
 * tmp/bench/schema-validation/ as formatted DXN: for the good ones and the
 * bad ones, the fastest, the slowest and the one closest to the mean total
 * as the run went.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Text\Lexer;

ini_set('memory_limit', '4G');

const SCHEMAS = __DIR__ . '/schemas/universe.dxns';
const SAMPLES = 'tmp/bench/schema-validation';
const WARM_UP = 200;
const STAGES = ['lex', 'parse', 'decode', 'check', 'total'];
const HEAD = ['', 'count', 'total ms', 'mean µs', 'stddev µs', 'min µs', 'median µs', 'p90 µs', 'p99 µs', 'p99.9 µs', 'max µs'];

/** @return array{bool, array<string, int>} */
function measure(string $text, Registry $registry): array
{
    $t0 = hrtime(true);
    Lexer::tokenize($text);
    $lex = hrtime(true) - $t0;
    $t0 = hrtime(true);
    $value = Dextrin::decode($text);
    $decode = hrtime(true) - $t0;
    $t0 = hrtime(true);
    try {
        Schema::check($value, $registry, 'Universe');
        $ok = true;
    } catch (\Throwable) {
        $ok = false;
    }
    $check = hrtime(true) - $t0;
    return [$ok, ['lex' => $lex, 'parse' => $decode - $lex, 'decode' => $decode, 'check' => $check, 'total' => $decode + $check]];
}

function run(int $seconds): void
{
    $registry = Schema::compile(Dextrin::decode((string) file_get_contents(SCHEMAS)), new Registry());
    $acc = [
        'n' => 0,
        'stages' => array_fill_keys(STAGES, []),
        'good' => ['check' => [], 'total' => []],
        'bad' => ['check' => [], 'total' => []],
        'kinds' => [],
        'wrong' => [],
        'samples' => ['good' => null, 'bad' => null],
    ];
    $seen = 0;
    $deadline = PHP_INT_MAX;

    while (($line = fgets(STDIN)) !== false) {
        // A data set is ERROR<tab>TEXT; anything else on the stream --
        // Mix's own compile messages, if the generator had to compile
        // first -- is skipped.
        $tab = (int) strpos($line, "\t");
        if ($tab < 1 || preg_match('/^[-a-z_]+$/', substr($line, 0, $tab)) !== 1) {
            continue;
        }
        $kind = substr($line, 0, $tab);
        $error = $kind === '-' ? null : $kind;
        $text = rtrim(substr($line, $tab + 1), "\n");
        $seen++;
        if ($seen <= WARM_UP) {
            measure($text, $registry);
            if ($seen === WARM_UP) {
                echo "Timing data sets against Universe for {$seconds} s (after " . WARM_UP . " untimed)...\n";
                $deadline = hrtime(true) + $seconds * 1_000_000_000;
            }
            continue;
        }
        [$ok, $times] = measure($text, $registry);
        add($acc, $error, $ok, $times, $text);
        if (hrtime(true) >= $deadline) {
            break;
        }
    }

    report($acc, $seconds);
    if ($acc['wrong'] !== []) {
        echo "\n" . count($acc['wrong']) . " verdicts did not match what the data set was meant to be; the first ones:\n";
        foreach (array_slice($acc['wrong'], 0, 10) as [$i, $error, $ok]) {
            echo "  #{$i}: meant " . ($error ?? 'good') . ', got ' . ($ok ? 'ok' : 'refused') . "\n";
        }
        exit(1);
    }
    echo "\nEvery verdict matched what the data set was meant to be.\n";
    writeSamples($acc['samples']);
    exit(0);
}

/**
 * @param array<string, mixed> $acc
 * @param array<string, int> $times
 */
function add(array &$acc, ?string $error, bool $ok, array $times, string $text): void
{
    $n = ++$acc['n'];
    foreach (STAGES as $s) {
        $acc['stages'][$s][] = $times[$s];
    }
    $label = $error !== null ? 'bad' : 'good';
    $acc[$label]['check'][] = $times['check'];
    $acc[$label]['total'][] = $times['total'];
    if ($error !== null) {
        $acc['kinds'][$error][] = $times['check'];
    }
    if ($ok !== ($error === null)) {
        $acc['wrong'][] = [$n, $error, $ok];
    }
    see($acc['samples'], $label, $times['total'], [$n, $error, $text]);
}

/**
 * The fastest, the slowest and the one closest to the running mean total.
 *
 * @param array<string, mixed> $samples
 * @param array{int, ?string, string} $data
 */
function see(array &$samples, string $label, int $ns, array $data): void
{
    $s = &$samples[$label];
    if ($s === null) {
        $s = ['n' => 1, 'sum' => $ns, 'fastest' => [$ns, $data], 'slowest' => [$ns, $data], 'average' => [$ns, $data]];
        return;
    }
    $s['n']++;
    $s['sum'] += $ns;
    $mean = $s['sum'] / $s['n'];
    if ($ns < $s['fastest'][0]) { $s['fastest'] = [$ns, $data]; }
    if ($ns > $s['slowest'][0]) { $s['slowest'] = [$ns, $data]; }
    if (abs($ns - $mean) < abs($s['average'][0] - $mean)) { $s['average'] = [$ns, $data]; }
}

/** @param array<string, mixed> $samples */
function writeSamples(array $samples): void
{
    if (!is_dir(SAMPLES)) { mkdir(SAMPLES, 0777, true); }
    foreach (['good', 'bad'] as $label) {
        if ($samples[$label] === null) { continue; }
        foreach (['fastest', 'average', 'slowest'] as $which) {
            [$ns, [$i, $error, $text]] = $samples[$label][$which];
            $what = $error !== null ? "error {$error}" : 'no error';
            $body = Dextrin::encode(Dextrin::decode($text), pretty: true);
            file_put_contents(SAMPLES . "/{$which}-{$label}.dxn", "# Data set #{$i}, {$what}: decoded and checked in " . us($ns) . " µs.\n{$body}\n");
        }
    }
    echo "\nSix data sets written to " . SAMPLES . "/: the fastest, the slowest and the one\n";
    echo "closest to the mean, of the good ones and of the bad ones.\n";
}

/** @param array<string, mixed> $acc */
function report(array $acc, int $seconds): void
{
    echo "\n" . number_format($acc['n']) . " data sets in {$seconds} s: " . number_format(intdiv($acc['n'], max($seconds, 1))) . " a second, reading included.\n\n";
    echo "Each stage, all data sets:\n\n" . row(HEAD) . "\n";
    foreach (STAGES as $s) { echo row(stats($s, $acc['stages'][$s])) . "\n"; }
    echo "\nThe check and the total, good and bad:\n\n" . row(HEAD) . "\n";
    foreach (['good', 'bad'] as $label) {
        foreach (['check', 'total'] as $what) { echo row(stats("{$what}, {$label}", $acc[$label][$what])) . "\n"; }
    }
    echo "\nThe check of the bad ones, by error:\n\n" . row(HEAD) . "\n";
    $kinds = $acc['kinds'];
    ksort($kinds);
    foreach ($kinds as $kind => $times) { echo row(stats((string) $kind, $times)) . "\n"; }
}

/**
 * @param list<int> $times
 * @return list<string>
 */
function stats(string $name, array $times): array
{
    $n = count($times);
    if ($n === 0) { return [$name, '0', ...array_fill(0, 9, '-')]; }
    sort($times);
    $total = array_sum($times);
    $mean = $total / $n;
    $variance = 0.0;
    foreach ($times as $t) { $variance += ($t - $mean) ** 2; }
    $variance /= $n;
    $at = fn (float $q): int => $times[min($n - 1, (int) floor($q * $n))];
    return [$name, number_format($n), us($total / 1000), us($mean), us(sqrt($variance)), us($times[0]),
        us($at(0.5)), us($at(0.9)), us($at(0.99)), us($at(0.999)), us($times[$n - 1])];
}

/** Nanoseconds as microseconds, one decimal; a total given in µs comes out in ms. */
function us(int|float $ns): string { return number_format($ns / 1000, 1, '.', ''); }

/** @param list<string> $cells */
function row(array $cells): string
{
    $first = array_shift($cells);
    return str_pad($first, 22) . implode('', array_map(fn (string $c): string => str_pad($c, 11 + strlen($c) - mb_strlen($c), ' ', STR_PAD_LEFT), $cells));
}

run(isset($argv[1]) ? (int) $argv[1] : 600);
