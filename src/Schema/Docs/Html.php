<?php

declare(strict_types=1);

namespace JOetjen\Dextrin\Schema\Docs;

use JOetjen\Dextrin\Canonical;
use JOetjen\Dextrin\Schema\Docs;
use JOetjen\Dextrin\Value\DXNKeyword;
use JOetjen\Dextrin\Value\DXNList;
use JOetjen\Dextrin\Value\DXNMap;
use JOetjen\Dextrin\Text\SyntaxNode;

/**
 * The pages of `Docs` -- the port of `Dextrin.Schema.Docs.Html`, writing
 * what it writes byte for byte: `index.html` (the top: a section per root
 * namespace, then the names with no namespace), an `index.html` in a
 * directory per namespace, and the stylesheet they share, `style.css`.
 * Every template here is the reference's, character for character.
 *
 * @internal
 */
final class Html
{
    /** @var array<string, Definition> */
    private array $known = [];
    /** @var array<string, list<string>> */
    private array $usedBy = [];
    /** @var array<string, list<Definition>> */
    private array $byNamespace = [];
    /** @var list<string> */
    private array $all = [];
    /** @var array<string, string> */
    private array $notes = [];

    private function __construct(private readonly string $title)
    {
    }

    /**
     * The site's pages for `$definitions`, under the name `$title`.
     *
     * @param list<Definition> $definitions
     * @return list<array{0: string, 1: string}>
     */
    public static function pages(string $title, array $definitions): array
    {
        $self = new self($title);
        foreach ($definitions as $d) {
            $self->known[$d->name] = $d;
            $self->byNamespace[$d->namespace][] = $d;
            if ($d->namespaceNote !== null && $d->namespaceNote !== '') {
                $self->notes[$d->namespace] = $d->namespaceNote;
            }
        }
        $all = [];
        foreach (array_keys($self->byNamespace) as $ns) {
            if ($ns !== '') {
                foreach (self::prefixes((string) $ns) as $prefix) {
                    $all[$prefix] = true;
                }
            }
        }
        $self->all = array_map('strval', array_keys($all));
        sort($self->all, SORT_STRING);
        $self->usedBy = self::usedBy($definitions);

        $pages = [['style.css', Assets::STYLESHEET], ['index.html', $self->namespacePage(null)]];
        foreach ($self->all as $ns) {
            $pages[] = [self::pageOf($ns), $self->namespacePage($ns)];
        }
        return $pages;
    }

    /**
     * Sorted by a lower-cased short name, stably.
     *
     * @param list<Definition> $defs
     * @return list<Definition>
     */
    private static function byShort(array $defs): array
    {
        usort($defs, static fn (Definition $a, Definition $b): int => strcmp(strtolower($a->short), strtolower($b->short)));
        return $defs;
    }

    // ---- the namespace tree ---------------------------------------------------

    /** @return list<string> `acme.shop` and every namespace above it */
    private static function prefixes(string $namespace): array
    {
        $segments = explode('.', $namespace);
        $out = [];
        for ($n = 1; $n <= count($segments); $n++) {
            $out[] = implode('.', array_slice($segments, 0, $n));
        }
        return $out;
    }

    /** @return list<string> the namespaces directly below `$parent` (null: the roots) */
    private function children(?string $parent): array
    {
        if ($parent === null) {
            return array_values(array_filter($this->all, static fn (string $ns): bool => !str_contains($ns, '.')));
        }
        $depth = count(explode('.', $parent));
        return array_values(array_filter(
            $this->all,
            static fn (string $ns): bool => str_starts_with($ns, "{$parent}.") && count(explode('.', $ns)) === $depth + 1,
        ));
    }

    /**
     * The public definitions of `$namespace`, in order, and the internal
     * ones by owner.
     *
     * @return array{0: list<Definition>, 1: array<string, list<Definition>>}
     */
    private function own(?string $namespace): array
    {
        $defs = $this->byNamespace[$namespace ?? ''] ?? [];
        $public = [];
        $internal = [];
        foreach ($defs as $d) {
            if ($d->internalTo === null) {
                $public[] = $d;
            } else {
                $internal[$d->internalTo][] = $d;
            }
        }
        return [self::byShort($public), $internal];
    }

    /**
     * @param array<string, list<Definition>> $internal
     * @return list<Definition>
     */
    private static function internalsOf(Definition $d, array $internal): array
    {
        return self::byShort($internal[$d->name] ?? []);
    }

    /** Every public definition in `$namespace` and below it. */
    private function total(string $namespace): int
    {
        $n = 0;
        foreach ($this->byNamespace as $ns => $defs) {
            $ns = (string) $ns;
            if ($ns === $namespace || str_starts_with($ns, "{$namespace}.")) {
                foreach ($defs as $d) {
                    if ($d->internalTo === null) {
                        $n++;
                    }
                }
            }
        }
        return $n;
    }

    // ---- addresses --------------------------------------------------------------

    /**
     * A namespace is a directory, its page the directory's `index.html`; the
     * top is `index.html`. Every link is written from the site's root, and
     * made relative to its page when the page is put together.
     */
    private static function pageOf(?string $namespace): string
    {
        if ($namespace === null || $namespace === '') {
            return 'index.html';
        }
        return str_replace('.', '/', $namespace) . '/index.html';
    }

    private static function relative(string $html, string $path): string
    {
        $base = str_repeat('../', count(explode('/', $path)) - 1);
        return (string) preg_replace('/(href)="(?!https?:|#|mailto:)([^"]*)"/', '$1="' . $base . '$2"', $html);
    }

    private static function anchor(Definition $d): string
    {
        return $d->internalTo === null ? $d->short : self::shortOf($d->internalTo) . '.' . $d->short;
    }

    private static function shortOf(string $name): string
    {
        $parts = explode('/', $name);
        return $parts[count($parts) - 1];
    }

    private static function href(Definition $d): string
    {
        return self::pageOf($d->namespace) . '#' . self::anchor($d);
    }

    private static function lastSegment(string $namespace): string
    {
        $parts = explode('.', $namespace);
        return $parts[count($parts) - 1];
    }

    /**
     * Who refers to whom.
     *
     * @param list<Definition> $definitions
     * @return array<string, list<string>>
     */
    private static function usedBy(array $definitions): array
    {
        $out = [];
        $seen = [];
        foreach ($definitions as $d) {
            $refs = [];
            foreach (self::typesOf($d) as $type) {
                foreach (Docs::references($type, $d->scope) as $ref) {
                    $refs[$ref] = true;
                }
            }
            foreach ($d->versions as $v) {
                foreach ($v->bases as $group) {
                    foreach ($group['names'] as [$name]) {
                        $refs[Docs::resolve($name, $d->scope)] = true;
                    }
                }
            }
            foreach (array_keys($refs) as $ref) {
                $ref = (string) $ref;
                if ($ref === $d->name || isset($seen["{$ref}\0{$d->name}"])) {
                    continue;
                }
                $seen["{$ref}\0{$d->name}"] = true;
                $out[$ref][] = $d->name;
            }
        }
        return $out;
    }

    /**
     * Every type a definition's versions write: a named type's definition
     * and its parameters' bounds and defaults, a body's field types and its
     * extras' keys and values.
     *
     * @return list<?At>
     */
    private static function typesOf(Definition $d): array
    {
        $out = [];
        foreach ($d->versions as $v) {
            if ($d->kind === 'type') {
                $out[] = $v->value;
                foreach ($v->params as $p) {
                    $out[] = $p['bound'];
                    $out[] = $p['default'];
                }
            } else {
                foreach ($v->fields as $f) {
                    if ($f instanceof Field) {
                        $out[] = $f->type;
                    } elseif ($f->kind === 'extra') {
                        $out[] = $f->key;
                        $out[] = $f->type;
                    }
                }
            }
        }
        return $out;
    }

    // ---- the frame: the sidebar, then the page ----------------------------------

    private function frame(string $title, ?string $current, string $body): string
    {
        return self::relative($this->page($title, $current, $body), self::pageOf($current));
    }

    private function page(string $title, ?string $current, string $body): string
    {
        return "<!doctype html>\n"
            . "<html lang=\"en\">\n"
            . "<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n"
            . '<title>' . Markdown::escape($title) . "</title>\n"
            . "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
            . "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n"
            . "<link rel=\"stylesheet\" href=\"https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&family=JetBrains+Mono:wght@400;600&display=swap\">\n"
            . "<link rel=\"stylesheet\" href=\"style.css\">\n"
            . '<script>' . Assets::THEME_EARLY . "</script>\n"
            . "</head>\n"
            . "<body>\n"
            . "<div class=\"layout\">\n"
            . "  <nav class=\"sidebar\" aria-label=\"All namespaces and definitions\">\n"
            . "    <div class=\"sidebar-head\">\n"
            . '      <a class="brand" href="index.html"><span class="brand-mark" aria-hidden="true">%{}</span>' . Markdown::escape($this->title) . "</a>\n"
            . "      <button class=\"theme-toggle\" type=\"button\" id=\"theme-toggle\" aria-label=\"Switch between light and dark\">Dark</button>\n"
            . "    </div>\n"
            . "    <details class=\"sidebar-toggle\" open>\n"
            . "      <summary>Browse</summary>\n"
            . '      ' . $this->sidebar($current) . "\n"
            . "    </details>\n"
            . "  </nav>\n"
            . "  <main class=\"main\">\n"
            . '    ' . $body . "\n"
            . "    <footer class=\"site-foot\"><p>Generated from the <code>.dxns</code> sources by Dextrin. A field's # is its position in the binary form.</p></footer>\n"
            . "  </main>\n"
            . "</div>\n"
            . '<script>' . Assets::THEME_SWITCH . "</script>\n"
            . "</body>\n"
            . "</html>\n";
    }

    /** The tree of every namespace and definition, `$current`'s branch open. */
    private function sidebar(?string $current): string
    {
        $roots = '';
        foreach ($this->children(null) as $ns) {
            $roots .= $this->treeNode($ns, $current);
        }
        [$public, $internal] = $this->own('');
        $global = '';
        if ($public !== []) {
            $global = '<li><details' . ($current === null ? ' open' : '') . '><summary><a href="' . self::pageOf(null) . '">No namespace</a></summary><ul>';
            foreach ($public as $d) {
                $global .= self::treeDefinition($d, $internal);
            }
            $global .= '</ul></details></li>';
        }
        return "<ul class=\"tree\">{$roots}{$global}</ul>";
    }

    private function treeNode(string $namespace, ?string $current): string
    {
        $onPath = $current !== null && $current !== ''
            && ($current === $namespace || str_starts_with($current, "{$namespace}."));
        [$public, $internal] = $this->own($namespace);
        $inner = '';
        foreach ($this->children($namespace) as $ns) {
            $inner .= $this->treeNode($ns, $current);
        }
        foreach ($public as $d) {
            $inner .= self::treeDefinition($d, $internal);
        }
        return '<li><details' . ($onPath ? ' open' : '') . '><summary><a href="' . self::pageOf($namespace) . '"' . self::aria($current === $namespace) . '>'
            . Markdown::escape(self::lastSegment($namespace)) . "</a></summary><ul>{$inner}</ul></details></li>";
    }

    /** @param array<string, list<Definition>> $internal */
    private static function treeDefinition(Definition $d, array $internal): string
    {
        $list = self::internalsOf($d, $internal);
        $inner = '';
        if ($list !== []) {
            $inner = '<ul>';
            foreach ($list as $i) {
                $inner .= self::treeDefinition($i, []);
            }
            $inner .= '</ul>';
        }
        return '<li class="def-leaf"><a href="' . self::href($d) . '"><span class="dot dot-' . $d->kind . '" aria-hidden="true"></span>'
            . Markdown::escape($d->short) . "</a>{$inner}</li>";
    }

    private static function aria(bool $current): string
    {
        return $current ? ' aria-current="page"' : '';
    }

    // ---- a namespace's page, and the index ---------------------------------------

    /**
     * One design for both: a section for each namespace directly below,
     * listing its own namespaces and definitions; then this namespace's own
     * definitions in full. The top's own definitions are the names with no
     * namespace.
     */
    private function namespacePage(?string $namespace): string
    {
        [$public, $internal] = $this->own($namespace);
        $below = $this->children($namespace);
        $sections = '';
        foreach ($below as $ns) {
            $sections .= $this->childSection($ns);
        }
        $note = $namespace !== null ? ($this->notes[$namespace] ?? null) : null;
        $cards = '';
        foreach ($public as $d) {
            $cards .= $this->definition($d);
            foreach (self::internalsOf($d, $internal) as $i) {
                $cards .= $this->definition($i);
            }
        }
        $ownHeading = '';
        if ($cards !== '' && $below !== []) {
            $ownHeading = $namespace === null
                ? '<h2 class="own-heading">Names with no namespace</h2>'
                : '<h2 class="own-heading">Definitions in <code>' . Markdown::escape($namespace) . '</code></h2>';
        }
        if ($namespace === null) {
            [$eyebrow, $heading, $title] = ['Schema reference', Markdown::escape($this->title), $this->title];
        } else {
            [$eyebrow, $heading, $title] = ['Namespace', '<code>' . Markdown::escape($namespace) . '</code>', "{$namespace} · {$this->title}"];
        }
        $lede = $namespace === null
            ? '<p class="lede">Every struct schema, shape and named type, by namespace. A name links to its definition wherever it appears.</p>'
            : '';
        $noteHtml = $note !== null && $note !== '' ? '<div class="prose">' . Markdown::toHtml($note, $this->linker([])) . '</div>' : '';
        return $this->frame(
            $title,
            $namespace,
            "<div class=\"page\">\n"
            . "  <header class=\"intro\">\n"
            . '    ' . self::crumbs($namespace) . "\n"
            . "    <p class=\"eyebrow\">{$eyebrow}</p>\n"
            . "    <h1>{$heading}</h1>\n"
            . "    {$lede}\n"
            . "    {$noteHtml}\n"
            . "  </header>\n"
            . '  ' . ($sections === '' ? '' : "<div class=\"sections\">{$sections}</div>") . "\n"
            . "  {$ownHeading}\n"
            . "  {$cards}\n"
            . "</div>\n",
        );
    }

    private static function crumbs(?string $namespace): string
    {
        if ($namespace === null) {
            return '';
        }
        $links = ['<a href="index.html">All namespaces</a>'];
        foreach (array_slice(self::prefixes($namespace), 0, -1) as $p) {
            $links[] = '<a href="' . self::pageOf($p) . '">' . Markdown::escape(self::lastSegment($p)) . '</a>';
        }
        return '<p class="crumbs">' . implode(' › ', $links) . ' › <b>' . Markdown::escape(self::lastSegment($namespace)) . '</b></p>';
    }

    /** A namespace directly below the page's. */
    private function childSection(string $namespace): string
    {
        [$public] = $this->own($namespace);
        $note = self::firstParagraph($this->notes[$namespace] ?? null);
        $subs = '';
        foreach ($this->children($namespace) as $sub) {
            $subs .= '<li><a class="ns-link" href="' . self::pageOf($sub) . '"><code>' . Markdown::escape(self::lastSegment($sub))
                . '</code></a><span class="count">' . $this->total($sub) . '</span></li>';
        }
        $defs = '';
        foreach ($public as $d) {
            $defs .= self::defLink($d);
        }
        return "<section class=\"ns-section\">\n"
            . '  <h2><a href="' . self::pageOf($namespace) . '"><code>' . Markdown::escape($namespace) . "</code></a></h2>\n"
            . '  ' . ($note !== null ? '<div class="prose summary">' . Markdown::toHtml($note, static fn (string $code): ?string => null) . '</div>' : '') . "\n"
            . '  ' . ($subs === '' ? '' : "<div class=\"group\"><p class=\"label\">Namespaces</p><ul class=\"links\">{$subs}</ul></div>") . "\n"
            . '  ' . ($defs === '' ? '' : "<div class=\"group\"><p class=\"label\">Definitions</p><ul class=\"links\">{$defs}</ul></div>") . "\n"
            . "</section>\n";
    }

    private static function defLink(Definition $d): string
    {
        return '<li><a href="' . self::href($d) . '"><code>' . Markdown::escape($d->short) . '</code></a>' . self::kindChip($d) . '</li>';
    }

    private static function firstParagraph(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        return (preg_split('/\n\s*\n/', $text) ?: [$text])[0];
    }

    // ---- one definition -------------------------------------------------------------

    private function definition(Definition $d): string
    {
        $versions = $d->versions;
        $latest = $versions[0];
        $older = array_slice($versions, 1);
        $users = $this->usedBy[$d->name] ?? [];
        sort($users, SORT_STRING);
        $used = implode(', ', array_map(function (string $name): string {
            $user = $this->known[$name] ?? null;
            return $user !== null
                ? '<a href="' . self::href($user) . '"><code>' . Markdown::escape(self::display($user)) . '</code></a>'
                : '<code>' . Markdown::escape($name) . '</code>';
        }, $users));
        $olderHtml = '';
        foreach ($older as $v) {
            $olderHtml .= $this->olderVersion($d, $v);
        }
        $anchor = Markdown::escape(self::anchor($d));
        return "<article class=\"def kind-{$d->kind}\" id=\"{$anchor}\">\n"
            . "  <header class=\"def-head\">\n"
            . "    <h2><a class=\"self\" href=\"#{$anchor}\"><code>" . self::struck(Markdown::escape($d->short), $latest->annotations) . "</code></a></h2>\n"
            . '    <div class="chips">' . self::kindChip($d) . $this->internalChip($d) . self::versionChips($d) . self::annotationChips($latest->annotations) . "</div>\n"
            . '    ' . self::title($latest->annotations) . '<p class="full-name"><code>' . Markdown::escape($d->name) . "</code></p>\n"
            . "  </header>\n"
            . '  ' . $this->about($d, $latest->annotations) . "\n"
            . '  ' . $this->versionBody($d, $latest) . "\n"
            . "  {$olderHtml}\n"
            . '  ' . ($used === '' ? '' : "<p class=\"used-by\"><span class=\"label\">Used by</span> {$used}</p>") . "\n"
            . "</article>\n";
    }

    // ---- annotations, {:doc T %{...}} (DXNS.md §1b) ---------------------------

    /**
     * What a definition's page says about it before its body: the doc's
     * description, the comments, examples and meta -- each only when there
     * is one, so a definition without annotations reads as it always did.
     *
     * @param array<string, mixed> $notes
     */
    private function about(Definition $d, array $notes): string
    {
        $description = $notes['description'] ?? null;
        $parts = [
            $this->prose(is_string($description) ? $description : '', $d),
            $this->prose($d->doc, $d),
            $this->values($notes, $d),
            self::examples($notes),
            self::messages($notes),
            self::meta($notes),
        ];
        return implode("\n  ", array_values(array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * Deprecated (with its reason), read-only, write-only, key, secret,
     * visibility and discriminator, as chips.
     *
     * @param array<string, mixed> $notes
     */
    private static function annotationChips(array $notes): string
    {
        $out = self::deprecatedChip($notes);
        foreach (['read-only' => 'chip-read-only', 'write-only' => 'chip-write-only'] as $key => $class) {
            if (($notes[$key] ?? null) === true) {
                $out .= "<span class=\"chip {$class}\">{$key}</span>";
            }
        }
        $key = $notes['key'] ?? null;
        $out .= match (true) {
            $key === true => '<span class="chip chip-key">key</span>',
            is_string($key) => '<span class="chip chip-key">key: ' . Markdown::escape($key) . '</span>',
            default => '',
        };
        if (($notes['secret'] ?? null) === true) {
            $out .= '<span class="chip chip-secret">secret</span>';
        }
        $out .= self::visibilityChip($notes['visibility'] ?? null);
        $discriminator = $notes['discriminator'] ?? null;
        if ($discriminator !== null) {
            $out .= '<span class="chip chip-discriminator">discriminator: ' . Markdown::escape(self::stageName($discriminator)) . '</span>';
        }
        return $out;
    }

    /** The stages that see a field (DXNS.md §1b); an empty list is none. */
    private static function visibilityChip(mixed $stages): string
    {
        if (!$stages instanceof DXNList) {
            return '';
        }
        if ($stages->items === []) {
            return '<span class="chip chip-visibility">visible on no stage</span>';
        }
        $names = implode(', ', array_map(self::stageName(...), $stages->items));
        return '<span class="chip chip-visibility">visible on ' . Markdown::escape($names) . '</span>';
    }

    /** A keyword's name; anything else as DXN writes it. */
    private static function stageName(mixed $value): string
    {
        return $value instanceof DXNKeyword ? $value->name : self::written($value);
    }

    /**
     * Deprecated, with its reason when it gives one.
     *
     * @param array<string, mixed> $notes
     */
    private static function deprecatedChip(array $notes): string
    {
        $deprecated = $notes['deprecated'] ?? null;
        return match (true) {
            $deprecated === true => '<span class="chip chip-deprecated">deprecated</span>',
            is_string($deprecated) => '<span class="chip chip-deprecated" title="' . Markdown::escape($deprecated) . '">deprecated: ' . Markdown::escape($deprecated) . '</span>',
            default => '',
        };
    }

    /**
     * `$html` struck through when `$notes` deprecate what it names.
     *
     * @param array<string, mixed> $notes
     */
    private static function struck(string $html, array $notes): string
    {
        $deprecated = $notes['deprecated'] ?? null;
        return $deprecated === true || is_string($deprecated) ? "<s>{$html}</s>" : $html;
    }

    /**
     * An enum's values, each with what its own annotations say.
     *
     * @param array<string, mixed> $notes
     */
    private function values(array $notes, Definition $d): string
    {
        $values = $notes['values'] ?? null;
        if (!is_array($values) || $values === []) {
            return '';
        }
        $written = [];
        foreach ($values as $pair) {
            if (is_array($pair) && array_key_exists(0, $pair) && is_array($pair[1] ?? null)) {
                $written[] = [self::written($pair[0]), $pair[1]];
            }
        }
        usort($written, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $rows = '';
        foreach ($written as [$literal, $own]) {
            /** @var array<string, mixed> $own */
            $text = $own['description'] ?? null;
            $description = is_string($text) ? Markdown::toHtml($text, $this->linker($d->scope)) : '';
            $rows .= '<dt><code>' . Markdown::escape($literal) . '</code>' . self::annotationChips($own) . "</dt><dd>{$description}</dd>";
        }
        return "<dl class=\"values\"><p class=\"label\">Values</p>{$rows}</dl>";
    }

    /** @param array<string, mixed> $notes */
    private static function title(array $notes): string
    {
        $title = $notes['title'] ?? null;
        return is_string($title) ? '<p class="title">' . Markdown::escape($title) . '</p>' : '';
    }

    /** @param array<string, mixed> $notes */
    private static function examples(array $notes): string
    {
        $examples = $notes['examples'] ?? null;
        if (!$examples instanceof DXNList || $examples->items === []) {
            return '';
        }
        $items = '';
        foreach ($examples->items as $example) {
            $items .= '<li><code>' . Markdown::escape(self::written($example)) . '</code></li>';
        }
        return "<div class=\"examples\"><p class=\"label\">Examples</p><ul>{$items}</ul></div>";
    }

    /** @param array<string, mixed> $notes */
    private static function meta(array $notes): string
    {
        $meta = $notes['meta'] ?? null;
        if (!(is_array($meta) || $meta instanceof DXNMap) || Docs::pairsOf($meta) === []) {
            return '';
        }
        $rows = array_map(static fn (array $pair): array => [self::written($pair[0]), self::written($pair[1])], Docs::pairsOf($meta));
        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        $out = '';
        foreach ($rows as [$key, $value]) {
            $out .= '<dt><code>' . Markdown::escape($key) . '</code></dt><dd><code>' . Markdown::escape($value) . '</code></dd>';
        }
        return "<dl class=\"meta\"><p class=\"label\">Meta</p>{$out}</dl>";
    }

    /**
     * A refusal's own words, by the refine key that refuses.
     *
     * @param array<string, mixed> $notes
     */
    private static function messages(array $notes): string
    {
        $messages = $notes['messages'] ?? null;
        if (!(is_array($messages) || $messages instanceof DXNMap) || Docs::pairsOf($messages) === []) {
            return '';
        }
        $rows = array_map(
            static fn (array $pair): array => [self::written($pair[0]), is_string($pair[1]) ? $pair[1] : (is_scalar($pair[1]) ? (string) $pair[1] : self::written($pair[1]))],
            Docs::pairsOf($messages),
        );
        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        $out = '';
        foreach ($rows as [$key, $message]) {
            $out .= '<dt><code>' . Markdown::escape($key) . '</code></dt><dd>' . Markdown::escape($message) . '</dd>';
        }
        return "<dl class=\"messages\"><p class=\"label\">Messages</p>{$out}</dl>";
    }

    /** A decoded value as DXN writes it; its PHP type when it has no DXN form. */
    private static function written(mixed $value): string
    {
        try {
            return Canonical::print($value);
        } catch (\Throwable) {
            return get_debug_type($value);
        }
    }

    private static function display(Definition $d): string
    {
        return $d->internalTo === null ? $d->name : self::shortOf($d->internalTo) . ' › ' . $d->short;
    }

    private function prose(string $text, Definition $d): string
    {
        return $text === '' ? '' : '<div class="prose">' . Markdown::toHtml($text, $this->linker($d->scope)) . '</div>';
    }

    private static function kindChip(Definition $d): string
    {
        return match ($d->kind) {
            'schema' => '<span class="chip chip-schema">struct schema</span>',
            'shape' => '<span class="chip chip-shape">shape</span>',
            'type' => $d->versions[0]->params !== []
                ? '<span class="chip chip-type">generic type</span>'
                : '<span class="chip chip-type">type</span>',
        };
    }

    private function internalChip(Definition $d): string
    {
        if ($d->internalTo === null) {
            return '';
        }
        $o = $this->known[$d->internalTo] ?? null;
        return $o !== null
            ? '<span class="chip chip-internal">internal to <a href="' . self::href($o) . '">' . Markdown::escape($o->short) . '</a></span>'
            : '<span class="chip chip-internal">internal</span>';
    }

    /** Where version `$n` of a definition is: its own anchor and the version. */
    private static function versionAnchor(Definition $d, ?int $n): string
    {
        return self::anchor($d) . "-v{$n}";
    }

    private static function versionChips(Definition $d): string
    {
        $n = $d->versions[0]->version;
        if ($n === null) {
            return '';
        }
        $count = count($d->versions);
        return '<span class="chip chip-version" id="' . Markdown::escape(self::versionAnchor($d, $n)) . "\">v{$n}</span>"
            . ($count === 1 ? '' : "<span class=\"chip chip-quiet\">{$count} versions</span>");
    }

    private function olderVersion(Definition $d, Version $version): string
    {
        return '<details class="older" id="' . Markdown::escape(self::versionAnchor($d, $version->version)) . "\">\n"
            . '  <summary>' . self::struck("Version {$version->version}", $version->annotations) . ' ' . self::deprecatedChip($version->annotations)
            . '<span class="file">' . Markdown::escape($version->file) . "</span></summary>\n"
            . '  ' . $this->versionBody($d, $version) . "\n"
            . "</details>\n";
    }

    private function versionBody(Definition $d, Version $version): string
    {
        if ($d->kind === 'type') {
            return "<div class=\"type-def\">\n"
                . '  ' . $this->paramsHtml($version->params, $d) . "<p class=\"label\">Definition</p>\n"
                . '  <pre class="expr"><code>' . $this->typeHtml($version->value, $d) . "</code></pre>\n"
                . '  ' . self::fileLine($version) . "\n"
                . "</div>\n";
        }
        $note = $version->note !== null && $version->note !== ''
            ? '<div class="prose version-note">' . Markdown::toHtml($version->note, $this->linker($d->scope)) . '</div>'
            : '';
        $positionHead = $d->kind === 'schema' ? '<th scope="col" class="pos" title="Position in the binary form">#</th>' : '';
        $rows = '';
        foreach ($version->fields as $f) {
            $rows .= $this->fieldRow($f, $d);
        }
        return "{$note}\n"
            . $this->basesHtml($version->bases, $d) . "<div class=\"table-wrap\">\n"
            . "  <table class=\"fields\">\n"
            . "    <thead><tr>{$positionHead}<th scope=\"col\">Field</th><th scope=\"col\">Type</th><th scope=\"col\">Notes</th></tr></thead>\n"
            . "    <tbody>{$rows}</tbody>\n"
            . "  </table>\n"
            . "</div>\n"
            . self::fileLine($version) . "\n";
    }

    /** The file a version is in, and what generated it when a tool did. */
    private static function fileLine(Version $version): string
    {
        $provenance = $version->provenance === null
            ? ''
            : ' <span class="provenance">generated by <code>' . Markdown::escape($version->provenance['tool'])
                . '</code> from <code>' . Markdown::escape($version->provenance['source']) . '</code></span>';
        return '<p class="file">' . Markdown::escape($version->file) . "{$provenance}</p>";
    }

    /**
     * The bases a head names, each a link (DXNS.md §4b).
     *
     * @param list<array{relation: string, names: list<array{0: string, 1: ?int}>}> $groups
     */
    private function basesHtml(array $groups, Definition $d): string
    {
        if ($groups === []) {
            return '';
        }
        $words = implode(' ', array_map(
            fn (array $group): string => $group['relation'] . ' ' . implode(', ', array_map(
                fn (array $base): string => $this->link($base[1] === null ? $base[0] : "{$base[0]}({$base[1]})", $base[0], $base[1], $d),
                $group['names'],
            )),
            $groups,
        ));
        return "<p class=\"bases\">{$words}</p>\n";
    }

    /**
     * A generic's parameters, each with its bound and default (DXNS.md §5).
     *
     * @param list<array{name: string, bound: ?At, default: ?At}> $params
     */
    private function paramsHtml(array $params, Definition $d): string
    {
        if ($params === []) {
            return '';
        }
        $rows = '';
        foreach ($params as $p) {
            $about = match (true) {
                $p['bound'] === null => 'any type',
                $p['default'] === null => 'within <code>' . $this->typeHtml($p['bound'], $d) . '</code>',
                default => 'within <code>' . $this->typeHtml($p['bound'], $d) . '</code>, default <code>' . $this->typeHtml($p['default'], $d) . '</code>',
            };
            $rows .= '<dt><code>' . Markdown::escape($p['name']) . "</code></dt><dd>{$about}</dd>";
        }
        return "<dl class=\"params\"><p class=\"label\">Parameters</p>{$rows}</dl>\n";
    }

    /**
     * One row of a body: a field, a field left out of the bases, the
     * extras, or the closing.
     */
    private function fieldRow(Field|Row $f, Definition $d): string
    {
        $pos = $d->kind === 'schema' ? '<td class="pos"></td>' : '';
        if ($f instanceof Row) {
            [$class, $field, $type, $notes] = match ($f->kind) {
                'drop' => ['dropped', '<code><s>' . Markdown::escape((string) $f->name) . '</s></code>', '', 'left out of the bases'],
                'closed' => ['closed', '<code>- *</code>', '', 'no entries beyond these'],
                'extra' => ['extras', '<code>* ' . $this->typeHtml($f->key, $d) . '</code>', '<code>' . $this->typeHtml($f->type, $d) . '</code>', 'any other entry with such a key'],
            };
            $doc = $f->doc !== '' ? Markdown::toHtml($f->doc, $this->linker($d->scope)) : '';
            return "<tr class=\"{$class}\">\n"
                . "  {$pos}\n"
                . "  <th scope=\"row\" class=\"field\">{$field}</th>\n"
                . "  <td class=\"type\">{$type}</td>\n"
                . "  <td class=\"notes\">{$notes}{$doc}</td>\n"
                . "</tr>\n";
        }
        $own = $f->annotations['description'] ?? null;
        $notes = self::annotationChips($f->annotations);
        foreach ([is_string($own) ? $own : null, $f->description, $f->doc] as $text) {
            if ($text !== null && $text !== '') {
                $notes .= Markdown::toHtml($text, $this->linker($d->scope));
            }
        }
        $notes .= $this->values($f->annotations, $d) . self::examples($f->annotations) . self::messages($f->annotations) . self::meta($f->annotations);
        $default = $f->default !== null
            ? '<div class="default"><span class="label">default</span> <code>' . $this->typeHtml($f->default, $d) . '</code></div>'
            : '';
        $computed = $f->computed !== null
            ? '<div class="default"><span class="label">computed</span> <code>' . $this->typeHtml($f->computed, $d) . '</code></div>'
            : '';
        return "<tr>\n"
            . '  ' . ($d->kind === 'schema' ? "<td class=\"pos\">{$f->position}</td>" : '') . "\n"
            . '  <th scope="row" class="field"><code>' . self::struck(Markdown::escape($f->name), $f->annotations) . '</code>' . ($f->optional ? '<span class="opt">optional</span>' : '') . "</th>\n"
            . '  <td class="type"><code>' . $this->typeHtml($f->type, $d) . "</code>{$default}{$computed}</td>\n"
            . "  <td class=\"notes\">{$notes}</td>\n"
            . "</tr>\n";
    }

    // ---- type expressions -------------------------------------------------------------

    /**
     * A code span names a definition when the file's own names, or the full
     * names, know it.
     *
     * @param array<string, string> $scope
     * @return \Closure(string): ?string
     */
    private function linker(array $scope): \Closure
    {
        return function (string $code) use ($scope): ?string {
            $target = $this->known[Docs::resolve($code, $scope)] ?? null;
            return $target === null ? null : self::href($target);
        };
    }

    private function typeHtml(?At $at, Definition $d): string
    {
        if ($at === null) {
            return '';
        }
        return trim((string) preg_replace('/ {2,}/', ' ', $this->render($at, $at->node, $d)));
    }

    /**
     * A node as HTML: its tokens as written, a gap between two as one space,
     * a symbol a link where it names a definition, a keyword and a string
     * marked, and a `{:case ...}` a table.
     */
    private function render(At $at, SyntaxNode $node, Definition $d): string
    {
        if (Docs::isSymbol($at, $node)) {
            [$name, $pin] = Docs::pinned($at, $node);
            return $this->link(Docs::text($at, $node), $name, $pin, $d);
        }
        if ($node->kind === 'body' && $node->children === [] && $node->first === $node->last) {
            $type = $at->tokens[$node->first]->type;
            if ($type === 'KEYWORD') {
                return '<span class="kw">' . Markdown::escape(Docs::text($at, $node)) . '</span>';
            }
            if ($type === 'STRING') {
                return '<span class="str">' . Markdown::escape(Docs::text($at, $node)) . '</span>';
            }
        }
        if ($node->kind === 'tuple') {
            $values = array_values(array_filter($node->children, static fn (SyntaxNode $c): bool => $c->kind === 'value'));
            if (count($values) === 3 && Docs::text($at, $values[0]) === ':case') {
                return $this->caseHtml($at, $values[1], $values[2], $d);
            }
            if (count($values) === 3 && Docs::text($at, $values[0]) === ':at') {
                return $this->plain($at, $node, $d, $values[1], $this->atLink($at, $values[1], $values[2], $d));
            }
        }
        return $this->plain($at, $node, $d);
    }

    /**
     * `{:at Name N}`, what `Name(N)` writes (DXNS.md §5c): the name, linking
     * to version N.
     */
    private function atLink(At $at, SyntaxNode $nameValue, SyntaxNode $versionValue, Definition $d): string
    {
        $body = Docs::bodyOf($nameValue);
        $name = $body !== null && Docs::isSymbol($at, $body) ? Docs::pinned($at, $body)[0] : null;
        $written = Docs::text($at, $versionValue);
        $pin = preg_match('/\A[+-]?[0-9]+\z/', $written) === 1 ? (int) $written : null;
        return $this->link(Docs::text($at, $nameValue), $name, $pin, $d);
    }

    /**
     * `$written`, naming `$name` pinned to `$pin` (or null), as a link to that
     * definition -- to that version of it when it has one -- or as a name.
     */
    private function link(string $written, ?string $name, ?int $pin, Definition $d): string
    {
        $target = $name === null ? null : ($this->known[Docs::resolve($name, $d->scope)] ?? null);
        if ($target === null) {
            return '<span class="sym">' . Markdown::escape($written) . '</span>';
        }
        $pinned = $pin !== null && array_filter($target->versions, static fn (Version $v): bool => $v->version === $pin) !== [];
        $href = $pinned ? self::pageOf($target->namespace) . '#' . self::versionAnchor($target, $pin) : self::href($target);
        return '<a class="ref" href="' . $href . '">' . Markdown::escape($written) . '</a>';
    }

    /**
     * A node's tokens and its children in order, each piece the tokens it
     * covers, joined by a space wherever the source has a gap; the child
     * `$special`, when given, as `$specialHtml`.
     */
    private function plain(At $at, SyntaxNode $node, Definition $d, ?SyntaxNode $special = null, string $specialHtml = ''): string
    {
        $pieces = [];
        $i = $node->first;
        foreach ($node->children as $child) {
            for (; $i < $child->first; $i++) {
                $pieces[] = [$i, Markdown::escape(Docs::tokenText($at, $i))];
            }
            $pieces[] = [$child->last, $child === $special ? $specialHtml : $this->render($at, $child, $d)];
            $i = $child->last + 1;
        }
        for (; $i <= $node->last; $i++) {
            $pieces[] = [$i, Markdown::escape(Docs::tokenText($at, $i))];
        }
        $out = '';
        foreach ($pieces as $k => [$last, $html]) {
            if ($k > 0 && self::gap($at, $pieces[$k - 1][0])) {
                $out .= ' ';
            }
            $out .= $html;
        }
        return $out;
    }

    /** Whether there is trivia between the token at `$i` and the next. */
    private static function gap(At $at, int $i): bool
    {
        $next = $at->tokens[$i + 1] ?? null;
        return $next !== null && $next->start > $at->tokens[$i]->end;
    }

    /**
     * `{:case field %{value => type, ...}}`: the type chosen by a field's
     * value, as a table from value to type.
     */
    private function caseHtml(At $at, SyntaxNode $field, SyntaxNode $table, Definition $d): string
    {
        $map = Docs::bodyOf($table);
        $rows = '';
        if ($map !== null && $map->kind === 'map') {
            foreach ($map->children as $entry) {
                if ($entry->kind !== 'entry') {
                    continue;
                }
                $keyNode = null;
                foreach ($entry->children as $c) {
                    if ($c->kind === 'value') {
                        $keyNode = $c;
                        break;
                    }
                }
                $key = $entry->key !== null
                    ? Markdown::escape(Docs::tokenText($at, $entry->key))
                    : ($keyNode !== null ? $this->render($at, $keyNode, $d) : '');
                $value = Docs::valueOf($entry);
                $rows .= '<tr><td>' . trim($key) . '</td><td>' . $this->typeHtml($value === null ? null : $at->at($value), $d) . '</td></tr>';
            }
        }
        return '<span class="case">by <span class="case-field">' . Markdown::escape(Docs::text($at, $field))
            . "</span><span class=\"case-table\"><span class=\"case-rows\"><table><tbody>{$rows}</tbody></table></span></span></span>";
    }
}
