<?php

use CarlJanzell\FilamentPageBuilder\Support\BlockHistory;

require_once __DIR__.'/DesignPageTest.php';

it('starts with nothing to undo', function (): void {
    canvas(page([block('a')]))
        ->assertSet('canUndo', false)
        ->assertSet('canRedo', false);
});

it('undoes a reorder', function (): void {
    $canvas = canvas(page([block('a'), block('b')]))->call('moveBlock', 'a', 2);

    expect(ids($canvas))->toBe(['b', 'a']);

    $canvas->call('undo');

    expect(ids($canvas))->toBe(['a', 'b']);
    $canvas->assertSet('canUndo', false)->assertSet('canRedo', true);
});

it('redoes what it undid', function (): void {
    $canvas = canvas(page([block('a'), block('b')]))
        ->call('moveBlock', 'a', 2)
        ->call('undo')
        ->call('redo');

    expect(ids($canvas))->toBe(['b', 'a']);
});

it('undoes an insert, a duplicate and a delete', function (): void {
    $canvas = canvas(page([block('a')]))->call('insertBlock', 'banner');
    expect($canvas->get('blocks'))->toHaveCount(2);

    $canvas->call('undo');
    expect(ids($canvas))->toBe(['a']);

    $canvas->call('duplicateBlock', 'a')->call('undo');
    expect(ids($canvas))->toBe(['a']);

    $canvas->call('removeBlock', 'a')->call('undo');
    expect(ids($canvas))->toBe(['a']);
});

it('undoes a content edit without the inspector writing it back', function (): void {
    $canvas = canvas(page([block('a', data: ['text' => 'Before'])]))
        ->call('selectBlock', 'a')
        ->set('blockData.text', 'After');

    expect($canvas->get('blocks.0.data.text'))->toBe('After');

    $canvas->call('undo');

    expect($canvas->get('blocks.0.data.text'))->toBe('Before')
        ->and($canvas->get('blockData.text'))->toBe('Before');
});

it('deselects a block that the undone state does not contain', function (): void {
    $canvas = canvas(page([block('a')]))->call('insertBlock', 'banner');

    $inserted = $canvas->get('selectedId');
    expect($inserted)->not->toBeNull();

    $canvas->call('undo')->assertSet('selectedId', null);
});

it('abandons the redo branch once a new change is made', function (): void {
    $canvas = canvas(page([block('a'), block('b')]))
        ->call('moveBlock', 'a', 2)
        ->call('undo')
        ->assertSet('canRedo', true)
        ->call('removeBlock', 'b')
        ->assertSet('canRedo', false);
});

it('ignores undo and redo when there is nothing to travel to', function (): void {
    $canvas = canvas(page([block('a')]))->call('undo')->call('redo');

    expect(ids($canvas))->toBe(['a']);
    $canvas->assertSet('isDirty', false);
});

it('does not record a commit that changes nothing', function (): void {
    canvas(page([block('a', data: ['text' => 'Same'])]))
        ->call('selectBlock', 'a')
        ->set('blockData.text', 'Same')
        ->assertSet('canUndo', false);
});

it('caps how far back the history goes', function (): void {
    $history = new BlockHistory(session()->driver(), 'owner');

    foreach (range(1, BlockHistory::LIMIT + 5) as $step) {
        $history->push([['id' => (string) $step]]);
    }

    $current = [['id' => 'now']];

    foreach (range(1, BlockHistory::LIMIT) as $ignored) {
        $current = $history->undo($current);
    }

    expect($history->canUndo())->toBeFalse()
        ->and($current[0]['id'])->toBe('6');
});

it('discards the history of a different canvas', function (): void {
    $session = session()->driver();

    (new BlockHistory($session, 'first'))->push([['id' => 'a']]);

    expect((new BlockHistory($session, 'second'))->canUndo())->toBeFalse();
});
