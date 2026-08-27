<?php

namespace CarlJanzell\FilamentPageBuilder;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

class PageBuilderServiceProvider extends ServiceProvider
{
    public const PACKAGE = 'carljanzell/filament-page-builder';

    public function register(): void
    {
        $this->app->singleton(BlockRegistry::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'page-builder');

        $this->registerAssets();
    }

    /**
     * Register the canvas assets under this package's namespace.
     *
     * Written by hand and shipped unminified on purpose: requiring consumers to run a
     * JS build would make the package unusable on hosts without Node, which is exactly
     * the situation it was written for.
     */
    protected function registerAssets(): void
    {
        if (! class_exists(FilamentAsset::class)) {
            return;
        }

        FilamentAsset::register([
            Js::make('page-builder', __DIR__.'/../resources/js/page-builder.js'),
            Css::make('page-builder', __DIR__.'/../resources/css/page-builder.css'),
        ], static::PACKAGE);
    }
}
