<?php

namespace CarlJanzell\FilamentPageBuilder;

/**
 * A declaration that one of a block's fields may be edited directly on the page.
 *
 * The block says which field, of what kind; its Blade component says which element in
 * its own markup that field is. Neither the canvas nor the package has to understand the
 * markup, which is what keeps one component serving both the public site and the editor.
 */
class Editable
{
    public const TEXT = 'text';

    public const RICH_TEXT = 'richText';

    protected bool $isMultiline = false;

    protected ?string $placeholder = null;

    final public function __construct(public readonly string $kind) {}

    public static function text(): static
    {
        return new static(static::TEXT);
    }

    public static function richText(): static
    {
        return new static(static::RICH_TEXT);
    }

    /**
     * Whether Enter inserts a line break rather than committing the edit.
     */
    public function multiline(bool $condition = true): static
    {
        $this->isMultiline = $condition;

        return $this;
    }

    /**
     * Shown in place of an empty field, so an unfilled block is still clickable.
     */
    public function placeholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function isMultiline(): bool
    {
        return $this->isMultiline;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    /**
     * Whether a value arriving from the browser is acceptable for this kind of field.
     *
     * The canvas is a public Livewire surface, so what it writes into block state is
     * checked here rather than trusted.
     */
    public function accepts(mixed $value): bool
    {
        return match ($this->kind) {
            static::TEXT, static::RICH_TEXT => is_string($value),
            default => false,
        };
    }
}
