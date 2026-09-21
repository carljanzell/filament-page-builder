<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures;

use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\BannerBlock;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\HeadingBlock;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\RestrictedBlock;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\LayoutPageResource;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\PageResource;
use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('testing')
            ->path('testing')
            ->resources([
                PageResource::class,
                LayoutPageResource::class,
            ])
            ->plugin(
                FilamentPageBuilderPlugin::make()
                    ->includeLayoutBlocks(false)
                    ->blocks([
                        HeadingBlock::class,
                        BannerBlock::class,
                        RestrictedBlock::class,
                    ])
                    ->recordModel(Page::class)
                    ->blocksAttribute('blocks'),
            );
    }
}
