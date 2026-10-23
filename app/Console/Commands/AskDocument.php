<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Document;
use App\Services\RagChatService;
use App\Services\Retrieval\RetrievalService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AskDocument extends Command
{
    protected $signature = 'rag:ask
        {document : Document id or part of its name}
        {question : The question to ask}
        {--retrieval : Only show the retrieved sources, do not call the chat model}';

    protected $description = 'Ask one question from the terminal (debugging aid for retrieval and answers)';

    public function handle(RetrievalService $retrieval, RagChatService $chat): int
    {
        $document = is_numeric($this->argument('document'))
            ? Document::find($this->argument('document'))
            : Document::where('name', 'like', '%'.$this->argument('document').'%')->first();

        if (! $document) {
            $this->error('Document not found.');

            return self::FAILURE;
        }

        $question = $this->argument('question');

        if ($this->option('retrieval')) {
            $result = $retrieval->retrieve($document, $question);
            $this->line('Route: '.json_encode($result['route']['labels'] ?? null));
            foreach ($result['sources'] as $source) {
                $this->newLine();
                $this->info("#{$source['rank']} {$source['page_label']} | {$source['section']} | {$source['kind']}".($source['primary'] ? " [{$source['ranks']}]" : ' (context)'));
                $this->line($source['content']);
            }

            return self::SUCCESS;
        }

        $conversation = Conversation::create([
            'session_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
        ]);

        $result = $chat->ask($conversation, $question);

        $this->info('Sources:');
        foreach ($result['sources'] as $source) {
            $this->line("  #{$source['rank']} {$source['page_label']} | {$source['section']} | {$source['kind']}");
        }
        $this->newLine();
        $this->line($result['answer']);

        $conversation->delete();

        return self::SUCCESS;
    }
}
