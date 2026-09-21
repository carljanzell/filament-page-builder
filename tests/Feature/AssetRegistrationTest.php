<?php

use CarlJanzell\FilamentPageBuilder\PageBuilderServiceProvider;
use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;

/** @param  array<int, Asset>  $assets */
function pageBuilderAsset(array $assets, string $id): ?Asset
{
    foreach ($assets as $asset) {
        if ($asset->getId() === $id) {
            return $asset;
        }
    }

    return null;
}

/**
 * The canvas is driven entirely by these files, and Anime.js is vendored rather than
 * pulled from a bundler or a CDN — so nothing at build time would notice one of them
 * going missing. It would simply stop working, quietly, in whatever application
 * installed the release.
 */
it('registers the canvas scripts and ships the files they point at', function (string $id): void {
    $script = pageBuilderAsset(FilamentAsset::getScripts([PageBuilderServiceProvider::PACKAGE]), $id);

    expect($script)->not->toBeNull()
        ->and($script->getPath())->toBeFile();
})->with(['page-builder', 'page-builder-anime']);

it('registers the canvas stylesheet and ships the file it points at', function (): void {
    $style = pageBuilderAsset(FilamentAsset::getStyles([PageBuilderServiceProvider::PACKAGE]), 'page-builder');

    expect($style)->not->toBeNull()
        ->and($style->getPath())->toBeFile();
});
