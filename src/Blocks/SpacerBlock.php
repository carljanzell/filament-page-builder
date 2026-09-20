<?php

namespace CarlJanzell\FilamentPageBuilder\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use Filament\Forms\Components\Select;

class SpacerBlock implements PageBlock
{
    public static function type(): string
    {
        return 'spacer';
    }

    public static function label(): string
    {
        return 'Spacer';
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-arrows-up-down';
    }

    public static function category(): string
    {
        return 'design';
    }

    public static function view(): string
    {
        return 'page-builder::components.spacer';
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
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return ['height' => 'md'];
    }

    /**
     * @return array<int, mixed>
     */
    public static function schema(): array
    {
        return [
            Select::make('height')
                ->options([
                    'sm' => 'Small',
                    'md' => 'Medium',
                    'lg' => 'Large',
                    'xl' => 'Extra large',
                ])
                ->default('md'),
        ];
    }
}
