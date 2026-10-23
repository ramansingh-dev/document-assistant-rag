<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Retrieval\RetrievalService;
use Generator;

/**
 * The RAG loop for one conversation (which is permanently scoped to one document).
 */
class RagChatService
{
    public const SYSTEM_TEMPLATE = <<<'PROMPT'
You answer questions about the document "{document}" using ONLY the CONTEXT passages provided with each question.

Rules:
- Use only facts stated in the CONTEXT. Never use outside knowledge and never add information that is not in the CONTEXT.
- If the CONTEXT does not contain the answer, reply exactly: I don't have that information in {document}.
- Quote numbers exactly as written and always keep them with their label, year, region or table row. Never take a number from a different year, region, chapter or row.
- A figure belongs only to the item it is stated about (for example recycled aluminum in the stand is not recycled fiber in the packaging). Do not attach a figure to a different item, and leave out facts about other items.
- If related figures appear together in the CONTEXT (for example a total and its breakdown, or a baseline, a reduction and a result), state them together.
- Lead with the figure or fact that directly answers the question. For an outlook or forecast question without a year, give the headline forecast (the first forecast year) before any later years.
- Mention the page of the source, for example (page 74).
- Be concise: 1 to 4 sentences, no preamble.
PROMPT;

    public function __construct(
        private RetrievalService $retrieval,
        private OllamaChatService $chat,
    ) {}

    /**
     * Non-streaming answer; persists both messages.
     *
     * @return array{answer:string,sources:array}
     */
    public function ask(Conversation $conversation, string $question): array
    {
        $answer = '';
        $sources = [];
        foreach ($this->askStream($conversation, $question) as $event) {
            if ($event['type'] === 'sources') {
                $sources = $event['sources'];
            } elseif ($event['type'] === 'delta') {
                $answer .= $event['text'];
            } elseif ($event['type'] === 'done') {
                $answer = $event['answer'];
            }
        }

        return ['answer' => $answer, 'sources' => $sources];
    }

    /**
     * Yields: {type:'sources'} once, {type:'delta'} per fragment, {type:'done'} at the end.
     *
     * @return Generator<int, array>
     */
    public function askStream(Conversation $conversation, string $question): Generator
    {
        $document = $conversation->document;
        $history = $conversation->messages()->get();
        $previousQuestion = $history->where('role', 'user')->last()?->content;

        $retrieval = $this->retrieval->retrieve($document, $question, $previousQuestion);
        $sources = array_map(fn ($s) => [
            'rank' => $s['rank'],
            'page_label' => $s['page_label'],
            'section' => $s['section'],
            'kind' => $s['kind'],
            'excerpt' => mb_strimwidth($s['content'], 0, 600, '...'),
            'primary' => $s['primary'],
        ], $retrieval['sources']);

        yield ['type' => 'sources', 'sources' => $sources];

        $messages = $this->messages($document->name, $history, $question, $retrieval['context']);

        $answer = '';
        foreach ($this->chat->stream($messages) as $fragment) {
            $answer .= $fragment;
            yield ['type' => 'delta', 'text' => $fragment];
        }

        $answer = trim($answer);
        if ($answer === '') {
            $answer = "I couldn't generate an answer. Please try rephrasing the question.";
        }

        $conversation->messages()->create(['role' => 'user', 'content' => $question]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => $answer, 'sources' => $sources]);

        if (! $conversation->title) {
            $conversation->update(['title' => mb_strimwidth($question, 0, 60, '...')]);
        }

        yield ['type' => 'done', 'answer' => $answer];
    }

    /** @return array<int, array{role:string,content:string}> */
    private function messages(string $documentName, $history, string $question, string $context): array
    {
        $messages = [[
            'role' => 'system',
            'content' => str_replace('{document}', $documentName, self::SYSTEM_TEMPLATE),
        ]];

        // Earlier turns carry the conversation; only the current turn carries CONTEXT.
        $recent = $history->take(-1 * (int) config('rag.retrieval.history_messages'));
        foreach ($recent as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $messages[] = [
            'role' => 'user',
            // The question is repeated after the context: a 1.5B model otherwise latches onto
            // whichever passage came last and answers a neighbouring question.
            'content' => "QUESTION: {$question}\n\nCONTEXT:\n{$context}\n\n"
                ."Answer this question using only the CONTEXT above, giving the exact figure that matches what was asked: {$question}",
        ];

        return $messages;
    }
}
