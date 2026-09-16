<?php

use CarlJanzell\FilamentPageBuilder\Editable;
use CarlJanzell\FilamentPageBuilder\PageBuilder;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\RestrictedBlock;

require_once __DIR__.'/DesignPageTest.php';

/* ── The markup ────────────────────────────────────────── */

it('marks a declared field as editable on the canvas', function (): void {
    canvas(page([block('a', data: ['text' => 'Hello'])]))
        ->assertSee('data-fpb-field="text"', escape: false)
        ->assertSee('data-fpb-block="a"', escape: false)
        ->assertSee('contenteditable="plaintext-only"', escape: false)
        ->assertSee('data-fpb-placeholder="Write a heading"', escape: false);
});

it('says whether Enter commits or inserts a line', function (): void {
    $markup = canvas(page([block('a'), block('b', 'banner')]))->html();

    expect($markup)->toContain('data-fpb-multiline="false"')
        ->and($markup)->toContain('data-fpb-multiline="true"');
});

it('emits nothing for a field the block did not declare', function (): void {
    canvas(page([block('b', 'banner')]))
        ->assertDontSee('data-fpb-field="image"', escape: false)
        ->assertSee('not declared editable');
});

it('emits nothing at all away from the canvas', function (): void {
    PageBuilder::idle();

    $html = view('components.blocks.heading', ['data' => ['text' => 'Hello']])->render();

    expect($html)->toContain('Hello')
        ->and($html)->not->toContain('data-fpb-field')
        ->and($html)->not->toContain('contenteditable');
});

it('reports no editable fields for a block that did not opt in', function (): void {
    expect(PageBuilder::editablesFor('restricted'))->toBe([])
        ->and(PageBuilder::editablesFor('nope'))->toBe([])
        ->and(PageBuilder::editablesFor('heading'))->toHaveKey('text');
});

/* ── Writing ───────────────────────────────────────────── */

it('writes an edit made on the page', function (): void {
    $canvas = canvas(page([block('a', data: ['text' => 'Before'])]))
        ->call('setBlockField', 'a', 'text', 'After');

    expect($canvas->get('blocks.0.data.text'))->toBe('After');
    $canvas->assertSet('isDirty', true)->assertSet('canUndo', true);
});

it('shows the edit in the inspector when that block is selected', function (): void {
    canvas(page([block('a', data: ['text' => 'Before'])]))
        ->call('selectBlock', 'a')
        ->call('setBlockField', 'a', 'text', 'After')
        ->assertSet('blockData.text', 'After');
});

it('leaves the inspector alone when a different block is selected', function (): void {
    canvas(page([block('a', data: ['text' => 'One']), block('b', data: ['text' => 'Two'])]))
        ->call('selectBlock', 'b')
        ->call('setBlockField', 'a', 'text', 'Edited')
        ->assertSet('blockData.text', 'Two');
});

it('records nothing when the value has not changed', function (): void {
    canvas(page([block('a', data: ['text' => 'Same'])]))
        ->call('setBlockField', 'a', 'text', 'Same')
        ->assertSet('isDirty', false)
        ->assertSet('canUndo', false);
});

it('undoes an edit made on the page', function (): void {
    $canvas = canvas(page([block('a', data: ['text' => 'Before'])]))
        ->call('setBlockField', 'a', 'text', 'After')
        ->call('undo');

    expect($canvas->get('blocks.0.data.text'))->toBe('Before');
});

it('persists an edit made on the page', function (): void {
    $record = page([block('a', data: ['text' => 'Before'])]);

    canvas($record)->call('setBlockField', 'a', 'text', 'After')->call('save');

    expect($record->fresh()->blocks[0]['data']['text'])->toBe('After');
});

/* ── What it refuses ───────────────────────────────────── */

it('refuses a field the block never declared editable', function (): void {
    canvas(page([block('b', 'banner', ['image' => 'kept.jpg'])]))
        ->call('setBlockField', 'b', 'image', 'smuggled.jpg')
        ->assertSet('blocks.0.data.image', 'kept.jpg')
        ->assertSet('isDirty', false);
});

it('refuses a value of the wrong kind', function (): void {
    canvas(page([block('a', data: ['text' => 'Before'])]))
        ->call('setBlockField', 'a', 'text', ['not', 'a', 'string'])
        ->assertSet('blocks.0.data.text', 'Before')
        ->assertSet('isDirty', false);
});

it('refuses to write into a block the user may not author', function (): void {
    RestrictedBlock::$visible = false;

    canvas(page([block('a', 'restricted', ['html' => 'kept'])]))
        ->call('setBlockField', 'a', 'html', 'smuggled')
        ->assertSet('blocks.0.data.html', 'kept')
        ->assertSet('isDirty', false);
});

it('refuses to write into a block that is not on the page', function (): void {
    canvas(page([block('a')]))
        ->call('setBlockField', 'ghost', 'text', 'nope')
        ->assertSet('isDirty', false);
});

/* ── The declaration itself ────────────────────────────── */

it('describes what each kind accepts', function (): void {
    expect(Editable::text()->accepts('a string'))->toBeTrue()
        ->and(Editable::text()->accepts(['array']))->toBeFalse()
        ->and(Editable::richText()->accepts('<p>html</p>'))->toBeTrue()
        ->and(Editable::text()->isMultiline())->toBeFalse()
        ->and(Editable::text()->multiline()->isMultiline())->toBeTrue()
        ->and(Editable::text()->placeholder('Hi')->getPlaceholder())->toBe('Hi');
});

it('keeps an empty editable field on the page while editing', function (): void {
    PageBuilder::idle();

    expect(PageBuilder::shows('text', ''))->toBeFalse()
        ->and(PageBuilder::shows('text', 'Filled'))->toBeTrue();

    PageBuilder::editing('a', 'heading');

    expect(PageBuilder::shows('text', ''))->toBeTrue()
        ->and(PageBuilder::shows('untouched', ''))->toBeFalse();

    PageBuilder::idle();
});
