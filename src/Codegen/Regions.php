<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Codegen;

/**
 * The hand-written regions of a generated file ({@see Php}):
 * `// dextrin:keep NAME` ... `// dextrin:end NAME`. Before a file is
 * rewritten, every region of the old one is taken by its name and put
 * back into the slot of that name in the new one. A region whose slot is
 * gone -- or one the user made up where no slot is -- is never dropped:
 * it is kept as `orphaned_NAME` after `__halt_compiler();` at the end of
 * the file, where PHP does not read it (code that stood inside a method
 * would not parse in the class body), and `--check` fails until someone
 * moves or deletes it.
 *
 * @internal
 */
final class Regions
{
    private const REGION = '/^([ \t]*)\/\/ dextrin:keep (\S+)\n(.*?)^[ \t]*\/\/ dextrin:end \2\n/ms';
    private const HALT = "\n__halt_compiler();\n";
    private const WAS = '// Was in the region ';

    private function __construct()
    {
    }

    /**
     * `$generated` with the regions of `$old` put back by name, those whose
     * slot is gone kept as orphans.
     *
     * @param string $generated the file as generated now, its slots empty
     * @param string|null $old the file as it is on disk, if it is
     * @return string the file to write
     */
    public static function merge(string $generated, ?string $old): string
    {
        if ($old === null) {
            return $generated;
        }
        preg_match_all(self::REGION, $old, $found, PREG_SET_ORDER);
        $kept = [];
        foreach ($found as $match) {
            if ($match[3] !== '') {
                $kept[$match[2]] = $match[3];
            }
        }
        preg_match_all(self::REGION, $generated, $slots, PREG_SET_ORDER);
        $slotNames = array_flip(array_map(static fn (array $m): string => $m[2], $slots));

        $filled = (string) preg_replace_callback(
            self::REGION,
            static fn (array $m): string => isset($kept[$m[2]])
                ? "{$m[1]}// dextrin:keep {$m[2]}\n{$kept[$m[2]]}{$m[1]}// dextrin:end {$m[2]}\n"
                : $m[0],
            $generated,
        );

        ksort($kept);
        $orphans = [];
        foreach ($kept as $name => $body) {
            if (isset($slotNames[$name])) {
                continue;
            }
            $was = str_starts_with($name, 'orphaned_') ? substr($name, 9) : $name;
            $orphan = "orphaned_{$was}";
            $note = str_starts_with($body, self::WAS) ? '' : self::WAS . "{$was}, whose place is gone: move it, or delete it.\n";
            $orphans[] = "// dextrin:keep {$orphan}\n{$note}{$body}// dextrin:end {$orphan}\n";
        }
        return $orphans === [] ? $filled : $filled . self::HALT . implode("\n", $orphans);
    }

    /**
     * The names of the orphaned regions `$text` holds.
     *
     * @return list<string>
     */
    public static function orphans(string $text): array
    {
        preg_match_all('/^\/\/ dextrin:keep (orphaned_\S+)$/m', $text, $found);
        return $found[1];
    }
}
