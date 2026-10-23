<?php

namespace App\Services\Chunking;

/**
 * Use case 3 - short visual document whose facts live in charts, small tables,
 * icon call-outs and footnotes rather than in running paragraphs.
 *
 * No vision model is used. The PDF text layer already contains the chart
 * labels and table cells, but block extraction scrambles them, so this chunker
 * rebuilds them geometrically from word rows:
 *   - label/value tables   ("Total product footprint | 346 kg CO2e")
 *   - chart bar labels     (value cell + the label cells stacked under it)
 *   - big-number call-outs ("100%" + the sentence beneath it)
 *   - footnotes            (superscript markers resolved against the Endnotes)
 * and keeps ordinary paragraphs, each carrying the footnotes that qualify them.
 */
class VisualChunker implements ChunkerInterface
{
    /** Minimum font size of a big-number call-out such as "100%". */
    private const CALLOUT_SIZE = 15;

    private const BULLET_PATTERN = '/^[\x{2022}\x{FFFD}\x{25CF}\x{25AA}\x{00B7}]\s*/u';

    public function __construct(private ?int $paragraphTarget = null)
    {
        $this->paragraphTarget ??= (int) config('rag.chunking.visual_paragraph_target');
    }

    public function chunk(array $extract, string $documentName): array
    {
        $notePage = $this->endnotePageIndex($extract);
        $notes = $this->endnotes($extract, $notePage);

        $chunks = [];
        foreach ($extract['pages'] as $page) {
            // Endnote pages only contribute their notes and any data table printed among them.
            if ($notePage !== null && $page['page'] >= $notePage) {
                foreach ($this->gridTables($page) as $text) {
                    $chunks[] = $this->make('table', $text, $page, $documentName, $notes);
                }

                continue;
            }

            $table = $this->labelValueTables($page);
            foreach ($table['chunks'] as $text) {
                $chunks[] = $this->make('table', $text, $page, $documentName, $notes);
            }
            foreach ($this->gridTables($page) as $text) {
                $chunks[] = $this->make('table', $text, $page, $documentName, $notes);
            }

            $charts = $this->charts($page, $table['consumedY']);
            foreach ($charts as $text) {
                $chunks[] = $this->make('chart', $text, $page, $documentName, $notes);
            }
            foreach ($this->callouts($page) as $text) {
                $chunks[] = $this->make('callout', $text, $page, $documentName, $notes);
            }
            foreach ($this->paragraphs($page, $charts !== [], $table['consumedY']) as $text) {
                $chunks[] = $this->make('paragraph', $text, $page, $documentName, $notes);
            }
        }

        foreach ($notes as $number => $note) {
            $chunks[] = new Chunk(
                kind: 'footnote',
                content: "Footnote {$number}: {$note['text']}",
                searchText: "{$documentName} - endnote {$number}: {$note['text']}",
                pageStart: $note['page'],
                pageEnd: $note['page'],
            );
        }

        return array_values(array_filter($chunks));
    }

    // ----------------------------------------------------------------- notes

    private function endnotePageIndex(array $extract): ?int
    {
        foreach ($extract['pages'] as $page) {
            foreach ($page['blocks'] as $block) {
                if ($block['zone'] === 'body' && preg_match('/^Endnotes\b/i', $block['text'])) {
                    return $page['page'];
                }
            }
        }

        return null;
    }

    /** @return array<int, array{text:string,page:int}> */
    private function endnotes(array $extract, ?int $firstPage): array
    {
        if ($firstPage === null) {
            return [];
        }

        // Each note starts on a visual row as "N text" in the note column; the
        // following rows in that column continue it. Notes are numbered 1..n in order.
        $notes = [];
        $current = null;

        foreach ($extract['pages'] as $page) {
            if ($page['page'] < $firstPage) {
                continue;
            }
            foreach ($page['rows'] as $row) {
                $first = $row['cells'][0] ?? null;
                // Skip the page title, footers, and table rows printed between notes.
                if (! $first || $row['y'] > 740 || $row['x0'] < 200 || $row['x0'] > 300 || count($row['cells']) > 1) {
                    continue;
                }

                $expected = $current === null ? 1 : $current + 1;
                if (preg_match('/^'.$expected.'\s+(\S.*)$/u', $first['text'], $m) && $first['x0'] < 214.5) {
                    $current = $expected;
                    $notes[$current] = ['text' => $m[1], 'page' => $page['page']];

                    continue;
                }
                if ($current !== null && $first['x0'] >= 214.5) {
                    $notes[$current]['text'] .= ' '.$first['text'];
                }
            }
        }

        return array_map(fn ($n) => ['text' => trim(preg_replace('/\s+/', ' ', $n['text'])), 'page' => $n['page']], $notes);
    }

    private function make(string $kind, string $text, array $page, string $documentName, array $notes): ?Chunk
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        [$clean, $numbers] = $this->resolveMarkers($text);
        $suffix = '';
        foreach ($numbers as $number) {
            if (isset($notes[$number])) {
                $suffix .= "\nFootnote {$number}: ".mb_strimwidth($notes[$number]['text'], 0, 420, '...');
            }
        }

        $content = $clean.$suffix;

        return new Chunk(
            kind: $kind,
            content: $content,
            searchText: "{$documentName} - page {$page['page']} - {$kind}\n{$content}",
            pageStart: $page['page'],
            pageEnd: $page['page'],
        );
    }

    /** @return array{0:string,1:int[]} text without "[^18]" markers, and the note numbers found */
    private function resolveMarkers(string $text): array
    {
        $numbers = [];
        $clean = preg_replace_callback('/\s*\[\^([\d,]+)\]/', function ($m) use (&$numbers) {
            foreach (explode(',', $m[1]) as $n) {
                $numbers[(int) $n] = (int) $n;
            }

            return ' ';
        }, $text);
        $clean = preg_replace('/\s+([.,;:])/', '$1', preg_replace('/\s+/', ' ', $clean));

        return [trim($clean), array_values($numbers)];
    }

    // ----------------------------------------------------------- paragraphs

    /** @return string[] */
    private function paragraphs(array $page, bool $hasChart = false, array $tableRowsY = []): array
    {
        $out = [];
        $shortBlocks = [];
        $minimum = $hasChart ? 60 : 25;

        // Call-out descriptions are emitted with their number, not again as paragraphs.
        $described = array_map(
            fn ($pair) => $pair['desc']['x0'].'|'.$pair['desc']['y0'],
            $this->calloutPairs($page),
        );

        // Blocks that are cells of an extracted table are already in the table chunk.
        $blocks = array_values(array_filter($page['blocks'], function ($b) use ($tableRowsY) {
            $center = ($b['y0'] + $b['y1']) / 2;
            foreach ($tableRowsY as $y) {
                if (abs($y - $center) <= 3) {
                    return false;
                }
            }

            return true;
        }));

        foreach ($this->mergeFragments($blocks, $described) as $block) {
            $text = TextCleaner::prose($block['text']);
            $plain = TextCleaner::stripFootnoteMarkers($text);

            $keep = mb_strlen($plain) >= $minimum || (! $hasChart && preg_match('/\d/', $plain));
            if (! $keep) {
                if (mb_strlen($plain) >= 3) {
                    $shortBlocks[] = $plain;
                }

                continue;
            }

            $current = '';
            foreach (TextCleaner::sentences($text, $this->paragraphTarget) as $sentence) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $this->paragraphTarget) {
                    $out[] = $current;
                    $current = '';
                }
                $current = trim($current.' '.$sentence);
            }
            if ($current !== '') {
                $out[] = $current;
            }
        }

        // Small labels (e.g. "Arsenic-free glass", "Mercury-free") are only meaningful together.
        if (! $hasChart && count($shortBlocks) >= 2) {
            $out[] = 'Labels on this page: '.implode('; ', array_slice($shortBlocks, 0, 14));
        }

        return $out;
    }

    /**
     * The PDF stores many paragraphs line by line. Join blocks that sit in the
     * same column directly under each other; drop footers and big-number call-outs.
     */
    private function mergeFragments(array $blocks, array $described = []): array
    {
        $blocks = array_values(array_filter($blocks, function ($b) use ($described) {
            return $b['zone'] === 'body'
                && ! $this->isCalloutNumber($b)
                && ! in_array($b['x0'].'|'.$b['y0'], $described, true)
                && ! preg_match('/^iMac\s*\|\s*Product Environmental Report/i', $b['text']);
        }));

        usort($blocks, fn ($a, $b) => [round($a['x0'] / 10), $a['y0']] <=> [round($b['x0'] / 10), $b['y0']]);

        $merged = [];
        foreach ($blocks as $block) {
            $last = $merged ? count($merged) - 1 : null;
            if ($last !== null
                && abs($merged[$last]['x0'] - $block['x0']) <= 8
                && $block['y0'] - $merged[$last]['y1'] <= 5
                && $block['y0'] - $merged[$last]['y1'] >= -2
                && abs($merged[$last]['size'] - $block['size']) <= 0.6) {
                $merged[$last]['text'] .= "\n".$block['text'];
                $merged[$last]['y1'] = $block['y1'];

                continue;
            }
            $merged[] = $block;
        }

        return $merged;
    }

    private function isCalloutNumber(array $block): bool
    {
        return $block['size'] >= self::CALLOUT_SIZE
            && (bool) preg_match('/^[<>]?\s*\d[\d.,]*\s*(%|kg|W)?$/', trim($block['text']));
    }

    // ------------------------------------------------------------ call-outs

    /** @return string[] */
    private function callouts(array $page): array
    {
        return array_map(
            fn ($pair) => trim($pair['big']['text']).' '.TextCleaner::prose($pair['desc']['text']),
            $this->calloutPairs($page),
        );
    }

    /** @return array<int, array{big:array,desc:array}> */
    private function calloutPairs(array $page): array
    {
        $out = [];
        $blocks = array_values(array_filter($page['blocks'], fn ($b) => $b['zone'] === 'body'));

        foreach ($blocks as $big) {
            if (! $this->isCalloutNumber($big)) {
                continue;
            }

            $best = null;
            foreach ($blocks as $candidate) {
                if ($candidate === $big || $this->isCalloutNumber($candidate)) {
                    continue;
                }
                $below = $candidate['y0'] - $big['y1'];
                $overlap = min($big['x1'], $candidate['x1']) - max($big['x0'], $candidate['x0']);
                if ($below >= -3 && $below <= 90 && ($overlap > 0 || abs($candidate['x0'] - $big['x0']) < 40)
                    && ($best === null || $below < $best['below'])) {
                    $best = ['block' => $candidate, 'below' => $below];
                }
            }

            if ($best) {
                $out[] = ['big' => $big, 'desc' => $best['block']];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ grid tables

    /**
     * Multi-column tables ("Mode | 100V | 115V | 230V"): a header row followed by
     * rows with the same number of cells. Each row becomes "Row: col: value; ...".
     *
     * @return string[]
     */
    private function gridTables(array $page): array
    {
        $rows = array_values(array_filter($page['rows'], fn ($r) => $r['y'] < 740));
        $out = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $header = array_map(fn ($c) => $c['text'], $rows[$i]['cells']);
            $width = count($header);
            if ($width < 3 || array_filter($header, fn ($h) => preg_match('/^[<>]?\d[\d.,]*\s*(W|%|kg)?$/', $h))) {
                continue;
            }

            $lines = [];
            $lastY = $rows[$i]['y'];
            for ($j = $i + 1; $j < $count; $j++) {
                $cells = array_map(fn ($c) => $this->normalise($c['text']), $rows[$j]['cells']);
                if (count($cells) !== $width || $rows[$j]['y'] - $lastY > 30
                    || ! preg_match('/\d/', implode(' ', array_slice($cells, 1)))) {
                    break;
                }
                $pairs = [];
                foreach (array_slice($cells, 1) as $k => $value) {
                    $pairs[] = $header[$k + 1].': '.$value;
                }
                $label = trim(preg_replace('/(?<=[a-z\)])\d{1,2}$/', '', $cells[0]));
                $lines[] = $label.' - '.implode('; ', $pairs);
                $lastY = $rows[$j]['y'];
            }

            if (count($lines) >= 2) {
                $title = $this->tableTitle($rows, $i);
                $out[] = 'Table (page '.$page['page'].')'.($title ? ': '.$title : '')
                    .' - columns: '.implode(', ', array_slice($header, 1))."\n".implode("\n", $lines);
                $i = $j - 1;
            }
        }

        return $out;
    }

    /** The single-cell row just above a grid table is its title. */
    private function tableTitle(array $rows, int $headerIndex): ?string
    {
        for ($k = $headerIndex - 1; $k >= max(0, $headerIndex - 2); $k--) {
            if (count($rows[$k]['cells']) === 1 && $rows[$k]['y'] > $rows[$headerIndex]['y'] - 40) {
                $text = $rows[$k]['text'];
                if (mb_strlen($text) < 80 && ! preg_match('/[.]$/', $text)) {
                    return $text;
                }
            }
        }

        return null;
    }

    // --------------------------------------------------------------- charts

    /**
     * Bar/value labels: a short measurement cell ("445 kg") plus the label
     * cells stacked beneath it in the same column band.
     *
     * @param  float[]  $consumedY  y positions already used by a table
     * @return string[]
     */
    private function charts(array $page, array $consumedY): array
    {
        $rows = $page['rows'];
        $values = [];

        foreach ($rows as $ri => $row) {
            if ($this->consumed($row['y'], $consumedY)) {
                continue;
            }
            foreach ($row['cells'] as $cell) {
                $text = $this->normalise($cell['text']);
                if (mb_strlen($text) <= 14 && preg_match('/^-?\s*\d[\d,.]*\s*(kg|g|kWh|Wh|W)\b/', $text)) {
                    $values[] = [$ri, $text, $cell];
                }
            }
        }
        if (count($values) < 2) {
            return [];
        }

        $parts = [];
        foreach ($values as [$ri, $text, $cell]) {
            $center = ($cell['x0'] + $cell['x1']) / 2;
            $label = [];
            $lastY = $rows[$ri]['y'];

            for ($next = $ri + 1; $next < count($rows) && count($label) < 6; $next++) {
                $row = $rows[$next];
                if ($row['y'] - $lastY > 26) {
                    break;
                }
                $match = null;
                foreach ($row['cells'] as $c) {
                    if (abs((($c['x0'] + $c['x1']) / 2) - $center) < 50 && mb_strlen($c['text']) < 40) {
                        $match = $c;
                        break;
                    }
                }
                if ($match) {
                    $label[] = preg_replace('/(?<=[a-z])\d{1,2}$/', '', $match['text']);
                    $lastY = $row['y'];
                }
            }

            $parts[] = $text.($label ? ' - '.implode(' ', $label) : '');
        }

        return [$this->chartTitle($page)."\nValues shown in the chart: ".implode('; ', $parts)];
    }

    private function chartTitle(array $page): string
    {
        foreach ($page['blocks'] as $block) {
            if ($block['zone'] === 'body' && mb_strlen($block['text']) < 60 && $block['size'] >= 12
                && preg_match('/[A-Za-z]{4}/', $block['text']) && ! preg_match('/\d\s*(kg|%)/', $block['text'])) {
                return 'Chart under heading "'.TextCleaner::prose($block['text']).'" (page '.$page['page'].')';
            }
        }

        return 'Chart (page '.$page['page'].')';
    }

    // ---------------------------------------------------------------- tables

    /** @return array{chunks:string[],consumedY:float[]} */
    private function labelValueTables(array $page): array
    {
        $rows = $page['rows'];
        $runs = [];
        $run = [];
        $lastY = null;

        $valueLike = fn (string $t) => (bool) preg_match(
            '/^[<>]?\s*\d[\d,.]*\s*(%|kg\s*CO2e|kg|W|kWh)?(\s*CO2e)?$/',
            $this->normalise($t)
        );

        foreach ($rows as $row) {
            $cells = $row['cells'];
            $isPair = count($cells) === 2
                && ! $valueLike($cells[0]['text'])
                && $valueLike($cells[1]['text'])
                && mb_strlen($cells[0]['text']) < 90;

            if ($isPair && ($lastY === null || $row['y'] - $lastY < 30)) {
                $run[] = $row;
                $lastY = $row['y'];

                continue;
            }
            if ($run) {
                $runs[] = $run;
            }
            $run = $isPair ? [$row] : [];
            $lastY = $isPair ? $row['y'] : null;
        }
        if ($run) {
            $runs[] = $run;
        }

        $chunks = [];
        $consumedY = [];
        foreach ($runs as $run) {
            if (count($run) < 3) {
                continue;
            }
            $header = $this->tableHeader($rows, $run[0]['y']);

            $lines = [];
            $parent = null;
            foreach ($run as $row) {
                $label = trim($row['cells'][0]['text']);
                $value = $this->normalise($row['cells'][1]['text']);
                $isChild = (bool) preg_match(self::BULLET_PATTERN, $label);
                $label = trim(preg_replace(self::BULLET_PATTERN, '', $label));
                $label = preg_replace('/(?<=[a-z\)])\d{1,2}$/', '', $label);

                if ($isChild && $parent !== null) {
                    $lines[] = "{$parent} - {$label}: {$value}";
                } else {
                    $parent = $label;
                    $lines[] = "{$label}: {$value}";
                }
                $consumedY[] = $row['y'];
            }

            $title = 'Table (page '.$page['page'].')'.($header ? ': '.implode(' - ', $header) : '');
            $chunks[] = $title."\n".implode("\n", $lines);
        }

        return ['chunks' => $chunks, 'consumedY' => $consumedY];
    }

    /** Header cells above the table, stacked per column and read left to right. */
    private function tableHeader(array $rows, float $firstY): array
    {
        $columns = [];
        foreach ($rows as $row) {
            if ($row['y'] >= $firstY - 1 || $row['y'] <= $firstY - 45) {
                continue;
            }
            foreach ($row['cells'] as $cell) {
                $columns[(int) round($cell['x0'] / 40)][] = $cell['text'];
            }
        }
        ksort($columns);

        return array_map(fn ($texts) => implode(' ', $texts), array_values($columns));
    }

    private function consumed(float $y, array $consumedY): bool
    {
        foreach ($consumedY as $c) {
            if (abs($c - $y) < 1) {
                return true;
            }
        }

        return false;
    }

    /** The chart font draws minus as an en dash / replacement glyph; make it a real minus. */
    private function normalise(string $text): string
    {
        return trim(preg_replace('/[\x{2013}\x{2212}\x{FFFD}](?=\s*\d)/u', '-', $text));
    }
}
