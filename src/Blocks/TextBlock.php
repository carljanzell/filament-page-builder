<?php

namespace CarlJanzell\FilamentPageBuilder\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use CarlJanzell\FilamentPageBuilder\Editable;
use Filament\Forms\Components\Textarea;

/**
 * A text box you drop anywhere and type into — the WordPress "paragraph" primitive.
 */
class TextBlock implements InlineEditable, PageBlock
{
    /**
     * @return array<string, Editable>
     */
    public static function editables(): array
    {
        return [
            'body' => Editable::text()->multiline()->placeholder('Write something'),
        ];
    }

    public static function type(): string
    {
        return 'text';
    }

    public static function label(): string
    {
        return 'Text';
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-bars-3-bottom-left';
    }

    public static function category(): string
    {
        return 'content';
    }

    public static function view(): string
    {
        return 'page-builder::components.text';
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
        return [
            Textarea::make('body')->rows(4),
        ];
    }
}
