<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\Pages;

use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Filament\PageResource;

class DesignPage extends BaseDesignPage
{
    protected static string $resource = PageResource::class;
}
