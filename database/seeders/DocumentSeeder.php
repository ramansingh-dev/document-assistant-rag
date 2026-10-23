<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Restores the output of `php artisan document:export-seed` (documents, chunks
 * and their embedding vectors) so no Ollama ingestion is needed on a new machine.
 */
class DocumentSeeder extends Seeder
{
    public function run(): void
    {
        $dir = database_path('seeders/data');
        if (! is_file($dir.'/documents.json')) {
            $this->command?->warn('No seed data found; run document:ingest instead.');

            return;
        }

        $documents = json_decode(file_get_contents($dir.'/documents.json'), true);
        $pdo = DB::connection()->getPdo();

        $insert = $pdo->prepare(
            'INSERT INTO document_chunks (id, document_id, chunk_index, parent_id, kind, section_path, page_start, page_end,
                printed_page, content, search_text, embedding, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($documents, $dir, $insert, $now) {
            foreach ($documents as $document) {
                DB::table('documents')->updateOrInsert(
                    ['id' => $document['id']],
                    $document + ['created_at' => $now, 'updated_at' => $now],
                );
                DB::table('document_chunks')->where('document_id', $document['id'])->delete();

                $file = $dir."/chunks-{$document['id']}.json";
                foreach (json_decode(file_get_contents($file), true) as $row) {
                    $insert->bindValue(1, $row['id'], PDO::PARAM_INT);
                    $insert->bindValue(2, $document['id'], PDO::PARAM_INT);
                    $insert->bindValue(3, $row['chunk_index'], PDO::PARAM_INT);
                    $insert->bindValue(4, $row['parent_id'], $row['parent_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $insert->bindValue(5, $row['kind']);
                    $insert->bindValue(6, $row['section_path']);
                    $insert->bindValue(7, $row['page_start']);
                    $insert->bindValue(8, $row['page_end']);
                    $insert->bindValue(9, $row['printed_page']);
                    $insert->bindValue(10, $row['content']);
                    $insert->bindValue(11, $row['search_text']);
                    // A packed float vector contains NUL bytes: it must be bound as a BLOB.
                    $insert->bindValue(
                        12,
                        $row['embedding'] ? base64_decode($row['embedding']) : null,
                        $row['embedding'] ? PDO::PARAM_LOB : PDO::PARAM_NULL,
                    );
                    $insert->bindValue(13, $now);
                    $insert->bindValue(14, $now);
                    $insert->execute();
                }
            }
        });
    }
}
