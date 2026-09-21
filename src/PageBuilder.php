<?php

namespace CarlJanzell\FilamentPageBuilder;

use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use Illuminate\Support\Js;

/**
 * The rendering context a block's Blade component can ask about.
 *
 * `@editable` has to emit editing attributes on the canvas and nothing at all on the
 * public site, from the same component. That means something outside the component has
 * to say which of the two is happening; this is it.
 */
class PageBuilder
{
    protected static ?string $blockId = null;

    protected static ?string $blockType = null;

    /**
     * @var array<string, mixed>
     */
    protected static array $blockData = [];

    /**
     * @var array<int, string>|null
     */
    protected static ?array $slotNames = null;

    /**
     * @var (callable(string): string)|null
     */
    protected static $slotRenderer = null;

    /**
     * Nested containers render children, which must not wipe the parent context.
     *
     * @var array<int, array<string, mixed>>
     */
    protected static array $stack = [];

    /**
     * Mark the start of a block being rendered for editing.
     *
     * @param  array<string, mixed>  $data
     */
    public static function editing(string $blockId, string $blockType, array $data = []): void
    {
        static::push();
        static::$blockId = $blockId;
        static::$blockType = $blockType;
        static::$blockData = $data;
        static::$slotNames = null;
        static::$slotRenderer = null;
    }

    /**
     * Render a container on the public page without turning `@editable` on.
     *
     * @param  array<string, mixed>  $data
     */
    public static function rendering(string $blockType, array $data = []): void
    {
        static::push();
        static::$blockId = null;
        static::$blockType = $blockType;
        static::$blockData = $data;
        static::$slotNames = null;
        static::$slotRenderer = null;
    }

    /**
     * How a container should fill its slots for this render.
     *
     * The block's own Blade component calls `slot()`; the canvas and the public
     * renderer each supply a different inner HTML — drop zones on the canvas,
     * children only on the public page. One section view, two contexts.
     *
     * @param  array<int, string>  $names
     * @param  callable(string): string  $renderer
     */
    public static function provideSlots(array $names, callable $renderer): void
    {
        static::$slotNames = $names;
        static::$slotRenderer = $renderer;
    }

    public static function idle(): void
    {
        $previous = array_pop(static::$stack);

        if ($previous === null) {
            static::$blockId = null;
            static::$blockType = null;
            static::$blockData = [];
            static::$slotNames = null;
            static::$slotRenderer = null;

            return;
        }

        static::$blockId = $previous['blockId'];
        static::$blockType = $previous['blockType'];
        static::$blockData = $previous['blockData'];
        static::$slotNames = $previous['slotNames'];
        static::$slotRenderer = $previous['slotRenderer'];
    }

    /**
     * @return array{blockId: ?string, blockType: ?string, blockData: array<string, mixed>, slotNames: ?array<int, string>, slotRenderer: (callable(string): string)|null}
     */
    protected static function snapshot(): array
    {
        return [
            'blockId' => static::$blockId,
            'blockType' => static::$blockType,
            'blockData' => static::$blockData,
            'slotNames' => static::$slotNames,
            'slotRenderer' => static::$slotRenderer,
        ];
    }

    protected static function push(): void
    {
        static::$stack[] = static::snapshot();
    }

    /**
     * @return array<int, string>
     */
    public static function slotNames(): array
    {
        if (static::$slotNames !== null) {
            return static::$slotNames;
        }

        return app(BlockRegistry::class)->slots(static::$blockType, static::$blockData);
    }

    /**
     * The current contents of a named slot, wrapped so the slot is one element.
     *
     * The wrapper is not decoration. A section is a grid, and without it each child
     * block becomes its own grid item, so a column holding two blocks spills into the
     * next one. Off the canvas the wrapper carries no editing attributes and the
     * stylesheet strips it back to a bare grid cell.
     */
    public static function slot(string $name): string
    {
        $inner = static::$slotRenderer !== null ? (string) (static::$slotRenderer)($name) : '';

        if (! static::isEditing()) {
            return '<div class="fpb-slot">'.$inner.'</div>';
        }

        // An empty `@foreach` still emits Blade/Livewire comment markers, which
        // are not content — the slot should still show the drop hint.
        $blank = trim(preg_replace('/<!--.*?-->/s', '', $inner) ?? '') === '';

        $empty = $blank
            ? '<p class="fpb-slot-empty">Drop a block here</p>'
            : '';

        return '<div class="fpb-slot" data-fpb-parent="'.e((string) static::$blockId).'" data-fpb-slot="'.e($name).'">'.$inner.$empty.'</div>';
    }

    public static function isEditing(): bool
    {
        return static::$blockId !== null;
    }

    public static function currentBlockId(): ?string
    {
        return static::$blockId;
    }

    /**
     * Attributes that turn an element into an inline editor, or nothing.
     *
     * Emits nothing off the canvas, and nothing for a field the block has not declared
     * editable — the declaration is the authority, so marking up an element the block
     * never listed cannot smuggle a writable field onto the page.
     */
    public static function editableAttributes(string $field): string
    {
        $editable = static::editableFor($field);

        if ($editable === null) {
            return '';
        }

        $attributes = [
            'data-fpb-block="'.e(static::$blockId).'"',
            'data-fpb-field="'.e($field).'"',
            'data-fpb-kind="'.e($editable->kind).'"',
        ];

        if ($editable->kind === Editable::TEXT) {
            $attributes[] = 'contenteditable="plaintext-only"';
            $attributes[] = 'data-fpb-multiline="'.($editable->isMultiline() ? 'true' : 'false').'"';
        }

        if (filled($placeholder = $editable->getPlaceholder())) {
            $attributes[] = 'data-fpb-placeholder="'.e($placeholder).'"';
        }

        return implode(' ', $attributes);
    }

    /**
     * Whether a block should render an element for a field that may be empty.
     *
     * Most block markup hides an empty field, which on the canvas leaves nothing to click
     * into and no way to fill it in. An editable field renders anyway while editing, so
     * its placeholder gives the editor a target.
     *
     * Wrap the element in `PageBuilder::shows('heading', $heading)` rather than a bare
     * `filled()` check and an unfilled block stays fillable.
     */
    public static function shows(string $field, mixed $value): bool
    {
        return filled($value) || static::editableFor($field) !== null;
    }

    public static function editableFor(string $field): ?Editable
    {
        if (! static::isEditing()) {
            return null;
        }

        return static::editablesFor(static::$blockType)[$field] ?? null;
    }

    /**
     * @return array<string, Editable>
     */
    public static function editablesFor(?string $type): array
    {
        $block = app(BlockRegistry::class)->find($type);

        if ($block === null || ! is_subclass_of($block, InlineEditable::class)) {
            return [];
        }

        return array_filter($block::editables(), fn (mixed $editable): bool => $editable instanceof Editable);
    }

    /**
     * The editable field map for a block type, as JSON for the canvas script.
     */
    public static function editablesJson(?string $type): string
    {
        return (string) Js::from(array_map(
            fn (Editable $editable): array => [
                'kind' => $editable->kind,
                'multiline' => $editable->isMultiline(),
            ],
            static::editablesFor($type),
        ));
    }
}
