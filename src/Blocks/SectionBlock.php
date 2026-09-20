<?php

namespace CarlJanzell\FilamentPageBuilder\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\Container;
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use Filament\Forms\Components\Select;

/**
 * A row of columns that other blocks drop into.
 *
 * This is the WordPress-style layout primitive: the page is still made of typed
 * blocks, but a section is how an editor composes them side by side. The package
 * ships it so every consuming app has columns without writing a container themselves;
 * registering another block with type `section` replaces it.
 */
class SectionBlock implements Container, PageBlock
{
    public static function type(): string
    {
        return 'section';
    }

    public static function label(): string
    {
        return 'Section';
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-view-columns';
    }

    public static function category(): string
    {
        return 'layout';
    }

    public static function view(): string
    {
        return 'page-builder::components.section';
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
        return [
            'columns' => 2,
            'ratio' => '1-1',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function slots(array $data): array
    {
        $count = max(1, min(4, (int) ($data['columns'] ?? 2)));

        return array_map(fn (int $i): string => 'col-'.$i, range(0, $count - 1));
    }

    /**
     * @return array<int, mixed>
     */
    public static function schema(): array
    {
        return [
            Select::make('columns')
                ->label('Columns')
                ->options([
                    1 => 'One',
                    2 => 'Two',
                    3 => 'Three',
                    4 => 'Four',
                ])
                ->default(2)
                ->live(),
            Select::make('ratio')
                ->label('Column ratio')
                ->options([
                    '1' => 'Full',
                    '1-1' => '1 / 1',
                    '1-2' => '1 / 2',
                    '2-1' => '2 / 1',
                    '1-1-1' => '1 / 1 / 1',
                    '1-2-1' => '1 / 2 / 1',
                    '1-1-1-1' => '1 / 1 / 1 / 1',
                ])
                ->default('1-1'),
        ];
    }
}
