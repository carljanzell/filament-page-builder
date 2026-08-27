<?php

namespace CarlJanzell\FilamentPageBuilder\Concerns;

use Illuminate\Support\Str;

/**
 * Applied by the consuming application to whichever model stores page blocks.
 *
 * The package never owns the table. It only needs the blocks to be an array of
 * ['id' => ..., 'type' => ..., 'data' => [...]] entries.
 */
trait HasBlocks
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlocks(): array
    {
        $attribute = $this->blocksAttribute();

        return is_array($this->{$attribute} ?? null) ? $this->{$attribute} : [];
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

    public function blocksAttribute(): string
    {
        return 'blocks';
    }
}
