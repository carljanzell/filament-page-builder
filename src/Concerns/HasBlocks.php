<?php

namespace CarlJanzell\FilamentPageBuilder\Concerns;

use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Support\Str;

/**
 * Applied by the consuming application to whichever model stores page blocks.
 *
 * The package never owns the table. It only needs the blocks to be an array of
 * typed entries. Nesting is expressed with parent/slot/position on that same
 * flat list — see BlockTree.
 */
trait HasBlocks
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlocks(): array
    {
        $attribute = $this->blocksAttribute();

        return BlockTree::hydrate(is_array($this->{$attribute} ?? null) ? $this->{$attribute} : []);
    }

    /**
     * Give every block a stable id.
     *
     * Identity has to survive reordering: without it a drag, an undo, or Livewire's DOM
     * diffing all fall back to array position, which changes the moment anything moves.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function ensureBlockIds(array $blocks): array
    {
        return array_values(array_map(
            fn (array $block): array => [
                'id' => $block['id'] ?? (string) Str::uuid(),
                ...$block,
            ],
            array_filter($blocks, 'is_array'),
        ));
    }

    /**
     * Which attribute holds the ordered blocks.
     *
     * Declare `protected string $blocksAttribute` on the model to override it; otherwise
     * the panel's `blocksAttribute()` configuration decides, so a consuming application
     * names the column once.
     */
    public function blocksAttribute(): string
    {
        if (property_exists($this, 'blocksAttribute')) {
            return $this->blocksAttribute;
        }

        return FilamentPageBuilderPlugin::configuredBlocksAttribute();
    }
}
