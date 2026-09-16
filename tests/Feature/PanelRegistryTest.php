<?php

use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use Filament\Facades\Filament;

it('keeps each panel to the blocks it registered', function (): void {
    // Panels register their plugins lazily, so both have to be resolved first.
    Filament::getPanel('testing');
    Filament::getPanel('second');

    $registries = app(BlockRegistries::class);

    expect(array_keys($registries->for('testing')->all()))
        ->toBe(['heading', 'banner', 'restricted'])
        ->and(array_keys($registries->for('second')->all()))
        ->toBe(['heading']);
});

it('resolves the registry of the panel being served', function (): void {
    Filament::setCurrentPanel('second');

    expect(array_keys(app(BlockRegistries::class)->current()->all()))->toBe(['heading']);

    Filament::setCurrentPanel('testing');

    expect(array_keys(app(BlockRegistries::class)->current()->all()))
        ->toBe(['heading', 'banner', 'restricted']);
});
