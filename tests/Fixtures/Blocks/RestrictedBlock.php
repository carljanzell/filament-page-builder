<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use Filament\Forms\Components\Textarea;

/**
 * A block registered for everyone but authorable only when the test says so.
 *
 * Stands in for the real case: raw markup an ordinary editor may reorder but not write.
 */
class RestrictedBlock implements PageBlock
{
    public static bool $visible = false;

    public static function type(): string
    {
        return 'restricted';
    }

    public static function label(): string
    {
        return 'Restricted';
    }

    public static function icon(): ?string
    {
        return null;
    }

    public static function view(): string
    {
        return 'blocks.restricted';
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
        return static::$visible;
    }

    /**
     * @return array<int, mixed>
     */
    public static function schema(): array
    {
        return [
            Textarea::make('html'),
        ];
    }
}
