# Canvas: `DesignPage` and undo history

`src/Filament/Pages/DesignPage.php` is an **abstract** Filament resource page. A consumer subclasses it, sets only `protected static string $resource`, and registers it as `'design' => DesignPage::route('/{record}/design')`. It is the only server-side write path for the canvas. Everything stays in memory in Livewire until an explicit `save()`, because a database write per drag would put a round trip in the middle of the gesture.

Related: [STORAGE.md](STORAGE.md) (the tree ops it calls), [FRONTEND.md](FRONTEND.md) (the JS that calls it), [BLOCKS.md](BLOCKS.md) (`isVisible()` and editables).

---

## State

| Property | Holds | Why it matters |
|---|---|---|
| `$blocks` | The whole flat tree in document order | Round-trips to the browser **on every request**. This is why history is *not* kept here. |
| `$selectedId` | The selected block's id | Read by the JS (`$wire.selectedId`) for keyboard shortcuts. |
| `$blockData` | Inspector form state for **the selected block only** | The form's `statePath`. Its shape differs from the stored data (TipTap doc, upload arrays). |
| `$blockSettings` | Style-tab state for the selected block | Bound with `wire:model.live="blockSettings.{token}"`. |
| `$isDirty` | Unsaved changes flag | Read by the JS `beforeunload` / `livewire:navigate` guards. |

Computed properties (`getXProperty`): `rootBlocks` (the decorated tree, adding `view`, `label`, `isKnown`, `hasContent`, `isContainer`, `slotNames`, `children[slot]`), `structure` (the outline, built from `rootBlocks` via `slotNames`, so ghosts are excluded), `palette` (`registry()->visible()`), `paletteGroups`, and `canUndo` / `canRedo` (session reads). `renderableBlocks` (flat, decorated) is documented public API, but **nothing in the package reads it**.

---

## Mutations

| Method | Authorisation gate | Notes |
|---|---|---|
| `moveBlock($id, $to, $parent, $slot)` | **none, deliberately** | An editor may reorder or remove a block they can't author, and retired types can be moved. `BlockTree::move` refuses cycles and depth itself. |
| `insertBlock($type, $at, $parent, $slot)` | `registry()->isVisible($type)` | A palette *click* with a selection inserts at the end of a container's first slot, or as the next sibling of a leaf. An explicit drop passes `$at`/`$parent`/`$slot` and wins. It seeds `data` from `defaults()` and selects the new block. |
| `duplicateBlock($id)` | `isVisible($source type)` | Refuses retired types. **Gap:** only the *root's* type is checked, while `BlockTree::duplicate` copies every descendant. Duplicating a section can clone a child the user may not author, such as a custom-HTML block. |
| `removeBlock($id)` | **none, deliberately** | Removes the subtree. It deselects if the selection was inside it, and it has no server-side confirmation (the JS confirms). |
| `setBlockField($id, $field, $value)` | `isVisible` + declared in `editables()` + `Editable::accepts($value)` | The inline-edit path. The markup's `@editable` is **not** trusted: the block's declaration is the authority. |
| `commitSelectedBlock()` | skips `!isVisible` types | Otherwise the inspector renders no fields and the empty state **wipes the content**. |
| `commitSelectedSettings()` | token allow-list | See "Style settings" below. |

**The shape every mutation follows:** compute `$next` → `return` if `$next === $this->blocks` → `remember()` → assign → `isDirty = true`. Record history **only once you know something will change**. A step recorded for a no-op makes the next undo appear to do nothing (test: `does not record a commit that changes nothing`).

**Two authorisation layers:** `Resource::canEdit($record)` (a 403 from `authorizeAccess()` at mount) decides who may open the canvas at all, and `PageBlock::isVisible()` decides who may *author* a type. Hiding a block from the palette is presentation only, since every mutation is reachable over the wire. That's why each authoring method re-checks `isVisible` rather than `has()`.

---

## The inspector: commit and sync rules

The form is `->components(fn () => $this->selectedBlockSchema())->statePath('blockData')`, a closure over the current selection.

- **Commit through `getState()`, never raw state.** `getState()` turns a TipTap document into HTML and an upload array into a path, and runs the dehydration hook that **moves an upload out of temporary storage**. Committing raw state writes a document array into the database and breaks every renderer.
- **`getState()` validates.** A half-filled block throws `ValidationException`. The fallback commits `normaliseData($this->blockData)` but **restores the stored value for each declared top-level file field**, because an in-progress upload has only a temporary URL, and storing it leaves a dead link. The fallback passes no `fileFields`, so a nested (repeater) upload in an invalid block commits its raw upload state.
- **`syncInspector()` never commits.** Undo replaces `$blocks` while the inspector still holds the state that was just undone, so committing would write it straight back (test: `undoes a content edit without the inspector writing it back`).
- **Drop the cached schema before filling it:** `$this->cacheSchema('form', null)` and then `form->fill()`. The schema is cached per request and built from `$selectedId`. Changing the selection mid-request leaves it pointing at the previous block (or at none), the inspector hydrates empty, and the next commit wipes the block. `setBlockField()` does the same when it edits the selected block.
- **Filament fields are deferred by default.** Text typed into the inspector but not yet sent rides along with the *next* request (select, undo, save). `updatedBlockData()` commits it **before** that action runs, so an undo immediately after typing undoes the typing.
- **`selectBlock()` commits data and then settings for the outgoing block** before it switches.

---

## Style settings

`commitSelectedSettings()` rebuilds `settings` from scratch. For each token in `styleTokens()`, it keeps the submitted value only if it's a string that appears in that token's allowed values. A crafted `'13px lime'` is dropped, and choosing `Default` (`''`) removes the key.

- Allowed values come from `tokenValues()`: the values of a **list** (`['sm', 'md']`) or the **keys** of a map (`['sm' => 'Small']`). A map with **integer keys that isn't a list** (`[1 => 'One', 2 => 'Two']`) can never validate. The `<select>` submits the label, and the strict `in_array` check against int keys fails. Use string keys.
- `styleTokens()` **replaces** the defaults (`padding` / `background` / `width` / `align`) rather than merging with them. The package CSS styles only the default names, so custom tokens need CSS in the app. See [RENDERING.md](RENDERING.md) for which token names ever reach the HTML.

---

## Undo history (`src/Support/BlockHistory.php`)

It lives in the **session** under `filament-page-builder.history`, not in a Livewire property. Thirty snapshots of a block array that runs to tens of kilobytes would otherwise put megabytes on the wire for each request.

| Fact | Consequence |
|---|---|
| `LIMIT = 30` (ROADMAP says ~50, but the code is the authority) | The 31st-oldest step is gone |
| One stack, tagged `owner` = the Livewire component id | `mount()` calls `clear()`. Opening the canvas in a second tab wipes the first tab's history, and the first tab's next push wipes the second's |
| A new push empties `future` | The usual redo-branch abandonment |
| `travel()` always sets `isDirty = true` | Nothing tracks the saved state, so undoing back to it still reads "Unsaved changes" |
| The session holds 30 × the page size | A **cookie** session driver will overflow browser cookie limits. The canvas needs a server-side driver (file, database, redis) |

---

## `save()`

It commits the inspector (data, then settings), then runs `$record->{blocksAttribute()} = $this->blocks; $record->save();` and sends a success notification.

- It writes `$blocks` **as-is**: retired types, ghosts, application keys and all the tree keys. **Never prune at persist time.** Commit `83310f3` fixed a canvas that filtered unknown types on load and destroyed them on save. Skip at *render* time only.
- It bypasses the resource's form lifecycle: no validation and no `mutateFormDataBeforeSave`. Model observers and events **do** fire.
- It has no optimistic lock, so the last writer wins against the form editor ([STORAGE.md](STORAGE.md)).

---

## The full-screen shell

| Override | Why |
|---|---|
| `getHeading()` → `''`, `getBreadcrumbs()` → `[]` | The editor's own chrome owns the top. The browser tab still uses `getTitle()` → `Design: {record}`. |
| `getMaxContentWidth()` → `Width::Screen` | |
| `getExtraBodyAttributes()` adds `fpb-edit-mode` | The CSS hook that hides Filament's sidebar, topbar and header. See [FRONTEND.md](FRONTEND.md). |
| `getPageClasses()` → `['fpb-editor-page']`, **never `fpb-page`** | `fpb-page` marks the *public* wrapper, and rules hung off it strip a slot back to a bare grid cell. Putting it on the editor erased the drop wells (incident `dc08dbc`). |
| `exitUrl()` | The resource's `index` page if it has one, otherwise the edit page. |
| `formEditorUrl()` | `null` when the resource has no `edit` page, because the canvas may be the only editing surface (fixture `LayoutPageResource`). Never link to the edit page unconditionally. |

`styleTokens()`, `canvasStylesView()` and the `blocksAttribute()` fallback all call `FilamentPageBuilderPlugin::get()` → `filament('page-builder')`. **The design page must live on a panel that registered the plugin**, or those calls throw.

---

## Naming traps

- **`prepareBlocks()`, not `hydrateBlocks()`.** Livewire treats `hydrate{Property}` / `dehydrate{Property}` / `updating{Property}` / `updated{Property}` as lifecycle hooks for **any** public property: `$blocks`, `$selectedId`, `$blockData`, `$blockSettings`, `$isDirty`. A helper named `hydrateBlocks` gets called on every request. `updatedBlockData()` and `updatedBlockSettings()` are the only deliberate hooks.
- Every public method is callable from the browser. Anything that shouldn't be callable (like `remember()`, `syncInspector()` or `travel()`) must stay `protected`.
