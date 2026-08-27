<?php

namespace CarlJanzell\FilamentPageBuilder;

use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use InvalidArgumentException;

/**
 * The single source of truth for which block types exist.
 *
 * Both editing surfaces and the public renderer resolve through this, so a block can
 * never mean one thing in the form and another on the canvas.
 */
class BlockRegistry
{
    /**
     * @var array<string, class-string<PageBlock>>
     */
    protected array $blocks = [];

    /**
     * @param  array<int, class-string<PageBlock>>  $blocks
     */
    public function register(array $blocks): static
    {
        foreach ($blocks as $block) {
            if (! is_subclass_of($block, PageBlock::class)) {
                throw new InvalidArgumentException("[{$block}] must implement ".PageBlock::class.'.');
            }

            $this->blocks[$block::type()] = $block;
        }

        return $this;
    }

    /**
     * @return array<string, class-string<PageBlock>>
     */
    public function all(): array
    {
        return $this->blocks;
    }

    /**
     * Only the blocks the current user may use.
     *
     * @return array<string, class-string<PageBlock>>
     */
    public function visible(): array
    {
        return array_filter($this->blocks, fn (string $block): bool => $block::isVisible());
    }

    /**
     * @return class-string<PageBlock>|null
     */
    public function find(?string $type): ?string
    {
        return $this->blocks[$type] ?? null;
    }

    public function has(?string $type): bool
    {
        return $this->find($type) !== null;
    }

    /**
     * Blade component for a type, or null when the type is unknown.
     *
     * Unknown types are expected rather than exceptional: content outlives schema changes,
     * so the renderer skips what it does not recognise instead of failing the page.
     */
    public function view(?string $type): ?string
    {
        return $this->find($type)?->view();
    }

    /**
     * @return array<int, string>
     */
    public function fileFields(?string $type): array
    {
        return $this->find($type)?->fileFields() ?? [];
    }
}
