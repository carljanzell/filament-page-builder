<?php

use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;

require_once __DIR__.'/DesignPageTest.php';

it('writes a style token onto the selected block', function (): void {
    $canvas = canvas(page([block('a')]))
        ->call('selectBlock', 'a')
        ->set('blockSettings.padding', 'lg');

    expect($canvas->get('blocks.0.settings.padding'))->toBe('lg');
    $canvas->assertSet('isDirty', true);
});

it('refuses a token value the theme does not offer', function (): void {
    $canvas = canvas(page([block('a')]))
        ->call('selectBlock', 'a')
        ->set('blockSettings.padding', '13px lime');

    expect($canvas->get('blocks.0.settings'))->toBe([])
        ->and($canvas->get('isDirty'))->toBeFalse();
});

it('shows the token attributes on the canvas wrapper', function (): void {
    canvas(page([[
        'id' => 'a',
        'type' => 'heading',
        'data' => ['text' => 'Hi'],
        'settings' => ['padding' => 'lg', 'align' => 'center'],
    ]]))
        ->assertSee('data-fpb-padding="lg"', escape: false)
        ->assertSee('data-fpb-align="center"', escape: false);
});

it('ships a default token set the application can replace', function (): void {
    expect(FilamentPageBuilderPlugin::defaultStyleTokens())->toHaveKeys([
        'padding',
        'background',
        'width',
        'align',
    ]);
});

it('persists settings through a save', function (): void {
    $record = page([block('a', data: ['text' => 'Hi'])]);

    canvas($record)
        ->call('selectBlock', 'a')
        ->set('blockSettings.width', 'narrow')
        ->call('save');

    expect($record->fresh()->blocks[0]['settings']['width'])->toBe('narrow');
});
