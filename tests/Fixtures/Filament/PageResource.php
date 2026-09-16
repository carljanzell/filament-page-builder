<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament;

use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages\DesignPage;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages\EditPage;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages\ListPages;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Page;
use Filament\Resources\Resource;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    public static bool $canEdit = true;

    public static function canEdit(mixed $record): bool
    {
        return static::$canEdit;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'edit' => EditPage::route('/{record}/edit'),
            'design' => DesignPage::route('/{record}/design'),
        ];
    }
}
