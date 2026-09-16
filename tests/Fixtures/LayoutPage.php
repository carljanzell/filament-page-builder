<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures;

/**
 * A record that names its blocks column something other than the default.
 *
 * Shares the table with Page on purpose: the point under test is the attribute name,
 * not the storage.
 */
class LayoutPage extends Page
{
    protected $table = 'pages';

    protected string $blocksAttribute = 'layout';
}
