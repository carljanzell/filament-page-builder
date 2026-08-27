<?php

namespace CarlJanzell\FilamentPageBuilder;

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
        if (! class_exists(\Filament\Support\Facades\FilamentAsset::class)) {
            return;
        }

        \Filament\Support\Facades\FilamentAsset::register([
            \Filament\Support\Assets\Js::make('page-builder', __DIR__.'/../resources/js/page-builder.js'),
            \Filament\Support\Assets\Css::make('page-builder', __DIR__.'/../resources/css/page-builder.css'),
        ], static::PACKAGE);
    }
}
