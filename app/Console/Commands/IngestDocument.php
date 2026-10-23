<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentIngestionService;
use Illuminate\Console\Command;
use Throwable;

class IngestDocument extends Command
{
    protected $signature = 'document:ingest
        {path : Path to the PDF}
        {name? : Display name (defaults to the file name)}
        {--profile= : narrative | structured | visual (inferred from the file name when omitted)}
        {--force : Replace an existing document with the same file name}
        {--dry-run : Extract and chunk only - no database, no Ollama; prints chunk statistics}
        {--show=0 : With --dry-run, print this many sample chunks per kind}';

    protected $description = 'Parse, chunk, embed and store a document for chat';

    public function handle(DocumentIngestionService $ingestion): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $profile = $this->option('profile') ?: $ingestion->inferProfile($path);
        if (! in_array($profile, config('rag.profiles'), true)) {
            $this->error('Profile must be one of: '.implode(', ', config('rag.profiles')));

            return self::FAILURE;
        }

        $name = $this->argument('name') ?: pathinfo($path, PATHINFO_FILENAME);

        if ($this->option('dry-run')) {
            return $this->dryRun($ingestion, $path, $profile, $name);
        }

        $existing = Document::where('filename', basename($path))->first();
        if ($existing) {
            if (! $this->option('force')) {
                $this->error("'{$existing->name}' is already ingested. Use --force to replace it.");

                return self::FAILURE;
            }
            $ingestion->delete($existing);
        }

        $this->info("Ingesting '{$name}' with the {$profile} profile...");
        $started = microtime(true);

        try {
            $document = $ingestion->ingest($path, $name, $profile, fn ($m) => $this->line("  {$m}"));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done: %d chunks, %d pages in %.1fs (document id %d).',
            $document->chunk_count, $document->page_count, microtime(true) - $started, $document->id
        ));

        return self::SUCCESS;
    }

    private function dryRun(DocumentIngestionService $ingestion, string $path, string $profile, string $name): int
    {
        [$extract, $chunks] = $ingestion->preview($path, $profile, $name);

        $byKind = [];
        foreach ($chunks as $chunk) {
            $byKind[$chunk->kind] = ($byKind[$chunk->kind] ?? 0) + 1;
        }

        $this->info("{$name}: {$extract['page_count']} pages, ".count($chunks).' chunks ('.$profile.')');
        foreach ($byKind as $kind => $count) {
            $this->line("  {$kind}: {$count}");
        }

        $show = (int) $this->option('show');
        if ($show > 0) {
            $shown = [];
            foreach ($chunks as $chunk) {
                $shown[$chunk->kind] = ($shown[$chunk->kind] ?? 0) + 1;
                if ($shown[$chunk->kind] > $show) {
                    continue;
                }
                $this->newLine();
                $this->line("[{$chunk->kind}] pages {$chunk->pageStart}-{$chunk->pageEnd} printed ".($chunk->printedPage ?? '-').' | '.($chunk->sectionPath ?? ''));
                $this->line($chunk->content);
            }
        }

        return self::SUCCESS;
    }
}
