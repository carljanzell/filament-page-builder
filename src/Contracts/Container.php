<?php

namespace CarlJanzell\FilamentPageBuilder\Contracts;

/**
 * A block that can hold other blocks.
 *
 * Slots are named drop targets — the columns of a section, the single well of a group.
 * The canvas and the public renderer both ask the block which slots it currently
 * exposes, so changing the column count on a section is a data change, not a new type.
 */
interface Container
{
    /**
     * Slot identifiers this instance currently exposes, given its stored data.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function slots(array $data): array;

    /**
     * Data a freshly dropped instance starts with, so a section is already a row of
     * columns rather than an empty shell the editor has to configure first.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array;
}
