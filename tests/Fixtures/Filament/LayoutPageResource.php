<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament;

use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages\DesignLayoutPage;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages\ListLayoutPages;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\LayoutPage;
use Filament\Resources\Resource;

class LayoutPageResource extends Resource
{
    protected static ?string $model = LayoutPage::class;

    public static function canEdit(mixed $record): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListLayoutPages::route('/'),
            'design' => DesignLayoutPage::route('/{record}/design'),
        ];
    }
}
