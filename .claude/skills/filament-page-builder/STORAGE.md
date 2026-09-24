# Storage — the flat block tree

All page content lives in **one JSON column** that holds a **flat list**. Nesting is expressed with `parent` / `slot` / `position` on that same list, so a move changes three keys instead of splicing nested arrays, and an undo snapshot is just one array. `src/Support/BlockTree.php` holds every tree operation. It is pure, static, and never touches Livewire or the database.

Related: [CANVAS.md](CANVAS.md) (who calls these operations and how they're gated), [RENDERING.md](RENDERING.md) (how the tree is walked for output).

---

## Keys on a block

| Key | Written by | Meaning / invariant |
|---|---|---|
| `id` | `hydrate()` if missing, then persisted by the canvas's `save()` | Stable identity. It survives reorders so that drag, undo and Livewire's `wire:key` diffing don't fall back to array position. |
| `type` | whoever created the block | The `PageBlock::type()` machine name. **Never rename it once content exists**: a renamed type turns into a "retired" block. |
| `data` | inspector commit / `setBlockField()` | The block's schema values in their **stored** shape: rich text as an HTML string, an upload as a path string. |
| `parent` | tree ops | The container's `id`, or `null` for a root. |
| `slot` | tree ops | Which well of the parent (`col-0`, `col-1`, …). Slots are **derived from the parent's `data`** (`Container::slots($data)`) and are never stored as entities. |
| `position` | `reindex()` | A contiguous `0..n-1` within each `(parent, slot)` group. |
| `settings` | style inspector | Token *names* (`['padding' => 'lg']`), never CSS. |
| anything else | the application | Carried through load and save untouched (`anchor`, `created_by`, …). This is covered by the test `carries application keys on a block through a save`. |

**There is no `{"version": 2, "blocks": [...]}` wrapper and no `pages:upgrade-blocks` command.** ROADMAP §4.1 sketches both, but neither shipped. The column is still a bare list, and the "v1 → tree upgrade" happens implicitly on every read inside `hydrate()`: a missing `parent` makes a block a root, and a missing `position` falls back to the array index. A v1 page only gains the tree keys when the canvas saves it. Don't write a migration or command for this, because none is needed.

---

## `hydrate()`: the only normalisation

Every reader goes through it: `DesignPage::prepareBlocks()`, `HasBlocks::getBlocks()`, and `<x-page-builder::blocks>`.

| Input | Result |
|---|---|
| An entry that is not an array, or whose `type` is not a string | **Dropped.** This is the only thing `hydrate()` removes. |
| `id` missing or `null` | A fresh UUID, **minted anew on every call**. It stays unstable across requests until a canvas save persists it, so never key anything external (anchors, caches) on an id you haven't seen stored. |
| `data` / `settings` not an array | `[]` |
| `parent` / `slot` not a string, or `''` | `null` |
| `position` not numeric | The entry's array index |
| then | `reindex()` → `flatten()` |

`flatten()` returns **document order**: depth-first, parent before children. Livewire's `$blocks` is kept in this order, so a page that is still a flat list keeps its ids in array order. The tests and any naive `@foreach` rely on that.

---

## Traps in the model

### `position` beats array order *(verified)*
After the first canvas save, every block carries a numeric `position`. From then on, **reordering the raw array changes nothing**: `reindex()` sorts by `position` and uses array index only to break ties. This affects the Filament `Builder` form editor, seeders, and hand-edited JSON. To reorder programmatically, go through `BlockTree::move()` or rewrite the positions.

### Ghosts: blocks that are stored but never shown
Two situations leave a block in the array that **no surface ever renders**. The canvas and the public renderer both walk from the roots through each container's *current* slots, and the outline does the same.

| Cause | Consequence |
|---|---|
| **Orphan**: `parent` names an id that isn't in the list (for example, a section item deleted in the form editor) | `flatten()` appends it at the end of the array. It survives every save, but it can't be selected or deleted from the canvas. |
| **Hidden slot**: a section drops from 3 columns to 2, leaving children in `col-2` | Invisible on the canvas, in the outline and on the public page. They **reappear** if the column count goes back up. |

Hidden-slot children still count in `DesignPage::blockHasContent()` because `childrenOf()` without a slot returns every child. That is why deleting an empty-looking section can still ask for confirmation. `remove()` and `duplicate()` take them along too, since `descendantIds()` ignores slots.

### `insert` and `move` don't check that the parent can hold children
Both check only that the parent exists, that no cycle forms, and that the depth cap holds. They do **not** check that the parent is a `Container` or that `$slot` is one it exposes. A crafted `insertBlock('text', 0, $leafId, 'whatever')` over the wire creates a hidden child. If you add that validation, put it in `DesignPage` and use `registry()->slots($type, $data)`.

---

## Operations

| Method | Contract |
|---|---|
| `move($blocks, $id, $to, $parent, $slot)` | `$to` is **the index among destination siblings before the block is lifted out**. Within the same group, moving the first of `[a,b,c]` to `2` lands it between b and c. That is why the JS sends `to + 1` when moving down. Across groups, it's the index into the destination list as it stands. |
| `insert($blocks, $block, $at, $parent, $slot)` | `$at = null` appends. It refuses an unknown parent, or a parent already at `MAX_DEPTH`. |
| `remove($blocks, $id)` | Removes the block and all its descendants. The emptied column stays, because it's a slot and not a block. |
| `duplicate($blocks, $id)` | Fresh UUIDs for the whole subtree, parents remapped, the copy placed at `position + 1`, later siblings shifted. It copies descendants **wholesale**. See CANVAS.md for the authorisation gap this opens. |
| `childrenOf($blocks, $parent, $slot = null)` | With no slot, it returns every child sorted by slot name and then position. That's how walkers visit columns without knowing their names. |

**Refusal is signalled by returning the input unchanged.** `DesignPage` detects a no-op with `$next === $this->blocks` and skips history. A new operation must keep that contract, or the first undo will appear to do nothing.

**Depth counts blocks, not columns.** `depthOf()` includes the node itself (a root is 1), and `MAX_DEPTH = 5` allows five nested blocks, for example four sections and a leaf. The class docblock's "section → column → section → column → text" undercounts, because columns are slots and not blocks. `move()` uses `wouldExceedDepth()` = parent depth + **height of the moved subtree**, so dragging a deep section can be refused even into a shallow column. `isInvalidParent()` walks ancestors with a 50-hop guard against corrupt cycles.

---

## The form editor is a flat view of a tree *(verified against `vendor/filament/forms` Builder)*

The documented bridge (`Builder::make('blocks')->blocks(PageBlocks::all())`, see `docs/index.html` → "The form editor") writes the **same column**. Filament's `Builder::hydrateItems()` keeps each item array whole, extra keys included, and dehydrates with `array_values()`. Once a page is nested, the two surfaces stop being symmetric:

| Form-editor action | What happens to the tree |
|---|---|
| Open the form | Every nested child shows up as a **top-level item**, and the section item has no children UI |
| Reorder items | **Ignored**, because `position` wins |
| Delete a section item | Its children become **orphans** |
| Add an item | It has no `id`/`parent`/`position`, so it becomes a root at its array-index position (it may tie with an existing position, and array order breaks the tie) |
| **Clone an item** | The Builder copies the item *including its `id`*. `flatten()` skips the second occurrence of an id, so **the clone never renders anywhere and the next canvas save deletes it** |

Both surfaces also write the whole column with **no concurrency check**. Saving the form after the canvas, or the other way round, silently overwrites the other's changes.

---

## `HasBlocks` (optional model trait)

| Member | Note |
|---|---|
| `getBlocks()` | `BlockTree::hydrate($this->{attr})`, defensive against a non-array value |
| `blocksAttribute()` | A model property `protected string $blocksAttribute` wins (`LayoutPage` fixture → `'layout'`). Otherwise it uses `FilamentPageBuilderPlugin::configuredBlocksAttribute()`, which catches `Throwable` and falls back to `'blocks'` because `filament()` throws on the public site, where no panel is current. |
| `ensureBlockIds()` | **Bug:** `['id' => $block['id'] ?? uuid, ...$block]` puts the spread *after* the id, so an explicit `'id' => null` overwrites the fresh UUID with `null`. `hydrate()` has the right order (spread first). Nothing in the package calls `ensureBlockIds()`. |

The canvas does **not** require the trait: it uses `method_exists($record, 'blocksAttribute')`. A model without it falls back to `FilamentPageBuilderPlugin::get()`, which needs a current panel. Either way, **the column must be cast to `array`**, because `save()` assigns a PHP array and calls `$record->save()` directly.
