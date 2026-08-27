<?php

namespace CarlJanzell\FilamentPageBuilder;

use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use Filament\Contracts\Plugin;
use Filament\Panel;

class FilamentPageBuilderPlugin implements Plugin
{
    /**
     * @var array<int, class-string<PageBlock>>
     */
    protected array $blocks = [];

    protected ?string $recordModel = null;

    protected string $blocksAttribute = 'blocks';

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'page-builder';
    }

    /**
     * Block types this application makes available to editors.
     *
     * @param  array<int, class-string<PageBlock>>  $blocks
     */
    public function blocks(array $blocks): static
    {
        $this->blocks = $blocks;

        return $this;
    }

    /**
     * The model whose records the builder edits.
     *
     * The package deliberately does not ship a model or a migration — the application
     * owns its own schema, and only has to point the builder at it.
     *
     * @param  class-string  $model
     */
    public function recordModel(string $model): static
    {
        $this->recordModel = $model;

        return $this;
    }

    /**
     * The JSON attribute on that model holding the ordered blocks.
     */
    public function blocksAttribute(string $attribute): static
    {
        $this->blocksAttribute = $attribute;

        return $this;
    }

    public function getRecordModel(): ?string
    {
        return $this->recordModel;
    }

    public function getBlocksAttribute(): string
    {
        return $this->blocksAttribute;
    }

    public function getRegistry(): BlockRegistry
    {
        return app(BlockRegistry::class);
    }

    public function register(Panel $panel): void
    {
        $this->getRegistry()->register($this->blocks);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
