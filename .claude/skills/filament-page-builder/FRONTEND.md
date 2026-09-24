# Frontend: `page-builder.js`, `page-builder.css`, assets

The canvas UI is one hand-written Alpine component (`resources/js/page-builder.js`), one stylesheet (`resources/css/page-builder.css`) and a vendored Anime.js (`resources/js/vendor/anime.min.js`, **v3.2.2**, so it uses the v3 `anime({targets, …})` API and not the v4 `animate()` one). **There is no build step and there must never be one.** The package exists for a production host with no Node. Nothing here is covered by tests (see [TESTING.md](TESTING.md)), so read the contracts below before renaming anything.

Related: [CANVAS.md](CANVAS.md) (the Livewire methods called), [RENDERING.md](RENDERING.md) (the markup the JS reads).

---

## Assets

Registered in `PageBuilderServiceProvider::registerAssets()` under the package key `carljanzell/filament-page-builder`: Js `page-builder-anime`, Js `page-builder`, Css `page-builder`. `AssetRegistrationTest` pins that each registered path exists, because a missing vendored file would fail silently in consumers.

| Fact | Consequence |
|---|---|
| Loaded **panel-wide** (no `loadedOnRequest()`) | JS and CSS appear on every page of every panel with the plugin, which is why all CSS is scoped under `.fpb*` / `body.fpb-edit-mode`. |
| The Alpine component is registered in an `alpine:init` listener | That event fires **once per full page load**. Panel-wide loading ensures the registration exists before a `wire:navigate` lands on the Design page. Switching to `loadedOnRequest()` would need the registration to handle Alpine already running. |
| Consumers serve copies published by `php artisan filament:assets` → `public/{js,css}/carljanzell/filament-page-builder/…` (under `config('filament.assets_path')` if set) | **Every JS/CSS change needs consumers to re-publish.** `filament:upgrade` runs the assets command; `filament:optimize` does **not**. docs/index.html claims optimize is enough, and it's wrong. |
| The cache-bust `?v=` is the package's installed version | On a `dev-main` pin the string never changes, so browsers can keep an old `page-builder.js` after an update. Hard-reload, or tag releases. |

---

## Alpine component `pageBuilderCanvas` (on `.fpb`)

**Look elements up from `$root`, never `$el`.** The methods run from `x-on` expressions all over the tree, where `$el` is the element carrying the listener. `canvas()` resolving `.fpb-canvas` against `.fpb-canvas` returned `null`, and **every drag silently did nothing** (incident `2382338`).

**Clean up in `destroy()`** (Alpine's teardown hook). `$cleanup` isn't an Alpine magic: `init()` used to throw on every load and the window/document listeners were never removed. Register every new window/document listener through `bind(target, event, handler, capture)`, which records its own remover. Filament's SPA navigation swaps pages without a reload, so a leaked listener would stack up on every visit.

### The Livewire contract (renaming either side breaks the canvas silently, so run `scripts/check-drift.sh`)

| JS calls | JS reads |
|---|---|
| `$wire.save()`, `undo()`, `redo()`, `selectBlock(id\|null)`, `duplicateBlock(id)`, `removeBlock(id)`, `moveBlock(id, to, parent, slot)`, `insertBlock(type, at, parent, slot)`, `setBlockField(id, field, value)` | `$wire.isDirty`, `$wire.selectedId` |

### The DOM contract (attributes the JS reads, and who emits them)

| Element | Attributes | Emitted by |
|---|---|---|
| `.fpb-block` | `data-id`, `data-parent`, `data-slot`, `data-has-content`, `data-selected` (plus `data-unknown`, which only CSS reads, and `data-container`, which nothing reads yet) | `components/canvas-block.blade.php` |
| `.fpb-slot` | `data-fpb-parent`, `data-fpb-slot` | `PageBuilder::slot()` (editing only) |
| editable field | `data-fpb-block`, `data-fpb-field`, `data-fpb-kind`, `data-fpb-multiline`, `data-fpb-placeholder`, `contenteditable` | `PageBuilder::editableAttributes()` |
| set by the JS itself | `data-dragging`, `data-drop-active`, `data-fpb-original`, `data-tone` | |

The block wrapper uses `data-parent`/`data-slot`, but a slot uses `data-fpb-parent`/`data-fpb-slot`. Don't "normalise" one side without the other.

---

## Drag and drop

It uses native HTML5 DnD. Filament bundles SortableJS but doesn't expose it as a global, and vendoring it was only ever a ROADMAP idea, never done.

- **Target resolution (`targetFromEvent`)**: the nearest `.fpb-slot` inside the canvas, otherwise the root canvas. The index is the first *direct* `.fpb-block` child whose **vertical midpoint** is below the pointer, skipping the dragged block, and it's sent unadjusted, which already matches `moveBlock`'s before-lift contract ([STORAGE.md](STORAGE.md)). Blocks inside a slot are always stacked vertically, and side-by-side layout comes only from separate slots.
- The client rejects self-nesting by DOM containment (`isInvalidParent`), and the server re-checks. **A drop is one Livewire call.**
- The empty `if` blocks in `targetFromEvent` (the `overCanvas` check and the `mode === 'move'` / `dragged` branch) are **vestigial comment-only no-ops**. Don't read logic into them.
- `autoscroll()` exists because native DnD won't scroll a nested scroller. `.fpb-canvas-frame` is the scroller.
- The palette uses both `draggable` + `x-on:dragstart` **and** `wire:click="insertBlock(...)"`. A click inserts at the selection, a drag inserts at the drop point.

---

## Motion (`const motion`, Anime.js)

It is purely presentational and runs *after* state has already changed. When Anime.js is missing, the tab is hidden, or `prefers-reduced-motion` is set, it's disabled.

- **`motion.play()` calls `complete` immediately when disabled.** Callers put real work there: `remove()` calls `$wire.removeBlock()` *inside* `complete`. Route every animation through `motion.play`, never call `window.anime` directly, and never put required work in any other callback.
- **FLIP:** `captureRects()` runs on every `pointerdown` (capture phase) and is trusted for 1500 ms. A `MutationObserver` on the canvas calls `settle()` once per frame: an id that wasn't in `seen` gets `enter()` (fade, ring, scroll into view), and an id whose box moved gets `slide()`.
- **`isOwnChrome()`** filters mutations that only add or remove `.fpb-drop-marker` / `.fpb-flash`. **Any new decoration element inserted into the canvas must be added there.** The 3 px drop marker moving on every `dragover` used to make every block twitch.

---

## Keyboard

| Keys | Action | While typing? |
|---|---|---|
| ⌘/Ctrl S, ⌘Z, ⇧⌘Z | save / undo / redo | **Intercepted anyway**: they're handled *before* `isTyping()`. Native text-undo inside inspector inputs and contenteditables is unavailable, and ⌘Z runs server undo. (Commit `c76513c` says all shortcuts are ignored while typing, but the code disagrees for these three.) |
| ⌘D, Backspace/Delete, ↑/↓, ⇧↑/⇧↓ | duplicate / delete (confirmed if `data-has-content`) / walk the selection in canvas order / move among siblings | ignored |
| Esc | deselect | inside an editable: reverts the text, with `stopPropagation` from the capture-phase root listener so it doesn't also deselect |

`isTyping()` = `isContentEditable`, INPUT/TEXTAREA/SELECT, or inside `[contenteditable="true"]`. `⇧↑/↓` sends `to + 1` when moving down, which is the before-lift contract again.

---

## Inline text editing

- `focusin` on a `[data-fpb-field]` records `innerText` as `data-fpb-original` and selects the block if it isn't already selected, so the inspector follows the caret.
- **Commit on blur, never per keystroke.** A round trip per character would re-render the block under the caret. `Enter` blurs unless `data-fpb-multiline="true"`, and `Esc` restores the original text.
- **⌘S while the caret is still in a field saves *without* that edit**: there's no blur, so no `setBlockField`. The status reads "All changes saved", and blurring afterwards makes the page dirty again. The toolbar button is safe because its click blurs first.
- The global `Livewire.hook('morph.updating')` **skips morphing the focused editable**. Otherwise any re-render (a selection, an inspector field) rewrites the text under the caret and moves the caret to the end.
- The value is `innerText`, which reports **rendered** text. A multiline field's element needs `white-space: pre-line` (or `pre-wrap`) in the block's CSS. Without it, a typed line break shows collapsed after the re-render, and the next edit writes the collapsed text back. The shipped `.fpb-text` currently has no `white-space` rule.

Unsaved-work guards: `beforeunload` and Livewire's `livewire:navigate` (a `confirm()`), because Filament's SPA navigation never fires `beforeunload`. Both read `$wire.isDirty`.

---

## CSS: rules with reasons

| Rule | Why |
|---|---|
| `body.fpb-edit-mode` hides `.fi-topbar-ctn`, `.fi-sidebar`, `.fi-sidebar-close-overlay`, `.fi-layout-sidebar-toggle-btn-ctn`, `.fi-header`, and zeroes margin/padding on `.fi-layout … .fi-page-content` | The full-screen editor. It's coupled to **Filament 5's internal class names**, and a rename in an upgrade silently brings the chrome back. `scripts/check-drift.sh` verifies them against `vendor/`. Below 1280 px the grid stacks and the page scrolls. |
| `.fpb-block-body { pointer-events: none }`; `[data-fpb-field]` and `.fpb-slot` set `auto` | A click anywhere selects the block, an editable takes a caret, and a slot accepts drops. **Links, buttons and Alpine widgets inside blocks (accordions, tabs) are inert on the canvas**, so content hidden behind interaction can only be edited in the inspector. |
| `.fpb-canvas-frame { align-items: flex-start }` | Load-bearing. `stretch` pinned the canvas to the frame height, and with the canvas's `overflow: hidden` (kept for the rounded corners), a page taller than the viewport was clipped with no scroll (incident `29cc533`). |
| Dark values on `html.dark .fpb`, not `:root` | An app setting `--fpb-editor-bg` / `--fpb-panel-bg` / `--fpb-raised-bg` on `:root` still wins in light mode. Chrome text colour is inherited from Filament, so only backgrounds need dark values (incident `a9dced0`). |
| `--fpb-canvas-bg` / `--fpb-canvas-fg` stay light in dark mode | The canvas previews the **public** page, not the admin UI. A dark site overrides the pair. |
| `body.fpb-edit-mode .fpb-slot` draws the dashed drop wells | Wells exist only in the editor. On the public page a slot is a bare grid cell (`min-width: 0`). |
| Token rules come in pairs: `.fpb-block[...] > .fpb-block-body > *` / `.fpb-block-body` / `.fpb-block` **and** `.fpb-el[...]` | The canvas can't restyle `.fpb-block` itself (outline, bar), so padding lands on the block's root element, background and alignment on the body, and width on the wrapper. The public side styles `.fpb-el`. **App token CSS must cover both forms.** Copy the package's pairs. |
| `@media (prefers-reduced-motion)` kills transitions | The CSS half of reduced motion. The JS `motion.enabled` check is the other half. |

docs/index.html's "Chrome classes you can override" lists `.fpb-toolbar`, which doesn't exist. The toolbar is `.fpb-chrome` / `.fpb-toolbar-actions`.
