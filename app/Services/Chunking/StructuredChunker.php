<?php

namespace App\Services\Chunking;

/**
 * Use case 2 - long report organised in chapters, sections and boxes.
 *
 * Structure comes from the PDF bookmarks (corrected against the page headings,
 * because a bookmark can point one page early) plus the running "BOX x.y"
 * headers. Every page belongs to exactly one section path such as
 * "Ch.2 Regional Outlooks > South Asia". Text is chunked as small children
 * (embedded, carrying the breadcrumb) that point at larger section-part
 * parents (given to the model for context). Tables become their own chunks
 * with row/column labels spelled out.
 */
class StructuredChunker implements ChunkerInterface
{
    private const SEP = ' > ';

    /** "TABLE 2.9 ...", "FIGURE B1.1.1 ...", "TABLE A.1 ..." (upper case, with a number). */
    private const CAPTION = '/^(TABLE|FIGURE)\s+[A-Z]{0,2}\.?\d[\dA-Z.]*/';

    private TableFacts $tables;

    /** @var Chunk[] */
    private array $chunks = [];

    private int $sequence = 0;

    /** @var array<string,true> */
    private array $vocabulary = [];

    public function __construct(
        private int $childTarget = 0,
        private int $parentMax = 0,
    ) {
        $this->childTarget = $childTarget ?: (int) config('rag.chunking.child_target');
        $this->parentMax = $parentMax ?: (int) config('rag.chunking.parent_max');
    }

    public function chunk(array $extract, string $documentName): array
    {
        $this->chunks = [];
        $this->sequence = 0;
        $this->tables = new TableFacts;

        $entries = $this->sections($extract);
        $firstContent = $this->firstContentPage($entries);
        $this->vocabulary = TextCleaner::vocabulary(
            array_merge(...array_map(fn ($p) => array_column($p['blocks'], 'text'), $extract['pages']))
        );

        $sectionPath = null;
        $buffer = [];
        $heading = '';
        $lastCaption = null;

        foreach ($extract['pages'] as $page) {
            if ($page['page'] < $firstContent) {
                continue;
            }

            [$path, $skip] = $this->pageSection($page, $entries);
            if ($skip) {
                continue;
            }

            if ($path !== $sectionPath) {
                $this->flushSection($sectionPath, $buffer);
                $buffer = [];
                $sectionPath = $path;
                $heading = '';
                $this->tables->reset();
                $lastCaption = null;
            }

            $leaf = $this->leaf($path);
            $captions = [];
            foreach ($page['blocks'] as $block) {
                if ($block['zone'] === 'body' && preg_match(self::CAPTION, $block['text'])) {
                    $captions[] = $block;
                }
            }
            $sourceY = $this->sourceY($page);

            foreach ($page['blocks'] as $block) {
                if ($block['zone'] !== 'body') {
                    continue;
                }
                $text = $block['text'];
                $size = $block['size'];

                if (preg_match(self::CAPTION, $text)) {
                    if (stripos($text, 'TABLE') === 0) {
                        $this->tableChunk($page, $block, $captions, $sourceY, $path);
                        $lastCaption = $this->oneLine($text);
                    }

                    continue;
                }

                if (preg_match('/^(Source|Note|Notes)\s*:/i', $text) || preg_match('/^CHAPTER\s+\d+/i', $text)) {
                    continue;
                }

                $oneLine = $this->oneLine($text);

                if ($size >= 13) {
                    // Page titles / decorations: the section title itself is not a sub-heading.
                    if ($this->norm($oneLine) !== $this->norm($leaf) && ! preg_match('/^[A-Z\s:\-]{4,}$/', $oneLine)) {
                        $heading = $oneLine;
                    }

                    continue;
                }

                if ($size < 7.9 || ($size < 10.5 && mb_strlen($text) < 150)) {
                    continue; // chart labels, axis text, footnote fragments
                }

                if ($this->looksLikeHeading($block, $oneLine)) {
                    $heading = $oneLine;

                    continue;
                }

                $prose = TextCleaner::prose($text, $this->vocabulary);
                if (mb_strlen($prose) < 25 || TextCleaner::isAcronymList($prose)) {
                    continue;
                }

                $buffer[] = [
                    'text' => $prose,
                    'page' => $page['page'],
                    'printed' => $page['printed'],
                    'heading' => $heading,
                ];
            }

            // Table continued on a following page without its own caption.
            if (! $captions && $lastCaption && $this->tables->hasHeader()) {
                $this->continuedTable($page, $sourceY, $path, $lastCaption);
            } elseif ($captions === []) {
                $lastCaption = null;
            }
        }

        $this->flushSection($sectionPath, $buffer);

        return $this->chunks;
    }

    // ---------------------------------------------------------------- sections

    /**
     * @return array<int, array{title:string,path:string,page:int,type:string,number:?string,skip:bool}>
     */
    public function sections(array $extract): array
    {
        $entries = [];
        $chapterLabel = null;

        foreach ($extract['toc'] as $item) {
            $title = trim(preg_replace('/\s+/', ' ', $item['title']));
            $type = 'section';
            $number = null;
            $skip = (bool) preg_match('/^(summary of )?contents$|^abbreviations$|^acknowledg/i', $title);

            if (preg_match('/^Ch\.\s*(\d+)\s+(.*)$/i', $title, $m)) {
                $type = 'chapter';
                $number = $m[1];
                $chapterLabel = "Ch.{$m[1]} {$m[2]}";
                $path = $chapterLabel;
                $title = $m[2];
            } elseif (preg_match('/^Box\s+(\d+\.\d+)\s+(.*)$/i', $title, $m)) {
                $type = 'box';
                $number = $m[1];
                $path = ($chapterLabel ? $chapterLabel.self::SEP : '')."Box {$m[1]} {$m[2]}";
            } elseif (preg_match('/annex|appendix|statistical/i', $title) || $chapterLabel === null) {
                $chapterLabel = null;
                $path = $title;
            } else {
                $path = $chapterLabel.self::SEP.$title;
            }

            $entries[] = [
                'title' => $title,
                'path' => $path,
                'page' => $this->correctedPage($extract, $title, $item['page'], $type),
                'type' => $type,
                'number' => $number,
                'skip' => $skip,
            ];
        }

        usort($entries, fn ($a, $b) => $a['page'] <=> $b['page']);

        return $entries;
    }

    /** A bookmark can point one page early; trust the page where the heading really is. */
    private function correctedPage(array $extract, string $title, int $page, string $type): int
    {
        if ($type !== 'section') {
            return $page;
        }
        $target = $this->norm($title);
        for ($p = max(1, $page - 1); $p <= min(count($extract['pages']), $page + 2); $p++) {
            foreach ($extract['pages'][$p - 1]['blocks'] as $block) {
                $text = $this->norm($this->oneLine($block['text']));
                if ($block['zone'] === 'body' && ($block['size'] >= 13 || $block['bold']) && $text === $target) {
                    return $p;
                }
            }
        }

        return $page;
    }

    private function firstContentPage(array $entries): int
    {
        foreach ($entries as $entry) {
            if (! $entry['skip'] && stripos($entry['title'], 'Executive Summary') !== false) {
                return $entry['page'];
            }
        }
        foreach ($entries as $entry) {
            if ($entry['type'] === 'chapter') {
                return $entry['page'];
            }
        }

        return 1;
    }

    /** @return array{0:string,1:bool} section path and whether the page is to be skipped */
    private function pageSection(array $page, array $entries): array
    {
        $base = null;
        foreach ($entries as $entry) {
            if ($entry['page'] <= $page['page'] && $entry['type'] !== 'box') {
                $base = $entry;
            }
        }
        if (! $base) {
            return ['Front matter', true];
        }

        // Box pages announce themselves in their first body block ("BOX 1.1 ... (continued)").
        foreach ($page['blocks'] as $block) {
            if ($block['zone'] !== 'body') {
                continue;
            }
            if (preg_match('/^BOX\s+(\d+\.\d+)/i', $block['text'], $m)) {
                foreach ($entries as $entry) {
                    if ($entry['type'] === 'box' && $entry['number'] === $m[1]) {
                        return [$entry['path'], $entry['skip']];
                    }
                }
            }
            break;
        }

        return [$base['path'], $base['skip']];
    }

    // ------------------------------------------------------------------ text

    private function flushSection(?string $path, array $buffer): void
    {
        if ($path === null || ! $buffer) {
            return;
        }

        // 1. Group paragraphs into parent parts of at most parentMax characters.
        $parts = [];
        $part = [];
        $size = 0;
        foreach ($buffer as $paragraph) {
            if ($part && $size + mb_strlen($paragraph['text']) > $this->parentMax) {
                $parts[] = $part;
                $part = [];
                $size = 0;
            }
            $part[] = $paragraph;
            $size += mb_strlen($paragraph['text']);
        }
        if ($part) {
            $parts[] = $part;
        }

        foreach ($parts as $paragraphs) {
            $this->sequence++;
            $key = 'part'.$this->sequence;
            $pages = array_column($paragraphs, 'page');

            $this->chunks[] = new Chunk(
                kind: 'section',
                content: implode("\n\n", array_column($paragraphs, 'text')),
                searchText: $path,
                pageStart: min($pages),
                pageEnd: max($pages),
                sectionPath: $path,
                key: $key,
                isParent: true,
            );

            $this->children($path, $key, $paragraphs);
        }
    }

    private function children(string $path, string $parentKey, array $paragraphs): void
    {
        $current = [];
        $size = 0;
        $currentHeading = null;

        $flush = function () use (&$current, &$size, &$currentHeading, $path, $parentKey) {
            if (! $current) {
                return;
            }
            $text = implode(' ', array_column($current, 0));
            $pages = array_column($current, 1);
            $start = min($pages);
            $end = max($pages);
            $crumb = $path.($currentHeading ? self::SEP.$currentHeading : '');

            $this->chunks[] = new Chunk(
                kind: 'paragraph',
                content: $text,
                searchText: $crumb."\n".$text,
                pageStart: $start,
                pageEnd: $end,
                printedPage: $start === $end ? $current[0][2] : null,
                sectionPath: $crumb,
                parentKey: $parentKey,
            );
            $current = [];
            $size = 0;
        };

        foreach ($paragraphs as $paragraph) {
            if ($currentHeading !== null && $paragraph['heading'] !== $currentHeading) {
                $flush();
            }
            $currentHeading = $paragraph['heading'];

            foreach (TextCleaner::sentences($paragraph['text'], $this->childTarget) as $sentence) {
                if ($current && $size + mb_strlen($sentence) + 1 > $this->childTarget) {
                    $flush();
                }
                $current[] = [$sentence, $paragraph['page'], $paragraph['printed']];
                $size += mb_strlen($sentence) + 1;
            }
        }
        $flush();
    }

    // ---------------------------------------------------------------- tables

    private function tableChunk(array $page, array $caption, array $captions, ?float $sourceY, string $path): void
    {
        $this->tables->reset();
        $top = $caption['y0'] - 2;
        $bottom = $sourceY ?? 1e9;
        foreach ($captions as $other) {
            if ($other['y0'] > $caption['y0'] + 5) {
                $bottom = min($bottom, $other['y0'] - 2);
            }
        }
        // Rows after the table's own "Source:" line belong to a later table.
        foreach ($page['rows'] as $row) {
            if ($row['y'] > $top && preg_match('/^Source\s*:/i', $row['text'])) {
                $bottom = min($bottom, $row['y']);
                break;
            }
        }

        $rows = array_values(array_filter(
            $page['rows'],
            fn ($r) => $r['y'] >= $top && $r['y'] < $bottom,
        ));

        $this->emitTable($page, $path, $this->oneLine($caption['text']), $this->tables->lines($rows));
    }

    private function continuedTable(array $page, ?float $sourceY, string $path, string $caption): void
    {
        $rows = array_values(array_filter(
            $page['rows'],
            fn ($r) => $r['y'] > 60 && ($sourceY === null || $r['y'] < $sourceY),
        ));
        $lines = $this->tables->lines($rows);
        if (count($lines) >= 3) {
            $this->emitTable($page, $path, $caption.' (continued)', $lines);
        }
    }

    private function emitTable(array $page, string $path, string $caption, array $lines): void
    {
        if (! $lines) {
            return;
        }

        // Keep each chunk small enough to retrieve precisely; repeat the caption.
        $group = [];
        $size = 0;
        $emit = function () use (&$group, &$size, $page, $path, $caption) {
            if (! $group) {
                return;
            }
            $body = $caption."\n".implode("\n", $group);
            $this->chunks[] = new Chunk(
                kind: 'table',
                content: $body,
                searchText: $path."\n".$body,
                pageStart: $page['page'],
                pageEnd: $page['page'],
                printedPage: $page['printed'],
                sectionPath: $path,
            );
            $group = [];
            $size = 0;
        };

        foreach ($lines as $line) {
            if ($group && $size + mb_strlen($line) > 1100) {
                $emit();
            }
            $group[] = $line;
            $size += mb_strlen($line) + 1;
        }
        $emit();
    }

    private function sourceY(array $page): ?float
    {
        foreach ($page['rows'] as $row) {
            if (preg_match('/^Source\s*:/i', $row['text'])) {
                return $row['y'];
            }
        }

        return null;
    }

    // --------------------------------------------------------------- helpers

    /** A short, unpunctuated, isolated line in body type is a sub-heading ("Outlook", "Risks"). */
    private function looksLikeHeading(array $block, string $oneLine): bool
    {
        if (str_contains($block['text'], "\n") && mb_strlen($oneLine) > 60) {
            return false;
        }

        return mb_strlen($oneLine) <= 60
            && ! str_contains($block['text'], "\n")
            && preg_match('/^[A-Z][^.!?;]*[^.,;:!?]$/u', $oneLine)
            && str_word_count($oneLine) <= 9
            && $block['size'] >= 10.5;
    }

    private function leaf(string $path): string
    {
        $parts = explode(self::SEP, $path);

        return end($parts);
    }

    private function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function norm(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }
}
