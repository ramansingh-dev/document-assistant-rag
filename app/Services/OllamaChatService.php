<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Local chat generation through Ollama's /api/chat (installed model only). */
class OllamaChatService
{
    /** @param  array<int, array{role:string,content:string}>  $messages */
    public function generate(array $messages): string
    {
        $response = Http::baseUrl(config('rag.ollama.host'))
            ->timeout(config('rag.ollama.timeout'))
            ->post('/api/chat', $this->payload($messages, false));

        if ($response->failed()) {
            throw new RuntimeException('Ollama chat failed: '.$response->body());
        }

        return trim((string) $response->json('message.content'));
    }

    /**
     * Streams answer fragments as Ollama produces them (NDJSON).
     *
     * @param  array<int, array{role:string,content:string}>  $messages
     * @return Generator<int,string>
     */
    public function stream(array $messages): Generator
    {
        $response = Http::baseUrl(config('rag.ollama.host'))
            ->timeout(config('rag.ollama.timeout'))
            ->withOptions(['stream' => true])
            ->post('/api/chat', $this->payload($messages, true));

        if ($response->failed()) {
            throw new RuntimeException('Ollama chat failed: '.$response->body());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(512);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if ($line === '') {
                    continue;
                }
                $data = json_decode($line, true);
                if (isset($data['error'])) {
                    throw new RuntimeException('Ollama chat failed: '.$data['error']);
                }
                $fragment = $data['message']['content'] ?? '';
                if ($fragment !== '') {
                    yield $fragment;
                }
            }
        }
    }

    private function payload(array $messages, bool $stream): array
    {
        return [
            'model' => config('rag.ollama.chat_model'),
            'messages' => $messages,
            'stream' => $stream,
            'think' => false,
            'keep_alive' => config('rag.ollama.keep_alive'),
            'options' => [
                'temperature' => config('rag.generation.temperature'),
                'num_predict' => config('rag.generation.num_predict'),
                'num_ctx' => config('rag.generation.num_ctx'),
            ],
        ];
    }
}
