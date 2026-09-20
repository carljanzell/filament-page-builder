<?php

namespace CarlJanzell\FilamentPageBuilder;

use CarlJanzell\FilamentPageBuilder\Blocks\ButtonBlock;
use CarlJanzell\FilamentPageBuilder\Blocks\DividerBlock;
use CarlJanzell\FilamentPageBuilder\Blocks\ImageBlock;
use CarlJanzell\FilamentPageBuilder\Blocks\SectionBlock;
use CarlJanzell\FilamentPageBuilder\Blocks\SpacerBlock;
use CarlJanzell\FilamentPageBuilder\Blocks\TextBlock;
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Throwable;

class FilamentPageBuilderPlugin implements Plugin
{
    /**
     * @var array<int, class-string<PageBlock>>
     */
    protected array $blocks = [];

    protected ?string $recordModel = null;

    protected string $blocksAttribute = 'blocks';

    protected ?string $canvasStylesView = null;

    protected bool $includeLayoutBlocks = true;

    /**
     * Constrained style knobs, keyed by token name.
     *
     * @var array<string, array<int|string, string>>
     */
    protected array $styleTokens = [];

    protected ?string $panelId = null;

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

    /**
     * A view rendered inside the canvas, before the blocks.
     *
     * The package styles the builder chrome but knows nothing about how a consuming
     * application styles its own blocks. This is where the application injects its
     * design tokens and block stylesheet so the canvas matches the public site.
     */
    public function canvasStylesView(?string $view): static
    {
        $this->canvasStylesView = $view;

        return $this;
    }

    public function getCanvasStylesView(): ?string
    {
        return $this->canvasStylesView;
    }

    /**
     * Whether the package's own layout primitives appear in the palette.
     *
     * On by default so a new panel already has sections, text boxes and spacers.
     * Registering a block with the same type() replaces the shipped
     * one. Pass false to keep a palette of only the application's own types.
     */
    public function includeLayoutBlocks(bool $include = true): static
    {
        $this->includeLayoutBlocks = $include;

        return $this;
    }

    /**
     * Token sets the style inspector offers.
     *
     * Values are stored as names, not CSS, and emitted as `data-fpb-{token}` on the
     * block wrapper. An editor cannot produce a 13px lime heading because the knobs
     * are this list, not a colour picker.
     *
     * @param  array<string, array<int|string, string>>  $tokens
     */
    public function styleTokens(array $tokens): static
    {
        $this->styleTokens = $tokens;

        return $this;
    }

    /**
     * @return array<string, array<int|string, string>>
     */
    public function getStyleTokens(): array
    {
        return $this->styleTokens !== [] ? $this->styleTokens : static::defaultStyleTokens();
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function defaultStyleTokens(): array
    {
        return [
            'padding' => [
                'none' => 'None',
                'sm' => 'Small',
                'md' => 'Medium',
                'lg' => 'Large',
                'xl' => 'Extra large',
            ],
            'background' => [
                'none' => 'None',
                'surface' => 'Surface',
                'muted' => 'Muted',
                'contrast' => 'Contrast',
            ],
            'width' => [
                'narrow' => 'Narrow',
                'default' => 'Default',
                'wide' => 'Wide',
                'full' => 'Full',
            ],
            'align' => [
                'start' => 'Start',
                'center' => 'Center',
                'end' => 'End',
            ],
        ];
    }

    /**
     * Layout and content primitives the package ships.
     *
     * @return array<int, class-string<PageBlock>>
     */
    public static function layoutBlockClasses(): array
    {
        return [
            SectionBlock::class,
            TextBlock::class,
            ImageBlock::class,
            ButtonBlock::class,
            SpacerBlock::class,
            DividerBlock::class,
        ];
    }

    /**
     * The configured attribute, resolvable from outside a panel.
     *
     * Models carrying blocks are read on the public site too, where no panel is current
     * and `filament()` would throw. Falling back to the default keeps a page rendering
     * rather than failing on a lookup it only needed for a name.
     */
    public static function configuredBlocksAttribute(string $default = 'blocks'): string
    {
        try {
            return static::get()->getBlocksAttribute();
        } catch (Throwable) {
            return $default;
        }
    }

    public function getRecordModel(): ?string
    {
        return $this->recordModel;
    }

    public function getBlocksAttribute(): string
    {
        return $this->blocksAttribute;
    }

    /**
     * The registry for the panel this plugin instance was registered on.
     *
     * Before registration there is no panel to speak of, so it falls back to whichever
     * one is current — which is what a caller outside a panel lifecycle means anyway.
     */
    public function getRegistry(): BlockRegistry
    {
        $registries = app(BlockRegistries::class);

        return $this->panelId === null
            ? $registries->current()
            : $registries->for($this->panelId);
    }

    public function register(Panel $panel): void
    {
        $this->panelId = $panel->getId();

        $blocks = $this->includeLayoutBlocks
            ? [...static::layoutBlockClasses(), ...$this->blocks]
            : $this->blocks;

        $this->getRegistry()->register($blocks);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
