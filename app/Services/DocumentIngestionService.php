<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Chunking\Chunk;
use App\Services\Chunking\ChunkerInterface;
use App\Services\Chunking\NarrativeChunker;
use App\Services\Chunking\StructuredChunker;
use App\Services\Chunking\VisualChunker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * extract (PyMuPDF) -> chunk (profile specific) -> embed (Ollama) -> store (SQLite).
 */
class DocumentIngestionService
{
    public function __construct(
        private PdfExtractorService $extractor,
        private EmbeddingService $embeddings,
    ) {}

    /** Choose a profile from the file name when none is given. */
    public function inferProfile(string $path): string
    {
        $name = strtolower(basename($path));

        return match (true) {
            str_contains($name, 'gep'), str_contains($name, 'prospects') => 'structured',
            str_contains($name, 'imac'), str_contains($name, 'environmental') => 'visual',
            default => 'narrative',
        };
    }

    public function chunker(string $profile): ChunkerInterface
    {
        return match ($profile) {
            'narrative' => new NarrativeChunker,
            'structured' => new StructuredChunker,
            'visual' => new VisualChunker,
            default => throw new InvalidArgumentException("Unknown profile [{$profile}]."),
        };
    }

    /** Extract + chunk only; no database and no Ollama (used by --dry-run and tests). */
    public function preview(string $path, string $profile, string $name): array
    {
        $extract = $this->extractor->extract($path, $profile === 'visual');

        return [$extract, $this->chunker($profile)->chunk($extract, $name)];
    }

    public function ingest(string $path, string $name, string $profile, ?callable $progress = null): Document
    {
        $document = Document::create([
            'name' => $name,
            'filename' => basename($path),
            'original_extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'pdf',
            'profile' => $profile,
            'status' => 'processing',
        ]);

        try {
            $progress && $progress('Extracting PDF with PyMuPDF...');
            [$extract, $chunks] = $this->preview($path, $profile, $name);
            $progress && $progress(count($chunks).' chunks from '.$extract['page_count'].' pages. Embedding...');

            $this->store($document, $chunks, $progress);

            $document->update([
                'status' => 'ready',
                'page_count' => $extract['page_count'],
                'chunk_count' => $document->chunks()->count(),
            ]);
        } catch (Throwable $e) {
            $document->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }

        return $document->refresh();
    }

    /** @param  Chunk[]  $chunks */
    private function store(Document $document, array $chunks, ?callable $progress): void
    {
        $embeddable = [];
        foreach ($chunks as $i => $chunk) {
            if (! $chunk->isParent) {
                $embeddable[] = $i;
            }
        }

        $vectors = [];
        $batch = (int) config('rag.ollama.embed_batch');
        foreach (array_chunk($embeddable, $batch * 4) as $group) {
            $texts = array_map(fn ($i) => $chunks[$i]->searchText, $group);
            foreach ($this->embeddings->embedMany($texts) as $position => $vector) {
                $vectors[$group[$position]] = $vector;
            }
            $progress && $progress('Embedded '.count($vectors).'/'.count($embeddable));
        }

        DB::transaction(function () use ($document, $chunks, $vectors) {
            $parentIds = [];
            $index = 0;

            // Parents first so children can reference their ids.
            foreach ($chunks as $chunk) {
                if ($chunk->isParent) {
                    $parentIds[$chunk->key] = $this->insert($document, $chunk, $index++, null, null);
                }
            }
            foreach ($chunks as $i => $chunk) {
                if (! $chunk->isParent) {
                    $this->insert(
                        $document,
                        $chunk,
                        $index++,
                        $chunk->parentKey ? ($parentIds[$chunk->parentKey] ?? null) : null,
                        DocumentChunk::packVector($vectors[$i]),
                    );
                }
            }
        });
    }

    private function insert(Document $document, Chunk $chunk, int $index, ?int $parentId, ?string $embedding): int
    {
        $row = DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => $index,
            'parent_id' => $parentId,
            'kind' => $chunk->kind,
            'section_path' => $chunk->sectionPath,
            'page_start' => $chunk->pageStart,
            'page_end' => $chunk->pageEnd,
            'printed_page' => $chunk->printedPage,
            'content' => $chunk->content,
            'search_text' => $chunk->searchText,
            'embedding' => null,
        ]);

        if ($embedding !== null) {
            // Bind as a real BLOB: as a string, SQLite would cut the packed floats at the first NUL byte.
            $statement = DB::connection()->getPdo()->prepare('UPDATE document_chunks SET embedding = ? WHERE id = ?');
            $statement->bindValue(1, $embedding, \PDO::PARAM_LOB);
            $statement->bindValue(2, $row->id, \PDO::PARAM_INT);
            $statement->execute();
        }

        return $row->id;
    }

    public function delete(Document $document): void
    {
        $document->delete();
    }
}
