<?php

use App\Services\Retrieval\Bm25;
use App\Services\Retrieval\QuestionRouter;

it('BM25 ranks the passage containing the rare query terms first', function () {
    $bm25 = new Bm25([
        1 => 'Growth in East Asia is expected to ease in 2026.',
        2 => 'Growth in SAR is expected to soften to 6.3 percent in 2026.',
        3 => 'Sub-Saharan Africa growth is forecast at 4 percent.',
    ]);

    expect(array_key_first($bm25->scores('South Asia SAR growth 6.3')))->toBe(2);
});

$paths = [
    'Executive Summary',
    'Ch.1 Global Outlook',
    'Ch.1 Global Outlook > Box 1.1 How much will AI affect global growth?',
    'Ch.2 Regional Outlooks',
    'Ch.2 Regional Outlooks > East Asia and Pacific',
    'Ch.2 Regional Outlooks > Middle East, North Africa, Afghanistan, and Pakistan',
    'Ch.2 Regional Outlooks > South Asia',
    'Ch.2 Regional Outlooks > Sub-Saharan Africa',
    'Ch.3 A Rising Challenge: Sovereign Debt Levels and Interest Rates in EMDEs',
    'Ch.3 A Rising Challenge: Sovereign Debt Levels and Interest Rates in EMDEs > Box 3.1 The costs of hidden debt',
];

it('routes a region question only to that region', function () use ($paths) {
    $router = new QuestionRouter;

    $route = $router->match($paths, 'What is the growth outlook for South Asia in this report?');
    expect($route['prefixes'])->toBe(['Ch.2 Regional Outlooks > South Asia']);

    $route = $router->match($paths, 'What is the outlook for SSA?');
    expect($route['prefixes'])->toBe(['Ch.2 Regional Outlooks > Sub-Saharan Africa']);

    $route = $router->match($paths, 'growth in the Middle East');
    expect($route['prefixes'])->toBe(['Ch.2 Regional Outlooks > Middle East, North Africa, Afghanistan, and Pakistan']);
});

it('routes chapter, box and executive summary references', function () use ($paths) {
    $router = new QuestionRouter;

    $route = $router->match($paths, 'What does Chapter 3 say about sovereign debt in developing economies?');
    expect($route['prefixes'][0])->toStartWith('Ch.3 A Rising Challenge')
        ->and(QuestionRouter::matches($route, 'Ch.3 A Rising Challenge: Sovereign Debt Levels and Interest Rates in EMDEs > Box 3.1 The costs of hidden debt'))->toBeTrue()
        ->and(QuestionRouter::matches($route, 'Ch.2 Regional Outlooks > South Asia'))->toBeFalse();

    expect($router->match($paths, 'According to the executive summary, what are the main risks?')['prefixes'])
        ->toBe(['Executive Summary']);

    expect($router->match($paths, 'What does box 1.1 conclude?')['prefixes'][0])->toContain('Box 1.1');
});

it('does not route a question that names no section', function () use ($paths) {
    expect((new QuestionRouter)->match($paths, 'How is global inflation expected to evolve?'))->toBeNull();
});
