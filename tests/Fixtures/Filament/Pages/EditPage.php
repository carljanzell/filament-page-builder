<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages;

use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\PageResource;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;
}
