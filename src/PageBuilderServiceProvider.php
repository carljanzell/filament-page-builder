<?php

namespace CarlJanzell\FilamentPageBuilder;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class PageBuilderServiceProvider extends ServiceProvider
{
    public const PACKAGE = 'carljanzell/filament-page-builder';

    public function register(): void
    {
        $this->app->singleton(BlockRegistries::class);

        // Resolving BlockRegistry keeps working and now yields whichever panel is being
        // served, so nothing that injects it has to know panels exist.
        $this->app->bind(
            BlockRegistry::class,
            fn ($app): BlockRegistry => $app->make(BlockRegistries::class)->current(),
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'page-builder');

        $this->registerDirectives();
        $this->registerAssets();
    }

    /**
     * `@editable('heading')` inside a block's own markup.
     *
     * It expands to editing attributes while the canvas is rendering and to nothing at
     * all anywhere else, which is what lets one Blade component serve both the public
     * page and the editor without a second renderer to drift from the first.
     */
    protected function registerDirectives(): void
    {
        Blade::directive(
            'editable',
            fn (string $expression): string => "<?php echo \CarlJanzell\FilamentPageBuilder\PageBuilder::editableAttributes({$expression}); ?>",
        );
    }

    /**
     * Register the canvas assets under this package's namespace.
     *
     * Written by hand and shipped unminified on purpose: requiring consumers to run a
     * JS build would make the package unusable on hosts without Node, which is exactly
     * the situation it was written for. Anime.js is vendored in for the same reason —
     * a checked-in UMD build needs no bundler and no CDN at runtime. The canvas degrades
     * to no animation at all if it is missing, so it is a nicety, not a hard dependency.
     */
    protected function registerAssets(): void
    {
        if (! class_exists(FilamentAsset::class)) {
            return;
        }

        FilamentAsset::register([
            Js::make('page-builder-anime', __DIR__.'/../resources/js/vendor/anime.min.js'),
            Js::make('page-builder', __DIR__.'/../resources/js/page-builder.js'),
            Css::make('page-builder', __DIR__.'/../resources/css/page-builder.css'),
        ], static::PACKAGE);
    }
}
