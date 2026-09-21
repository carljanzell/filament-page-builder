<?php

use CarlJanzell\FilamentPageBuilder\Support\BlockTree;

function tree(array $blocks): array
{
    return BlockTree::hydrate($blocks);
}

function node(string $id, string $type = 'heading', ?string $parent = null, ?string $slot = null, array $data = []): array
{
    return ['id' => $id, 'type' => $type, 'data' => $data, 'parent' => $parent, 'slot' => $slot];
}

it('turns a flat v1 list into roots without changing order', function (): void {
    $blocks = tree([
        ['id' => 'a', 'type' => 'heading', 'data' => ['text' => 'Hi']],
        ['id' => 'b', 'type' => 'banner', 'data' => []],
    ]);

    expect(array_column($blocks, 'id'))->toBe(['a', 'b'])
        ->and($blocks[0]['parent'])->toBeNull()
        ->and($blocks[0]['position'])->toBe(0)
        ->and($blocks[1]['position'])->toBe(1)
        ->and($blocks[0]['settings'])->toBe([]);
});

it('keeps application keys while hydrating', function (): void {
    $blocks = tree([[
        'id' => 'a',
        'type' => 'heading',
        'data' => [],
        'anchor' => 'intro',
    ]]);

    expect($blocks[0]['anchor'])->toBe('intro');
});

it('walks children in slot then position order', function (): void {
    $blocks = tree([
        node('s', 'section'),
        node('b', 'text', 's', 'col-1'),
        node('a', 'text', 's', 'col-0'),
        node('c', 'text', 's', 'col-0'),
    ]);

    expect(array_column(BlockTree::childrenOf($blocks, 's', 'col-0'), 'id'))->toBe(['a', 'c'])
        ->and(array_column(BlockTree::childrenOf($blocks, 's'), 'id'))->toBe(['a', 'c', 'b']);
});

it('moves a root the same way the old flat list did', function (): void {
    $blocks = tree([node('a'), node('b'), node('c')]);

    expect(array_column(BlockTree::move($blocks, 'a', 2), 'id'))->toBe(['b', 'a', 'c'])
        ->and(array_column(BlockTree::move($blocks, 'c', 0), 'id'))->toBe(['c', 'a', 'b'])
        ->and(array_column(BlockTree::move($blocks, 'a', 99), 'id'))->toBe(['b', 'c', 'a']);
});

it('drops a block into a section slot', function (): void {
    $blocks = tree([node('s', 'section'), node('t', 'text')]);
    $moved = BlockTree::move($blocks, 't', 0, 's', 'col-0');

    expect($moved[1]['id'])->toBe('t')
        ->and($moved[1]['parent'])->toBe('s')
        ->and($moved[1]['slot'])->toBe('col-0')
        ->and($moved[1]['position'])->toBe(0);
});

it('refuses to nest a block inside itself', function (): void {
    $blocks = tree([
        node('s', 'section'),
        node('inner', 'section', 's', 'col-0'),
    ]);

    expect(BlockTree::move($blocks, 's', 0, 'inner', 'col-0'))->toBe($blocks)
        ->and(BlockTree::move($blocks, 's', 0, 's', 'col-0'))->toBe($blocks);
});

it('refuses to nest deeper than the cap', function (): void {
    $blocks = tree([
        node('a', 'section'),
        node('b', 'section', 'a', 'col-0'),
        node('c', 'section', 'b', 'col-0'),
        node('d', 'section', 'c', 'col-0'),
        node('e', 'section', 'd', 'col-0'),
        node('leaf', 'text'),
    ]);

    expect(BlockTree::depthOf($blocks, 'e'))->toBe(5)
        ->and(BlockTree::move($blocks, 'leaf', 0, 'e', 'col-0'))->toBe($blocks);
});

it('inserts at a sibling index and shifts the rest', function (): void {
    $blocks = tree([node('a'), node('b')]);
    $next = BlockTree::insert($blocks, node('x', 'banner'), 1);

    expect(array_column($next, 'id'))->toBe(['a', 'x', 'b'])
        ->and($next[1]['position'])->toBe(1);
});

it('removes a container and its descendants', function (): void {
    $blocks = tree([
        node('s', 'section'),
        node('t', 'text', 's', 'col-0'),
        node('r', 'heading'),
    ]);

    expect(array_column(BlockTree::remove($blocks, 's'), 'id'))->toBe(['r']);
});

it('duplicates a subtree with fresh ids and remapped parents', function (): void {
    $blocks = tree([
        node('s', 'section'),
        node('t', 'text', 's', 'col-0', ['body' => 'Hi']),
        node('r', 'heading'),
    ]);

    $next = BlockTree::duplicate($blocks, 's');

    expect($next)->toHaveCount(5)
        ->and($next[2]['type'])->toBe('section')
        ->and($next[2]['id'])->not->toBe('s')
        ->and($next[3]['parent'])->toBe($next[2]['id'])
        ->and($next[3]['data']['body'])->toBe('Hi')
        ->and($next[4]['id'])->toBe('r');
});
