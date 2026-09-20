<?php

namespace CarlJanzell\FilamentPageBuilder\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use CarlJanzell\FilamentPageBuilder\Editable;
use Filament\Forms\Components\TextInput;

class ButtonBlock implements InlineEditable, PageBlock
{
    /**
     * @return array<string, Editable>
     */
    public static function editables(): array
    {
        return [
            'label' => Editable::text()->placeholder('Button label'),
        ];
    }

    public static function type(): string
    {
        return 'button';
    }

    public static function label(): string
    {
        return 'Button';
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-cursor-arrow-rays';
    }

    public static function category(): string
    {
        return 'content';
    }

    public static function view(): string
    {
        return 'page-builder::components.button';
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
            TextInput::make('label')->maxLength(255),
            TextInput::make('url')->label('Link')->maxLength(2048),
        ];
    }
}
