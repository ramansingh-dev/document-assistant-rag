<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Local embeddings through Ollama's /api/embed. */
class EmbeddingService
{
    /** @return float[] */
    public function embed(string $text): array
    {
        return $this->embedMany([$text])[0];
    }

    /**
     * Qwen3-Embedding is trained with an instruction in front of the *query*
     * (documents are embedded as-is), which noticeably improves retrieval.
     *
     * @return float[]
     */
    public function embedQuery(string $question): array
    {
        return $this->embed(
            "Instruct: Given a question about a document, retrieve the passages, tables or figures that answer it\nQuery: {$question}"
        );
    }

    /**
     * @param  string[]  $texts
     * @return array<int, float[]>
     */
    public function embedMany(array $texts): array
    {
        $texts = array_values($texts);
        $vectors = [];

        foreach (array_chunk($texts, config('rag.ollama.embed_batch')) as $batch) {
            $response = Http::baseUrl(config('rag.ollama.host'))
                ->timeout(config('rag.ollama.timeout'))
                ->post('/api/embed', [
                    'model' => config('rag.ollama.embedding_model'),
                    'input' => $batch,
                    'keep_alive' => config('rag.ollama.keep_alive'),
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Ollama embedding failed: '.$response->body());
            }

            foreach ($response->json('embeddings') as $vector) {
                $vectors[] = $vector;
            }
        }

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException('Ollama returned an unexpected number of embeddings.');
        }

        return $vectors;
    }
}
