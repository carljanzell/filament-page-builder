<?php

use CarlJanzell\FilamentPageBuilder\Support\BlockStateNormaliser;

beforeEach(function (): void {
    $this->normaliser = app(BlockStateNormaliser::class);
});

it('renders a rich text document to html', function (): void {
    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Hello']],
        ]],
    ];

    expect($this->normaliser->normaliseData(['body' => $document])['body'])
        ->toContain('Hello');
});

it('walks into nested arrays so editors inside repeaters are handled', function (): void {
    $document = ['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'Nested']],
    ]]];

    $result = $this->normaliser->normaliseData(['items' => [['body' => $document]]]);

    expect($result['items'][0]['body'])->toContain('Nested');
});

it('reduces a declared file field to its stored path', function (): void {
    $result = $this->normaliser->normaliseData(
        ['image' => ['some-uuid' => 'pages/hero.jpg']],
        ['image'],
    );

    expect($result['image'])->toBe('pages/hero.jpg');
});

it('leaves an undeclared array field alone', function (): void {
    $result = $this->normaliser->normaliseData(['image' => ['pages/hero.jpg']]);

    expect($result['image'])->toBe(['pages/hero.jpg']);
});

it('resolves an empty file field to null', function (): void {
    expect($this->normaliser->resolveFileState([]))->toBeNull()
        ->and($this->normaliser->resolveFileState(null))->toBeNull()
        ->and($this->normaliser->resolveFileState(''))->toBe('');
});

it('drops entries that are not blocks', function (): void {
    $blocks = $this->normaliser->normalise([
        ['id' => 'a', 'type' => 'heading', 'data' => ['text' => 'Hi']],
        ['id' => 'b'],
        'not an array',
    ]);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('heading');
});

it('returns nothing for a non array', function (): void {
    expect($this->normaliser->normalise('nope'))->toBe([])
        ->and($this->normaliser->normaliseData('nope'))->toBe([]);
});
