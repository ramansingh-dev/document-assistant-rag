<?php

namespace App\Services\Chunking;

/**
 * Turns visual table rows (from scripts/extract_pdf.py `rows`) into
 * self-describing text lines, so "6.3" is never separated from its row label
 * and column year. Example output line:
 *
 *   EMDE SAR, GDP: 2023: 6.6; 2024: 6.8; 2025e: 7.0; 2026f: 6.3; 2027f: 6.9; 2028f: 7.1;
 *   percentage-point difference from January 2026 projections - 2026f: 0.1; 2027f: 0.4
 */
class TableFacts
{
    /** @var string[] column headers of the table currently being read */
    private array $header = [];

    private string $diffLabel = 'difference from previous projections';

    private string $group = '';

    public function reset(): void
    {
        $this->header = [];
        $this->group = '';
        $this->diffLabel = 'difference from previous projections';
    }

    public function hasHeader(): bool
    {
        return $this->header !== [];
    }

    /**
     * @param  array  $rows  visual rows, top to bottom
     * @return string[] lines
     */
    public function lines(array $rows): array
    {
        $lines = [];
        $preHeader = [];

        foreach ($rows as $row) {
            $cells = array_map(fn ($c) => trim($c['text']), $row['cells']);
            $cells = array_values(array_filter($cells, fn ($c) => $c !== ''));
            if (! $cells) {
                continue;
            }

            if ($this->isHeaderRow($cells)) {
                $this->setHeader($cells, $preHeader);
                $preHeader = [];

                continue;
            }

            if (! $this->hasHeader()) {
                $preHeader[] = implode(' ', $cells);

                continue;
            }

            $numeric = [];
            while ($cells && $this->isNumber(end($cells))) {
                array_unshift($numeric, array_pop($cells));
            }
            $label = $this->label(implode(' ', $cells));

            if (! $numeric) {
                // A row with only a label ("Memo items: GDP", "Calendar-year basis").
                if ($label !== '' && mb_strlen($label) < 80) {
                    $this->group = $label;
                }

                continue;
            }
            if ($label === '') {
                continue;
            }

            $lines[] = $this->format($label, $numeric);
        }

        return $lines;
    }

    private function isHeaderRow(array $cells): bool
    {
        $years = array_filter($cells, fn ($c) => $this->isPeriod($c));

        return count($years) >= 4 && count($years) >= count($cells) - 1;
    }

    private function isPeriod(string $cell): bool
    {
        return (bool) preg_match('/^(?:19|20)\d{2}(?:\/\d{2,4})?[ef]?$/', $cell);
    }

    private function isNumber(string $cell): bool
    {
        return (bool) preg_match('/^[-\x{2212}\x{2013}]?\d[\d,]*(?:\.\d+)?$|^\.\.$/u', $cell);
    }

    private function setHeader(array $cells, array $preHeader): void
    {
        $this->header = array_values(array_filter($cells, fn ($c) => $this->isPeriod($c)));

        if (preg_match(
            '/(?:percentage[- ]point\s+)?differences?\s+from\s+\w+\s+(?:20\d{2}\s+)?projections?/i',
            // Drop "(Real GDP growth ...)" asides that sit between the pieces of the phrase.
            preg_replace('/\([^)]*\)/', ' ', implode(' ', $preHeader)),
            $m
        )) {
            $this->diffLabel = 'percentage-point '.preg_replace('/\s+/', ' ', strtolower(
                preg_replace('/^percentage[- ]point\s+/i', '', $m[0])
            ));
        }

        // A leading non-period cell on the header row names the basis ("Fiscal-year basis").
        $lead = array_values(array_filter($cells, fn ($c) => ! $this->isPeriod($c)));
        if ($lead) {
            $this->group = $this->label($lead[0]);
        }
    }

    private function label(string $text): string
    {
        // Trailing footnote digits glued to a label ("Iraq 1", "GDP 2").
        $text = preg_replace('/\s+\d$/', '', trim($text));

        return trim($text);
    }

    private function format(string $label, array $values): string
    {
        $primary = [];
        $diff = [];
        $seen = [];

        foreach ($values as $i => $value) {
            $key = $this->header[$i] ?? null;
            if ($key === null) {
                $primary[] = $value;

                continue;
            }
            if (isset($seen[$key])) {
                $diff[] = "{$key}: {$value}";
            } else {
                $seen[$key] = true;
                $primary[] = "{$key}: {$value}";
            }
        }

        $line = ($this->group !== '' ? "[{$this->group}] " : '').$label.': '.implode('; ', $primary);
        if ($diff) {
            $line .= '; '.$this->diffLabel.' - '.implode('; ', $diff);
        }

        return $line;
    }
}
