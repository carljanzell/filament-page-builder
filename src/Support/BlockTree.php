<?php

namespace CarlJanzell\FilamentPageBuilder\Support;

use Illuminate\Support\Str;

/**
 * A flat list of blocks that can nest.
 *
 * Nested arrays make every move a path-splice and undo a deep diff. The page is stored
 * as one array; parent, slot and position say where each block sits. Existing pages
 * have none of those keys and become a list of roots, so opening one written before
 * nesting existed does not change what it looks like.
 */
class BlockTree
{
    /**
     * How deep a block may sit. Deep enough for section → column → section → column →
     * text, which is what a WordPress-style page actually uses, and shallow enough that
     * an editor cannot bury content six containers down by accident.
     */
    public const MAX_DEPTH = 5;

    /**
     * Give every block the keys the tree needs, without dropping anything else.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function hydrate(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $prepared = [];

        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }

            $prepared[] = [
                ...$block,
                'id' => $block['id'] ?? (string) Str::uuid(),
                'type' => $block['type'],
                'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
                'parent' => self::nullableString($block['parent'] ?? null),
                'slot' => self::nullableString($block['slot'] ?? null),
                'position' => is_numeric($block['position'] ?? null) ? (int) $block['position'] : $index,
                'settings' => is_array($block['settings'] ?? null) ? $block['settings'] : [],
            ];
        }

        return self::flatten(self::reindex($prepared));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function indexOf(array $blocks, string $id): ?int
    {
        foreach ($blocks as $index => $block) {
            if (($block['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>|null
     */
    public static function find(array $blocks, string $id): ?array
    {
        $index = self::indexOf($blocks, $id);

        return $index === null ? null : $blocks[$index];
    }

    /**
     * Children of a parent, optionally of one slot, in stored order.
     *
     * Passing no slot returns every child of that parent, so a walk of the page can
     * visit a section's columns without knowing their names.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function childrenOf(array $blocks, ?string $parent, ?string $slot = null): array
    {
        $children = array_values(array_filter(
            $blocks,
            function (array $block) use ($parent, $slot): bool {
                if (self::nullableString($block['parent'] ?? null) !== $parent) {
                    return false;
                }

                return $slot === null || self::nullableString($block['slot'] ?? null) === $slot;
            },
        ));

        usort($children, function (array $a, array $b) use ($slot): int {
            if ($slot === null) {
                $bySlot = (self::nullableString($a['slot'] ?? null) ?? '') <=> (self::nullableString($b['slot'] ?? null) ?? '');

                if ($bySlot !== 0) {
                    return $bySlot;
                }
            }

            return ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
        });

        return $children;
    }

    /**
     * Rewrite positions so each parent/slot group is a contiguous 0..n-1 sequence.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function reindex(array $blocks): array
    {
        $groups = [];

        foreach ($blocks as $index => $block) {
            $key = self::groupKey(
                self::nullableString($block['parent'] ?? null),
                self::nullableString($block['slot'] ?? null),
            );
            $groups[$key][] = $index;
        }

        foreach ($groups as $indices) {
            usort($indices, function (int $a, int $b) use ($blocks): int {
                return ($blocks[$a]['position'] ?? 0) <=> ($blocks[$b]['position'] ?? 0)
                    ?: $a <=> $b;
            });

            foreach ($indices as $order => $index) {
                $blocks[$index]['position'] = $order;
            }
        }

        return $blocks;
    }

    /**
     * Document order: each parent, then its children, depth first.
     *
     * The Livewire array stays in this order so a page that is still a flat list has
     * the same ids-in-array-order the existing tests (and a naive `@foreach`) expect.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $blocks): array
    {
        $out = [];
        $seen = [];

        $walk = function (?string $parent) use (&$walk, &$out, &$seen, $blocks): void {
            foreach (self::childrenOf($blocks, $parent) as $child) {
                $id = $child['id'];

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $out[] = $child;
                $walk($id);
            }
        };

        $walk(null);

        foreach ($blocks as $block) {
            $id = $block['id'] ?? null;

            if (! is_string($id) || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $out[] = $block;
        }

        return $out;
    }

    public static function groupKey(?string $parent, ?string $slot): string
    {
        return ($parent ?? '')."\0".($slot ?? '');
    }

    /**
     * Whether `$parent` is the block itself or one of its descendants.
     *
     * Dropping a section into a column it already owns would cycle the tree and the
     * canvas would never finish rendering it.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function isInvalidParent(array $blocks, string $id, ?string $parent): bool
    {
        if ($parent === null) {
            return false;
        }

        $cursor = $parent;
        $guard = 0;

        while ($cursor !== null && $guard++ < 50) {
            if ($cursor === $id) {
                return true;
            }

            $block = self::find($blocks, $cursor);
            $cursor = self::nullableString($block['parent'] ?? null);
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function depthOf(array $blocks, ?string $id): int
    {
        $depth = 0;
        $cursor = $id;

        while ($cursor !== null && $depth < 50) {
            $block = self::find($blocks, $cursor);

            if ($block === null) {
                break;
            }

            $cursor = self::nullableString($block['parent'] ?? null);
            $depth++;
        }

        return $depth;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function subtreeHeight(array $blocks, string $id): int
    {
        $height = 1;

        foreach (self::childrenOf($blocks, $id) as $child) {
            $height = max($height, 1 + self::subtreeHeight($blocks, $child['id']));
        }

        return $height;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function wouldExceedDepth(array $blocks, string $id, ?string $parent): bool
    {
        $parentDepth = $parent === null ? 0 : self::depthOf($blocks, $parent);

        return ($parentDepth + self::subtreeHeight($blocks, $id)) > self::MAX_DEPTH;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, string>
     */
    public static function descendantIds(array $blocks, string $id): array
    {
        $ids = [];

        foreach (self::childrenOf($blocks, $id) as $child) {
            $ids[] = $child['id'];
            array_push($ids, ...self::descendantIds($blocks, $child['id']));
        }

        return $ids;
    }

    /**
     * Move a block to a sibling index in a parent/slot.
     *
     * `$to` is the index among current siblings *before* the block is lifted out,
     * matching the existing canvas contract: moving the first root to 2 in
     * [a, b, c] lands it between b and c.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function move(array $blocks, string $id, int $to, ?string $parent = null, ?string $slot = null): array
    {
        $index = self::indexOf($blocks, $id);

        if ($index === null || self::isInvalidParent($blocks, $id, $parent) || self::wouldExceedDepth($blocks, $id, $parent)) {
            return $blocks;
        }

        $block = $blocks[$index];
        $fromParent = self::nullableString($block['parent'] ?? null);
        $fromSlot = self::nullableString($block['slot'] ?? null);
        $sameGroup = $fromParent === $parent && $fromSlot === $slot;
        $fromSiblings = self::childrenOf($blocks, $fromParent, $fromSlot);
        $from = 0;

        foreach ($fromSiblings as $i => $sibling) {
            if ($sibling['id'] === $id) {
                $from = $i;

                break;
            }
        }

        if ($sameGroup) {
            if ($fromSiblings === []) {
                return $blocks;
            }

            $to = max(0, min($to > $from ? $to - 1 : $to, count($fromSiblings) - 1));

            if ($to === $from) {
                return $blocks;
            }
        }

        array_splice($blocks, $index, 1);

        $destSiblings = self::childrenOf($blocks, $parent, $slot);

        if (! $sameGroup) {
            $to = max(0, min($to, count($destSiblings)));
        }

        $block['parent'] = $parent;
        $block['slot'] = $slot;

        array_splice($destSiblings, $to, 0, [$block]);

        foreach ($destSiblings as $order => $sibling) {
            if ($sibling['id'] === $id) {
                $block['position'] = $order;

                continue;
            }

            $siblingIndex = self::indexOf($blocks, $sibling['id']);

            if ($siblingIndex !== null) {
                $blocks[$siblingIndex]['position'] = $order;
            }
        }

        $blocks[] = $block;

        return self::flatten(self::reindex($blocks));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $block
     * @return array<int, array<string, mixed>>
     */
    public static function insert(array $blocks, array $block, ?int $at = null, ?string $parent = null, ?string $slot = null): array
    {
        if ($parent !== null && self::find($blocks, $parent) === null) {
            return $blocks;
        }

        if ($parent !== null && self::depthOf($blocks, $parent) >= self::MAX_DEPTH) {
            return $blocks;
        }

        $siblings = self::childrenOf($blocks, $parent, $slot);
        $at = $at === null ? count($siblings) : max(0, min($at, count($siblings)));

        $block['parent'] = $parent;
        $block['slot'] = $slot;
        $block['settings'] = is_array($block['settings'] ?? null) ? $block['settings'] : [];

        array_splice($siblings, $at, 0, [$block]);

        foreach ($siblings as $order => $sibling) {
            if (($sibling['id'] ?? null) === ($block['id'] ?? null)) {
                $block['position'] = $order;

                continue;
            }

            $siblingIndex = self::indexOf($blocks, $sibling['id']);

            if ($siblingIndex !== null) {
                $blocks[$siblingIndex]['position'] = $order;
            }
        }

        $blocks[] = $block;

        return self::flatten(self::reindex($blocks));
    }

    /**
     * Remove a block and every descendant. An emptied column stays; it is a slot, not a block.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function remove(array $blocks, string $id): array
    {
        $ids = [$id, ...self::descendantIds($blocks, $id)];

        $blocks = array_values(array_filter(
            $blocks,
            fn (array $block): bool => ! in_array($block['id'] ?? null, $ids, true),
        ));

        return self::flatten(self::reindex($blocks));
    }

    /**
     * Copy a subtree, placed as the next sibling of the original.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function duplicate(array $blocks, string $id): array
    {
        $source = self::find($blocks, $id);

        if ($source === null) {
            return $blocks;
        }

        $map = [];
        $copies = [];
        $ids = [$id, ...self::descendantIds($blocks, $id)];

        foreach ($ids as $old) {
            $map[$old] = (string) Str::uuid();
        }

        foreach ($blocks as $block) {
            if (! in_array($block['id'] ?? null, $ids, true)) {
                continue;
            }

            $copy = $block;
            $copy['id'] = $map[$block['id']];

            $parent = self::nullableString($block['parent'] ?? null);
            $copy['parent'] = $parent === null ? null : ($map[$parent] ?? $parent);

            if ($block['id'] === $id) {
                $copy['position'] = ($block['position'] ?? 0) + 1;
            }

            $copies[] = $copy;
        }

        $parent = self::nullableString($source['parent'] ?? null);
        $slot = self::nullableString($source['slot'] ?? null);
        $after = ($source['position'] ?? 0) + 1;

        foreach ($blocks as $i => $other) {
            if (self::nullableString($other['parent'] ?? null) !== $parent
                || self::nullableString($other['slot'] ?? null) !== $slot) {
                continue;
            }

            if (($other['position'] ?? 0) >= $after) {
                $blocks[$i]['position'] = ($other['position'] ?? 0) + 1;
            }
        }

        array_push($blocks, ...$copies);

        return self::flatten(self::reindex($blocks));
    }

    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
