<?php

namespace CarlJanzell\FilamentPageBuilder\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;

class DividerBlock implements PageBlock
{
    public static function type(): string
    {
        return 'divider';
    }

    public static function label(): string
    {
        return 'Divider';
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-minus';
    }

    public static function category(): string
    {
        return 'design';
    }

    public static function view(): string
    {
        return 'page-builder::components.divider';
    }

    /**
     * @return array<int, string>
     */
    public static function fileFields(): array
    {
        return [];
    }

    public static function isVisible(): bool
    {
        return true;
    }

    /**
     * @return array<int, mixed>
     */
    public static function schema(): array
    {
        return [];
    }
}
