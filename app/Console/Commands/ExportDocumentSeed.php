<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Dumps ingested documents + chunks (text and embedding vectors) to JSON so a
 * fresh checkout can `php artisan db:seed` instead of re-running the (slow,
 * CPU-bound) ingestion. Conversations and messages are never exported.
 */
class ExportDocumentSeed extends Command
{
    protected $signature = 'document:export-seed';

    protected $description = 'Export documents and chunks to database/seeders/data/*.json';

    public function handle(): int
    {
        $dir = database_path('seeders/data');
        File::ensureDirectoryExists($dir);

        $documents = Document::ready()->orderBy('id')->get();
        File::put($dir.'/documents.json', json_encode($documents->map->only([
            'id', 'name', 'filename', 'original_extension', 'profile', 'status', 'page_count', 'chunk_count',
        ])->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // One file per document keeps each file reviewable and below editor limits.
        foreach (glob($dir.'/chunks-*.json') as $old) {
            unlink($old);
        }

        foreach ($documents as $document) {
            $rows = [];
            foreach (DB::table('document_chunks')->where('document_id', $document->id)->orderBy('chunk_index')->cursor() as $row) {
                $embedding = $row->embedding;
                if (is_resource($embedding)) {
                    $embedding = stream_get_contents($embedding);
                }
                $rows[] = [
                    'id' => $row->id,
                    'chunk_index' => $row->chunk_index,
                    'parent_id' => $row->parent_id,
                    'kind' => $row->kind,
                    'section_path' => $row->section_path,
                    'page_start' => $row->page_start,
                    'page_end' => $row->page_end,
                    'printed_page' => $row->printed_page,
                    'content' => $row->content,
                    'search_text' => $row->search_text,
                    'embedding' => $embedding ? base64_encode($embedding) : null,
                ];
            }
            File::put($dir."/chunks-{$document->id}.json", json_encode($rows, JSON_UNESCAPED_UNICODE));
            $this->line("  {$document->name}: ".count($rows).' chunks');
        }

        $this->info('Exported '.$documents->count().' document(s) to '.$dir);

        return self::SUCCESS;
    }
}
