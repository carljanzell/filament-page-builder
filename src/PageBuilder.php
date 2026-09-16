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
     * Mark the start of a block being rendered for editing.
     */
    public static function editing(string $blockId, string $blockType): void
    {
        static::$blockId = $blockId;
        static::$blockType = $blockType;
    }

    public static function idle(): void
    {
        static::$blockId = null;
        static::$blockType = null;
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
