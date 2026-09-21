<?php

namespace CarlJanzell\FilamentPageBuilder\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use CarlJanzell\FilamentPageBuilder\Editable;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;

class ImageBlock implements InlineEditable, PageBlock
{
    /**
     * @return array<string, Editable>
     */
    public static function editables(): array
    {
        return [
            'alt' => Editable::text()->placeholder('Describe the image'),
        ];
    }

    public static function type(): string
    {
        return 'image';
    }

    public static function label(): string
    {
        return 'Image';
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-photo';
    }

    public static function category(): string
    {
        return 'content';
    }

    public static function view(): string
    {
        return 'page-builder::components.image';
    }

    /**
     * @return array<int, string>
     */
    public static function fileFields(): array
    {
        return ['src'];
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
            FileUpload::make('src')
                ->label('Image')
                ->image()
                ->disk('public')
                ->directory('pages'),
            TextInput::make('alt')->label('Alt text')->maxLength(255),
        ];
    }
}
