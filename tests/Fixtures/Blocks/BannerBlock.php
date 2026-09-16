<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use CarlJanzell\FilamentPageBuilder\Editable;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;

class BannerBlock implements InlineEditable, PageBlock
{
    /**
     * @return array<string, Editable>
     */
    public static function editables(): array
    {
        return [
            'caption' => Editable::text()->multiline(),
        ];
    }

    public static function type(): string
    {
        return 'banner';
    }

    public static function label(): string
    {
        return 'Banner';
    }

    public static function icon(): ?string
    {
        return null;
    }

    public static function view(): string
    {
        return 'blocks.banner';
    }

    /**
     * @return array<int, string>
     */
    public static function fileFields(): array
    {
        return ['image'];
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
            TextInput::make('caption')->maxLength(255),
            FileUpload::make('image'),
        ];
    }
}
