<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages;

use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\LayoutPageResource;

class DesignLayoutPage extends BaseDesignPage
{
    protected static string $resource = LayoutPageResource::class;
}
