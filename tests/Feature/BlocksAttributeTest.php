<?php

use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages\DesignLayoutPage;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\LayoutPage;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Page;
use Livewire\Livewire;

it('reads and writes the column the record names', function (): void {
    $record = LayoutPage::create([
        'title' => 'Layout page',
        'blocks' => [['id' => 'wrong', 'type' => 'heading', 'data' => []]],
        'layout' => [['id' => 'right', 'type' => 'heading', 'data' => ['text' => 'Hi']]],
    ]);

    $canvas = Livewire::test(DesignLayoutPage::class, ['record' => $record->getKey()]);

    expect($canvas->get('blocks.0.id'))->toBe('right');

    $canvas->call('insertBlock', 'banner')->call('save');

    $record->refresh();

    expect($record->layout)->toHaveCount(2)
        ->and($record->blocks)->toHaveCount(1);
});

it('falls back to the panel configuration when the model does not override it', function (): void {
    expect((new Page)->blocksAttribute())->toBe('blocks');
});

it('resolves the configured attribute without a current panel', function (): void {
    expect(FilamentPageBuilderPlugin::configuredBlocksAttribute())->toBe('blocks')
        ->and(FilamentPageBuilderPlugin::configuredBlocksAttribute('fallback'))->toBeString();
});
