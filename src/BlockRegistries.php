<?php

namespace CarlJanzell\FilamentPageBuilder;

use Filament\Facades\Filament;

/**
 * One registry per panel.
 *
 * A single shared registry merges the block sets of every panel that registers the
 * plugin, so an admin panel and, say, a departmental panel would each offer the other's
 * blocks. Registration is a per-panel act; the registry has to be too.
 */
class BlockRegistries
{
    /**
     * @var array<string, BlockRegistry>
     */
    protected array $registries = [];

    public function for(string $panel): BlockRegistry
    {
        return $this->registries[$panel] ??= new BlockRegistry;
    }

    /**
     * The registry belonging to whichever panel is being served.
     *
     * Falls back to the default panel: block content is also rendered on the public site,
     * where no panel is current but the block types are still the application's.
     */
    public function current(): BlockRegistry
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        return $this->for($panel?->getId() ?? 'default');
    }
}
