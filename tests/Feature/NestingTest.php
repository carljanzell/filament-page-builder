<?php

use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\Blocks\SectionBlock;
use CarlJanzell\FilamentPageBuilder\Blocks\TextBlock;
use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\PageBuilder;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;

require_once __DIR__.'/DesignPageTest.php';

beforeEach(function (): void {
    app(BlockRegistry::class)->register([
        SectionBlock::class,
        TextBlock::class,
    ]);
});

function section(string $id, int $columns = 2): array
{
    return [
        'id' => $id,
        'type' => 'section',
        'data' => ['columns' => $columns, 'ratio' => $columns === 2 ? '1-1' : '1'],
        'parent' => null,
        'slot' => null,
        'position' => 0,
        'settings' => [],
    ];
}

function child(string $id, string $parent, string $slot, string $type = 'text', array $data = []): array
{
    return [
        'id' => $id,
        'type' => $type,
        'data' => $data,
        'parent' => $parent,
        'slot' => $slot,
        'position' => 0,
        'settings' => [],
    ];
}

it('offers layout primitives when the plugin is left on its default', function (): void {
    expect(FilamentPageBuilderPlugin::layoutBlockClasses())->toContain(SectionBlock::class)
        ->and(SectionBlock::slots(['columns' => 3]))->toBe(['col-0', 'col-1', 'col-2']);
});

it('inserts a section with columns already opened', function (): void {
    $canvas = canvas(page())->call('insertBlock', 'section');

    expect($canvas->get('blocks.0.type'))->toBe('section')
        ->and($canvas->get('blocks.0.data.columns'))->toBe(2);

    $canvas->assertSee('Drop a block here')
        ->assertSee('data-fpb-slot="col-0"', escape: false)
        ->assertSee('data-fpb-slot="col-1"', escape: false);
});

it('drops a text box into a column', function (): void {
    $canvas = canvas(page([section('s')]))
        ->call('insertBlock', 'text', 0, 's', 'col-0');

    expect($canvas->get('blocks.1.type'))->toBe('text')
        ->and($canvas->get('blocks.1.parent'))->toBe('s')
        ->and($canvas->get('blocks.1.slot'))->toBe('col-0');

    $canvas->assertSee('data-fpb-field="body"', escape: false);
});

it('inserts into the selected section when the palette is clicked', function (): void {
    $canvas = canvas(page([section('s')]))
        ->call('selectBlock', 's')
        ->call('insertBlock', 'text');

    expect($canvas->get('blocks.1.parent'))->toBe('s')
        ->and($canvas->get('blocks.1.slot'))->toBe('col-0');
});

it('inserts as the next sibling of a selected leaf', function (): void {
    $canvas = canvas(page([
        ['id' => 'a', 'type' => 'heading', 'data' => []],
        ['id' => 'b', 'type' => 'heading', 'data' => []],
    ]))
        ->call('selectBlock', 'a')
        ->call('insertBlock', 'banner');

    expect(array_column($canvas->get('blocks'), 'type'))
        ->toBe(['heading', 'banner', 'heading']);
});

it('moves a block between columns', function (): void {
    $canvas = canvas(page([
        section('s'),
        child('t', 's', 'col-0', 'text', ['body' => 'Hi']),
    ]))->call('moveBlock', 't', 0, 's', 'col-1');

    expect($canvas->get('blocks.1.slot'))->toBe('col-1')
        ->and($canvas->get('blocks.1.parent'))->toBe('s');
});

it('will not drop a section into one of its own columns', function (): void {
    $canvas = canvas(page([
        section('s'),
        child('t', 's', 'col-0'),
    ]))->call('moveBlock', 's', 0, 's', 'col-0');

    expect($canvas->get('blocks.0.parent'))->toBeNull();

    $canvas->assertSet('isDirty', false);
});

it('duplicates a section together with the blocks inside it', function (): void {
    $canvas = canvas(page([
        section('s'),
        child('t', 's', 'col-0', 'text', ['body' => 'Hi']),
    ]))->call('duplicateBlock', 's');

    expect($canvas->get('blocks'))->toHaveCount(4)
        ->and($canvas->get('blocks.2.type'))->toBe('section')
        ->and($canvas->get('blocks.3.parent'))->toBe($canvas->get('blocks.2.id'))
        ->and($canvas->get('blocks.3.data.body'))->toBe('Hi');
});

it('removes the children of a deleted section', function (): void {
    $canvas = canvas(page([
        section('s'),
        child('t', 's', 'col-0'),
        ['id' => 'r', 'type' => 'heading', 'data' => []],
    ]))->call('removeBlock', 's');

    expect(array_column($canvas->get('blocks'), 'id'))->toBe(['r']);
});

it('clears the selection when the selected block was inside a removed section', function (): void {
    canvas(page([
        section('s'),
        child('t', 's', 'col-0'),
    ]))
        ->call('selectBlock', 't')
        ->call('removeBlock', 's')
        ->assertSet('selectedId', null);
});

it('does not treat an empty section as content worth confirming', function (): void {
    $canvas = canvas(page())->call('insertBlock', 'section');

    expect($canvas->instance()->rootBlocks[0]['hasContent'])->toBeFalse();
});

it('renders nested children only inside their section on the public page', function (): void {
    $html = view('page-builder::components.blocks', [
        'blocks' => [
            section('s'),
            child('t', 's', 'col-0', 'text', ['body' => 'Inside the column']),
            ['id' => 'r', 'type' => 'heading', 'data' => ['text' => 'Root heading']],
        ],
    ])->render();

    expect($html)->toContain('Inside the column')
        ->and($html)->toContain('Root heading')
        ->and($html)->toContain('fpb-section')
        ->and($html)->not->toContain('data-fpb-field')
        ->and($html)->not->toContain('Drop a block here');
});

it('does not emit editing attributes from a public nested render', function (): void {
    PageBuilder::idle();

    $html = view('page-builder::components.blocks', [
        'blocks' => [
            section('s'),
            child('t', 's', 'col-0', 'text', ['body' => 'Hi']),
        ],
    ])->render();

    expect(PageBuilder::isEditing())->toBeFalse()
        ->and($html)->not->toContain('contenteditable');
});

it('walks the stored tree in document order after a nested save', function (): void {
    $record = page([section('s')]);

    canvas($record)->call('insertBlock', 'text', 0, 's', 'col-1')->call('save');

    $stored = $record->fresh()->blocks;

    expect($stored[1]['parent'])->toBe('s')
        ->and($stored[1]['slot'])->toBe('col-1')
        ->and(array_column(BlockTree::childrenOf(BlockTree::hydrate($stored), 's', 'col-1'), 'type'))
        ->toBe(['text']);
});

it('renders every column of a section on the public page', function (): void {
    $html = view('page-builder::components.blocks', [
        'blocks' => [
            section('s'),
            child('a', 's', 'col-0', 'text', ['body' => 'First column, first block']),
            child('b', 's', 'col-0', 'text', ['body' => 'First column, second block']),
            child('c', 's', 'col-1', 'text', ['body' => 'Second column']),
        ],
    ])->render();

    // A leaf used to pop the section's own render context, so every column after the
    // first came back empty — on the public site only, while the canvas looked right.
    expect($html)->toContain('First column, first block')
        ->and($html)->toContain('First column, second block')
        ->and($html)->toContain('Second column');
});

it('keeps a column a single grid item on the public page', function (): void {
    $html = view('page-builder::components.blocks', [
        'blocks' => [
            section('s'),
            child('a', 's', 'col-0', 'text', ['body' => 'One']),
            child('b', 's', 'col-0', 'text', ['body' => 'Two']),
            child('c', 's', 'col-1', 'text', ['body' => 'Three']),
        ],
    ])->render();

    // .fpb-section is a grid. Two blocks in one column must sit inside one .fpb-slot,
    // or the second takes the next column's cell.
    expect(substr_count($html, 'class="fpb-slot"'))->toBe(2)
        ->and($html)->not->toContain('data-fpb-slot=');

    preg_match('/class="fpb-slot">(.*?)Three/s', $html, $first);

    expect($first[1] ?? '')->toContain('One')->toContain('Two');
});

it('renders a section nested inside a column', function (): void {
    $html = view('page-builder::components.blocks', [
        'blocks' => [
            section('outer'),
            [...section('inner'), 'parent' => 'outer', 'slot' => 'col-1'],
            child('deep', 'inner', 'col-1', 'text', ['body' => 'Two levels down']),
            child('beside', 'outer', 'col-0', 'text', ['body' => 'Beside the inner section']),
        ],
    ])->render();

    expect($html)->toContain('Two levels down')
        ->and($html)->toContain('Beside the inner section')
        ->and(substr_count($html, 'fpb-section'))->toBe(2);
});

it('leaves no render context behind after a public render', function (): void {
    view('page-builder::components.blocks', [
        'blocks' => [
            section('s'),
            child('a', 's', 'col-0', 'text', ['body' => 'Hi']),
            child('b', 's', 'col-1', 'text', ['body' => 'There']),
        ],
    ])->render();

    // Every push has a matching pop, so nothing leaks into the next render on the page.
    expect(PageBuilder::isEditing())->toBeFalse()
        ->and(PageBuilder::currentBlockId())->toBeNull();
});

it('still shows the empty drop well on the canvas', function (): void {
    canvas(page([section('s')]))
        ->assertSee('Drop a block here')
        ->assertSee('data-fpb-slot="col-0"', escape: false)
        ->assertSee('data-fpb-slot="col-1"', escape: false);
});
