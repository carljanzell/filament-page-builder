<?php

namespace CarlJanzell\FilamentPageBuilder\Contracts;

/**
 * A content block type that the consuming application registers with the builder.
 *
 * The package owns the mechanism — the canvas, the registry, state handling — while
 * the application owns the block types themselves, because a block is free to query
 * application models and render application-branded markup.
 */
interface PageBlock
{
    /**
     * Stable machine name stored in the blocks JSON. Never change it once content exists.
     */
    public static function type(): string;

    /**
     * Human readable name shown in the builder and the palette.
     */
    public static function label(): string;

    /**
     * Heroicon name shown beside the label, if any.
     */
    public static function icon(): ?string;

    /**
     * Filament schema components for editing this block.
     *
     * @return array<int, mixed>
     */
    public static function schema(): array;

    /**
     * Blade component that renders this block, e.g. 'blocks.hero'.
     */
    public static function view(): string;

    /**
     * Field names within this block that hold file uploads.
     *
     * Uploads are held as an array in form state but stored as a plain path, so the
     * package needs to know which fields to reconcile when rendering a live preview.
     * It cannot infer this: a map of strings is indistinguishable from a repeater item.
     *
     * @return array<int, string>
     */
    public static function fileFields(): array;

    /**
     * Whether the current user may use this block.
     *
     * Authorisation is deliberately a callback rather than a role check, so the package
     * makes no assumption about how the consuming application does permissions.
     */
    public static function isVisible(): bool;
}
