<?php

namespace CarlJanzell\FilamentPageBuilder\Contracts;

use CarlJanzell\FilamentPageBuilder\Editable;

/**
 * A block whose fields can be edited directly on the canvas as well as in the inspector.
 *
 * Kept separate from PageBlock so that opting in is explicit and existing blocks keep
 * working untouched: a block that does not implement this is simply edited in the
 * inspector, as before.
 */
interface InlineEditable
{
    /**
     * Fields that may be edited in place, keyed by field name.
     *
     * Only the fields named here can be written from the canvas. A field the markup
     * marks with `@editable` but that is missing from this list is not editable — the
     * list is the authority, not the markup.
     *
     * @return array<string, Editable>
     */
    public static function editables(): array;
}
