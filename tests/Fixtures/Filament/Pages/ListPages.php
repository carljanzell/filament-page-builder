<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages;

use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\PageResource;
use Filament\Resources\Pages\ListRecords;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;
}
