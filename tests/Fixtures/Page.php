<?php

namespace CarlJanzell\FilamentPageBuilder\Tests\Fixtures;

use CarlJanzell\FilamentPageBuilder\Concerns\HasBlocks;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasBlocks;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'layout' => 'array',
        ];
    }
}
