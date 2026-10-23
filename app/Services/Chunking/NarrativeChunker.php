<?php

namespace App\Services\Chunking;

/**
 * Use case 1 - plain narrative report.
 *
 * Page text (two-column order, running headers/footers dropped) is split into
 * sentences and packed into ~target-character chunks that carry their page
 * range. The last sentence is repeated at the start of the next chunk when it
 * is short enough, so a fact is never cut off from its sentence.
 */
class NarrativeChunker implements ChunkerInterface
{
    public function __construct(
        private int $target = 0,
        private int $overlap = 0,
    ) {
        $this->target = $target ?: (int) config('rag.chunking.narrative_target');
        $this->overlap = $overlap ?: (int) config('rag.chunking.narrative_overlap');
    }

    public function chunk(array $extract, string $documentName): array
    {
        $chunks = [];
        /** @var array<int, array{0:string,1:int,2:?string}> $current */
        $current = [];
        $size = 0;

        $flush = function () use (&$chunks, &$current, $documentName) {
            if (! $current) {
                return;
            }
            $text = implode(' ', array_column($current, 0));
            $pages = array_column($current, 1);
            $first = $current[array_key_first($current)];
            $start = min($pages);
            $end = max($pages);

            $chunks[] = new Chunk(
                kind: 'paragraph',
                content: $text,
                searchText: $text,
                pageStart: $start,
                pageEnd: $end,
                printedPage: $start === $end ? $first[2] : null,
            );
        };

        foreach ($extract['pages'] as $page) {
            $blocks = array_filter($page['blocks'], fn ($b) => $b['zone'] === 'body');
            $text = TextCleaner::prose(implode("\n", array_column($blocks, 'text')));
            if ($text === '') {
                continue;
            }

            foreach (TextCleaner::sentences($text, $this->target) as $sentence) {
                $length = mb_strlen($sentence);

                if ($current && $size + $length + 1 > $this->target) {
                    $flush();
                    $last = end($current);
                    $current = ($last && mb_strlen($last[0]) <= $this->overlap) ? [$last] : [];
                    $size = array_sum(array_map(fn ($i) => mb_strlen($i[0]) + 1, $current));
                }

                $current[] = [$sentence, $page['page'], $page['printed']];
                $size += $length + 1;
            }
        }

        $flush();

        return $chunks;
    }
}
