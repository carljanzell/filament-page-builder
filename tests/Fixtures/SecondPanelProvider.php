<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures;

use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\HeadingBlock;
use Filament\Panel;
use Filament\PanelProvider;

/**
 * A second panel offering a narrower set of blocks, so the registry can be shown not to
 * pool them.
 */
class SecondPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('second')
            ->path('second')
            ->plugin(
                FilamentPageBuilderPlugin::make()
                    ->includeLayoutBlocks(false)
                    ->blocks([HeadingBlock::class]),
            );
    }
}
