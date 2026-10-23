<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Document;
use App\Services\RagChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Runs the questions from RAG-Hands-On-Use-Cases.docx (plus a few negative
 * controls) and checks both retrieval and the final answer.
 */
class EvaluateRag extends Command
{
    protected $signature = 'rag:eval
        {--only= : Run only cases whose use case/name contains this text}
        {--no-answer : Check retrieval only (fast, no chat model)}';

    protected $description = 'Evaluate retrieval and answers for the three use cases';

    /**
     * answer_all: every pattern must appear in the answer (case-insensitive regex)
     * answer_none: no pattern may appear in the answer (a figure from a different item)
     * context:    a pattern that must appear in the retrieved context
     * section:    every primary source must come from a section starting with this
     */
    public static function cases(): array
    {
        return [
            // Use case 1 - plain narrative report
            ['uc' => 'UC1 GPO', 'document' => 'GPO',
                'q' => 'How many depository libraries did GPO staff visit in FY 2018?',
                'answer_all' => ['\b166\b'], 'context' => '166 depository libraries'],
            ['uc' => 'UC1 GPO', 'document' => 'GPO',
                'q' => 'What new digital system replaced FDsys, and when was it fully deployed?',
                'answer_all' => ['govinfo', '2018'], 'context' => 'December 2018'],
            ['uc' => 'UC1 GPO', 'document' => 'GPO',
                'q' => 'How much did GPO procure in printing and information products in FY 2018 on behalf of federal agencies?',
                'answer_all' => ['\$?375'], 'context' => '375 million'],

            // Use case 2 - long structured report
            ['uc' => 'UC2 GEP', 'document' => 'Global Economic',
                'q' => 'What is the growth outlook for South Asia in this report?',
                'answer_all' => ['6\.3 percent in 2026|2026[^.]{0,40}6\.3'], 'context' => 'SAR is expected to soften to 6.3',
                'section' => 'Ch.2 Regional Outlooks > South Asia'],
            ['uc' => 'UC2 GEP', 'document' => 'Global Economic',
                'q' => 'What does Chapter 3 say about sovereign debt in developing economies?',
                'answer_all' => ['debt', 'interest rate'], 'section' => 'Ch.3'],
            ['uc' => 'UC2 GEP', 'document' => 'Global Economic',
                'q' => 'According to the executive summary, what are the main risks to the global outlook?',
                'answer_all' => ['downside|escalation|energy'], 'section' => 'Executive Summary'],
            ['uc' => 'UC2 GEP (no region mix-up)', 'document' => 'Global Economic',
                'q' => 'What is the growth outlook for Sub-Saharan Africa?',
                'answer_all' => ['\b4(\.0)? percent in 2026|2026[^.]{0,40}\b4(\.0)? percent'],
                'section' => 'Ch.2 Regional Outlooks > Sub-Saharan Africa'],

            // Use case 3 - short visual document
            ['uc' => 'UC3 iMac', 'document' => 'iMac',
                'q' => 'How much less energy does iMac use compared to the ENERGY STAR requirement?',
                'answer_all' => ['58'], 'context' => '58 percent less energy'],
            ['uc' => 'UC3 iMac', 'document' => 'iMac',
                'q' => 'What is the total carbon footprint of the iMac, and how much of that comes from product use versus manufacturing?',
                // each share must be tied to its label, not just present as a number
                'answer_all' => [
                    '346',
                    'product use[^.]{0,40}45|45\s*(%|percent)[^.]{0,40}product use',
                    '(production|manufactur)[^.]{0,40}50|50\s*(%|percent)[^.]{0,40}(production|manufactur)',
                ],
                'context' => 'Product use: 45%'],
            ['uc' => 'UC3 iMac', 'document' => 'iMac',
                'q' => 'What material is the iMac stand made from, and what percentage of it is recycled?',
                'answer_all' => ['alumin', '100'], 'context' => '100% recycled aluminum in the stand',
                // 76% is the recycled content of the packaging, a different item
                'answer_none' => ['\b76\b']],

            // Negative control: not in any document
            ['uc' => 'Control', 'document' => 'GPO',
                'q' => 'Who won the 2022 FIFA World Cup?',
                'answer_all' => ["don't have that information|do not have that information|not (?:in|mentioned)|no information"]],
        ];
    }

    public function handle(RagChatService $chat, \App\Services\Retrieval\RetrievalService $retrieval): int
    {
        $only = $this->option('only');
        $rows = [];
        $failures = 0;

        foreach (self::cases() as $case) {
            if ($only && ! Str::contains(strtolower($case['uc'].' '.$case['q']), strtolower($only))) {
                continue;
            }

            $document = Document::where('name', 'like', '%'.$case['document'].'%')->ready()->first();
            if (! $document) {
                $rows[] = [$case['uc'], Str::limit($case['q'], 50), 'SKIP', 'document not ingested'];
                continue;
            }

            $started = microtime(true);
            $notes = [];

            $result = $retrieval->retrieve($document, $case['q']);
            $contextOk = ! isset($case['context'])
                || stripos(preg_replace('/\s+/', ' ', $result['context']), $case['context']) !== false;
            if (! $contextOk) {
                $notes[] = 'context missing "'.$case['context'].'"';
            }

            $sectionOk = true;
            if (isset($case['section'])) {
                foreach (array_filter($result['sources'], fn ($s) => $s['primary']) as $source) {
                    if (! str_starts_with((string) $source['section'], $case['section'])) {
                        $sectionOk = false;
                    }
                }
                if (! $sectionOk) {
                    $notes[] = 'sources outside '.$case['section'];
                }
            }

            $answerOk = true;
            $answer = '';
            if (! $this->option('no-answer')) {
                $conversation = Conversation::create(['session_uuid' => (string) Str::uuid(), 'document_id' => $document->id]);
                $answer = $chat->ask($conversation, $case['q'])['answer'];
                $conversation->delete();

                foreach ($case['answer_all'] as $pattern) {
                    if (! preg_match('/'.$pattern.'/i', $answer)) {
                        $answerOk = false;
                        $notes[] = "answer lacks /{$pattern}/";
                    }
                }
                foreach ($case['answer_none'] ?? [] as $pattern) {
                    if (preg_match('/'.$pattern.'/i', $answer)) {
                        $answerOk = false;
                        $notes[] = "answer wrongly contains /{$pattern}/";
                    }
                }
            }

            $pass = $contextOk && $sectionOk && $answerOk;
            $failures += $pass ? 0 : 1;
            $rows[] = [
                $case['uc'],
                Str::limit($case['q'], 50),
                $pass ? 'PASS' : 'FAIL',
                sprintf('%.1fs', microtime(true) - $started).($notes ? ' - '.implode('; ', $notes) : ''),
            ];

            if (! $this->option('no-answer')) {
                $this->line('Q: '.$case['q']);
                $this->line('A: '.Str::limit(preg_replace('/\s+/', ' ', $answer), 400));
                $this->newLine();
            }
        }

        $this->table(['Use case', 'Question', 'Result', 'Notes'], $rows);
        $this->line($failures === 0 ? 'All checks passed.' : "{$failures} case(s) failed.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
