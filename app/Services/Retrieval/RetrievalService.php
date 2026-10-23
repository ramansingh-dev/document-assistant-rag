<?php

namespace App\Services\Retrieval;

use App\Models\Document;
use App\Services\EmbeddingService;

/**
 * question -> route -> hybrid search -> expanded context for the model.
 */
class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddings,
        private VectorSearchService $search,
        private QuestionRouter $router,
    ) {}

    /**
     * @param  string  $question  the user's message
     * @param  string|null  $previousQuestion  the user's previous message, to resolve short follow-ups
     * @return array{sources: array<int,array>, context: string, route: ?array, query: string}
     */
    public function retrieve(Document $document, string $question, ?string $previousQuestion = null): array
    {
        $query = $this->standalone($question, $previousQuestion);
        $route = $this->router->route($document, $query);
        $vector = $this->embeddings->embedQuery($query);

        $hits = $this->search->search(
            $document,
            $query,
            $vector,
            (int) config('rag.retrieval.top_k'),
            $route,
        );

        return $this->assemble($document, $hits, $route, $query);
    }

    /** A short follow-up ("and for 2027?") borrows the previous question's subject. */
    private function standalone(string $question, ?string $previous): string
    {
        if ($previous && str_word_count($question) < 8) {
            return $previous.' '.$question;
        }

        return $question;
    }

    private function assemble(Document $document, array $hits, ?array $route, string $query): array
    {
        $budget = (int) config('rag.retrieval.context_budget');
        $sources = [];
        $seen = [];
        $used = 0;

        $ranks = [];
        foreach ($hits as $hit) {
            $ranks[$hit['chunk']->id] = 'dense '.($hit['dense_rank'] ?? '-').' / bm25 '.($hit['bm25_rank'] ?? '-');
        }

        $add = function (object $chunk, bool $primary) use (&$sources, &$seen, &$used, $budget, $ranks) {
            if (isset($seen[$chunk->id])) {
                return;
            }
            $length = mb_strlen($chunk->content);
            if ($used + $length > $budget && $sources) {
                return;
            }
            $seen[$chunk->id] = true;
            $used += $length;
            $sources[] = [
                'id' => $chunk->id,
                'rank' => count($sources) + 1,
                'kind' => $chunk->kind,
                'section' => $chunk->section_path,
                'page_label' => $this->pageLabel($chunk),
                'page_start' => $chunk->page_start,
                'content' => $chunk->content,
                'primary' => $primary,
                'ranks' => $ranks[$chunk->id] ?? null,
            ];
        };

        foreach ($hits as $hit) {
            $add($hit['chunk'], true);
        }

        // Give the best hits their surrounding sentences so a fact is not cut off.
        foreach (array_slice($hits, 0, 3) as $hit) {
            $neighbours = $this->search->neighbours($document, $hit['chunk']);
            foreach (['next', 'previous'] as $side) {
                if ($neighbours[$side] && $hit['chunk']->kind === 'paragraph') {
                    $add($neighbours[$side], false);
                }
            }
        }

        $parts = [];
        foreach ($sources as $i => $source) {
            $header = 'Source '.($i + 1).' | '.$source['page_label']
                .($source['section'] ? ' | '.$source['section'] : '')
                .($source['kind'] !== 'paragraph' ? ' | '.$source['kind'] : '');
            $parts[] = "[{$header}]\n{$source['content']}";
        }

        return [
            'sources' => $sources,
            'context' => implode("\n\n", $parts),
            'route' => $route,
            'query' => $query,
        ];
    }

    private function pageLabel(object $chunk): string
    {
        $model = new \App\Models\DocumentChunk((array) $chunk);

        return $model->pageLabel();
    }
}
