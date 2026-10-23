<?php

use App\Services\Chunking\NarrativeChunker;
use App\Services\Chunking\StructuredChunker;
use App\Services\Chunking\TableFacts;
use App\Services\Chunking\TextCleaner;
use App\Services\Chunking\VisualChunker;
use Tests\Unit\ChunkerFixtures as F;

it('repairs the lost Th ligature and drops acronym lists', function () {
    expect(TextCleaner::prose("Te conflict in\nthe Middle East. Tese risks"))->toBe('The conflict in the Middle East. These risks');
    expect(TextCleaner::isAcronymList('AE AI ASEAN CPI EAP ECA EMBI EMBIG EMDEs FAO FCS FDI FY GCC GDP'))->toBeTrue();
    expect(TextCleaner::isAcronymList('Growth is expected to soften in 2026 as energy prices rise sharply.'))->toBeFalse();
});

it('re-joins a line-end hyphen only when the whole word exists elsewhere', function () {
    $vocabulary = TextCleaner::vocabulary(['The sustainability of debt matters.']);

    expect(TextCleaner::prose("sustainabil-\nity of low-\nincome countries", $vocabulary))
        ->toBe('sustainability of low-income countries');
});

it('narrative chunker keeps page ranges and repeats a short last sentence', function () {
    $pages = [
        F::page(1, [F::block('GPO staff visited 166 depository libraries in FY 2018. This was a record.')]),
        F::page(2, [
            F::block('Footer 12', 7, 760, 36, 'footer'),
            F::block('Another sentence follows here. And one more sentence to fill the chunk with words.'),
        ]),
    ];

    $chunks = (new NarrativeChunker(target: 90, overlap: 60))->chunk(['pages' => $pages], 'GPO');

    expect($chunks)->not->toBeEmpty()
        ->and($chunks[0]->content)->toContain('166 depository libraries')
        ->and($chunks[0]->pageStart)->toBe(1)
        ->and(collect($chunks)->pluck('content')->implode(' '))->not->toContain('Footer 12')
        ->and(collect($chunks)->max('pageEnd'))->toBe(2);
});

it('table facts spell out year columns and the revision columns', function () {
    $rows = [
        F::row(80, ['TABLE 2.9 South Asia forecast summary', 'Percentage-point']),
        F::row(90, ['differences from January']),
        F::row(98, ['(Real GDP growth)', '2026 projections']),
        F::row(113, ['2023', '2024', '2025e', '2026f', '2027f', '2028f', '2026f', '2027f']),
        F::row(125, ['EMDE SAR, GDP 1', '6.6', '6.8', '7.0', '6.3', '6.9', '7.1', '0.1', '0.4']),
    ];

    $lines = (new TableFacts)->lines($rows);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('EMDE SAR, GDP: 2023: 6.6')
        ->and($lines[0])->toContain('2026f: 6.3')
        ->and($lines[0])->toContain('percentage-point differences from january 2026 projections - 2026f: 0.1');
});

it('structured chunker corrects an early bookmark and assigns section paths, boxes and tables', function () {
    $heading = fn (string $t) => F::block($t, 16, 75, 89, 'body', true);
    $pages = [
        1 => F::page(1, [$heading('Executive Summary'), F::block('Risks to the outlook remain skewed to the downside and could lower growth.')]),
        2 => F::page(2, [F::block('CHAPTER 2', 26, 100), F::block('REGIONAL OUTLOOKS', 30, 150)]),
        3 => F::page(3, [F::block('Table of the previous region continues without a heading on this page.', 11, 300)]),
        4 => F::page(4, [$heading('South Asia'), F::block('Recent developments', 11, 102), F::block('Growth in SAR is expected to soften to 6.3 percent in 2026, mainly reflecting the conflict.', 11, 125)]),
        5 => F::page(5, [
            F::block('BOX 2.1 Low-income countries: Outlook (continued)', 10, 60, 90, 'body', true),
            F::block('Growth in low-income countries firmed to 5 percent in 2025 before easing.', 10.6, 120),
        ]),
        6 => F::page(6, [
            F::block('TABLE 2.9 South Asia forecast summary', 10, 75, 38, 'body', true),
        ], [
            F::row(80, ['TABLE 2.9 South Asia forecast summary']),
            F::row(113, ['2023', '2024', '2025e', '2026f']),
            F::row(125, ['GDP', '6.6', '6.8', '7.0', '6.3']),
        ]),
    ];
    $toc = [
        ['level' => 1, 'title' => 'Executive Summary', 'page' => 1],
        ['level' => 1, 'title' => 'Ch. 2 Regional Outlooks', 'page' => 2],
        ['level' => 1, 'title' => 'Box 2.1 Low-income countries: Outlook', 'page' => 5],
        // bookmark points one page before the real heading (page 4)
        ['level' => 1, 'title' => 'South Asia', 'page' => 3],
    ];

    $chunks = (new StructuredChunker)->chunk(['pages' => array_values($pages), 'toc' => $toc], 'GEP');
    $byText = fn (string $needle) => collect($chunks)->first(fn ($c) => ! $c->isParent && str_contains($c->content, $needle));

    expect($byText('skewed to the downside')->sectionPath)->toBe('Executive Summary')
        ->and($byText('soften to 6.3')->sectionPath)->toBe('Ch.2 Regional Outlooks > South Asia > Recent developments')
        ->and($byText('Table of the previous region')->sectionPath)->toStartWith('Ch.2 Regional Outlooks')
        ->and($byText('Table of the previous region')->sectionPath)->not->toContain('South Asia')
        ->and($byText('low-income countries firmed')->sectionPath)->toBe('Ch.2 Regional Outlooks > Box 2.1 Low-income countries: Outlook')
        ->and($byText('2026f: 6.3')->kind)->toBe('table')
        ->and($byText('2026f: 6.3')->sectionPath)->toBe('Ch.2 Regional Outlooks > South Asia');

    // every searchable child points at a parent section part
    $children = collect($chunks)->filter(fn ($c) => $c->kind === 'paragraph');
    expect($children->every(fn ($c) => $c->parentKey !== null))->toBeTrue();
});

it('visual chunker rebuilds label/value tables, chart labels, call-outs and footnotes', function () {
    // Endnotes are numbered 1..n, each starting a row in the note column.
    $texts = [3 => 'Breakdown of U.S. retail packaging by weight.', 18 => 'ENERGY STAR note text.'];
    $noteRows = [];
    foreach (range(1, 18) as $n) {
        $text = "{$n} ".($texts[$n] ?? "Note number {$n}.");
        $noteRows[] = ['y' => 80 + $n * 12, 'x0' => 213, 'text' => $text, 'cells' => [['x0' => 213, 'x1' => 500, 'text' => $text]]];
    }

    $pages = [
        F::page(1, [
            F::block('Progress toward carbon neutral', 14, 80),
            F::block("iMac uses 58 percent less energy than the requirement for ENERGY STAR.[^18]", 12, 200, 216),
            array_merge(F::block('100%', 17.5, 400, 36), ['x1' => 88, 'y1' => 420]),
            array_merge(F::block("of the packaging[^3]\nis fiber-based", 9.5, 430, 36), ['x1' => 126, 'y1' => 470]),
        ], [
            ['y' => 255, 'x0' => 254, 'text' => '445 kg', 'cells' => [['x0' => 254, 'x1' => 290, 'text' => '445 kg'], ['x0' => 349, 'x1' => 390, 'text' => "\u{2013}99 kg"]]],
            ['y' => 269, 'x0' => 241, 'text' => 'Baseline emissions7', 'cells' => [['x0' => 241, 'x1' => 310, 'text' => 'Baseline emissions7'], ['x0' => 333, 'x1' => 410, 'text' => 'Emissions reductions']]],
            F::row(300, ['Total product footprint', '346 kg CO2e']),
            F::row(314, ['Life cycle product emissions (scope 3)', '346 kg CO2e']),
            F::row(328, ["\u{2022} Production", '50%']),
            F::row(342, ["\u{2022} Product use", '45%']),
        ]),
        F::page(2, [F::block('Endnotes', 24, 80, 36)], $noteRows),
    ];

    $chunks = (new VisualChunker)->chunk(['pages' => $pages], 'iMac PER');
    $all = collect($chunks);

    $table = $all->firstWhere('kind', 'table');
    expect($table->content)->toContain('Total product footprint: 346 kg CO2e')
        ->and($table->content)->toContain('Life cycle product emissions (scope 3) - Product use: 45%');

    expect($all->firstWhere('kind', 'chart')->content)->toContain('445 kg - Baseline emissions')
        ->and($all->firstWhere('kind', 'chart')->content)->toContain('-99 kg');

    $callout = $all->firstWhere('kind', 'callout');
    expect($callout->content)->toContain('100% of the packaging')
        ->and($callout->content)->toContain('Footnote 3: Breakdown of U.S. retail packaging by weight.');

    $energy = $all->first(fn ($c) => str_contains($c->content, '58 percent'));
    expect($energy->content)->toContain('Footnote 18: ENERGY STAR note text.')
        ->and($energy->content)->not->toContain('[^18]');
});
