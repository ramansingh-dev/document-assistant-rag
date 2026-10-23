<?php

namespace App\Services\Retrieval;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid search over ONE document's chunks: dense cosine similarity (computed
 * in PHP, no external vector DB) + BM25 keyword search, merged with
 * Reciprocal Rank Fusion. Optional section filter from QuestionRouter.
 */
class VectorSearchService
{
    /** @var array<int, array{rows: array<int,object>, vectors: array<int,float[]>, norms: array<int,float>, bm25: Bm25}> */
    private array $cache = [];

    /**
     * @param  float[]  $queryVector
     * @param  array|null  $route  result of QuestionRouter::route()
     * @return array<int, array{chunk: object, score: float, dense_rank: ?int, bm25_rank: ?int}>
     */
    public function search(Document $document, string $query, array $queryVector, int $limit, ?array $route = null): array
    {
        $index = $this->index($document);
        $allowed = array_keys($index['rows']);

        if ($route) {
            $filtered = array_values(array_filter(
                $allowed,
                fn ($id) => QuestionRouter::matches($route, $index['rows'][$id]->section_path),
            ));
            // A filter that leaves almost nothing would hide the answer.
            if (count($filtered) >= 3) {
                $allowed = $filtered;
            }
        }

        $candidates = (int) config('rag.retrieval.candidates');
        $k = (int) config('rag.retrieval.rrf_k');

        // Dense ranking.
        $queryNorm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $queryVector))) ?: 1.0;
        $dense = [];
        foreach ($allowed as $id) {
            $vector = $index['vectors'][$id];
            $dot = 0.0;
            foreach ($queryVector as $i => $x) {
                $dot += $x * $vector[$i];
            }
            $dense[$id] = $dot / ($queryNorm * $index['norms'][$id]);
        }
        arsort($dense);
        $denseRanks = array_flip(array_slice(array_keys($dense), 0, $candidates));

        // Keyword ranking.
        $bm25 = $index['bm25']->scores($query, $allowed);
        $bm25Ranks = array_flip(array_slice(array_keys($bm25), 0, $candidates));

        // Reciprocal Rank Fusion.
        $fused = [];
        foreach ($denseRanks as $id => $rank) {
            $fused[$id] = ($fused[$id] ?? 0) + 1 / ($k + $rank + 1);
        }
        foreach ($bm25Ranks as $id => $rank) {
            $fused[$id] = ($fused[$id] ?? 0) + 1 / ($k + $rank + 1);
        }
        foreach ($fused as $id => $score) {
            $fused[$id] = $score * $this->weight($index['rows'][$id], $query, $document->profile);
        }
        arsort($fused);

        $results = [];
        foreach (array_slice($fused, 0, $limit, true) as $id => $score) {
            $results[] = [
                'chunk' => $index['rows'][$id],
                'score' => $score,
                'dense_rank' => isset($denseRanks[$id]) ? $denseRanks[$id] + 1 : null,
                'bm25_rank' => isset($bm25Ranks[$id]) ? $bm25Ranks[$id] + 1 : null,
            ];
        }

        return $results;
    }

    /**
     * Light priors on top of the fused rank:
     *  - tables / charts hold the numbers, so they win ties on quantity questions;
     *  - footnote chunks only matter when the question is about a note or a definition;
     *  - tiny text fragments without any figure are rarely an answer.
     */
    private function weight(object $chunk, string $query, string $profile): float
    {
        $asksQuantity = (bool) preg_match(
            '/\b(how much|how many|total|percent|percentage|number of|rate|growth|forecast|outlook|share|amount|less|more than|compared)\b/i',
            $query
        );
        $asksNote = (bool) preg_match('/\b(footnote|endnote|note|methodolog|defin|calculat|assum|basis|why)\w*/i', $query);

        $weight = 1.0;
        // Call-outs are single statements already ranked on their own text; only tables and
        // charts (many numbers in one chunk) are boosted.
        if (in_array($chunk->kind, ['table', 'chart'], true) && $asksQuantity) {
            // In a visual document the numbers live in tables/charts; in a long report the
            // prose usually states the headline figure and big tables only add distractor rows.
            $weight *= $profile === 'visual' ? 1.6 : 1.05;
        }
        if ($chunk->kind === 'footnote' && ! $asksNote) {
            $weight *= 0.6;
        }
        if (mb_strlen($chunk->content) < 60 && ! preg_match('/\d/', $chunk->content)) {
            $weight *= 0.75;
        }

        return $weight;
    }

    /** Chunks of the same parent/section directly before and after a chunk. */
    public function neighbours(Document $document, object $chunk): array
    {
        $index = $this->index($document);
        $siblings = [];
        foreach ($index['rows'] as $row) {
            if ($row->parent_id !== null && $row->parent_id === $chunk->parent_id) {
                $siblings[$row->chunk_index] = $row;
            }
        }
        ksort($siblings);
        $order = array_keys($siblings);
        $position = array_search($chunk->chunk_index, $order, true);
        if ($position === false) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $siblings[$order[$position - 1] ?? -1] ?? null,
            'next' => $siblings[$order[$position + 1] ?? -1] ?? null,
        ];
    }

    private function index(Document $document): array
    {
        if (isset($this->cache[$document->id])) {
            return $this->cache[$document->id];
        }

        $rows = [];
        $vectors = [];
        $norms = [];
        $texts = [];

        $query = DB::table('document_chunks')
            ->where('document_id', $document->id)
            ->whereNotNull('embedding')
            ->orderBy('chunk_index')
            ->select(['id', 'chunk_index', 'parent_id', 'kind', 'section_path', 'page_start', 'page_end',
                'printed_page', 'content', 'search_text', 'embedding']);

        foreach ($query->cursor() as $row) {
            $vector = DocumentChunk::unpackVector($row->embedding);
            if (! $vector) {
                continue;
            }
            unset($row->embedding);
            $rows[$row->id] = $row;
            $vectors[$row->id] = $vector;
            $norms[$row->id] = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector))) ?: 1.0;
            $texts[$row->id] = $row->search_text;
        }

        return $this->cache[$document->id] = [
            'rows' => $rows,
            'vectors' => $vectors,
            'norms' => $norms,
            'bm25' => new Bm25($texts),
        ];
    }
}
