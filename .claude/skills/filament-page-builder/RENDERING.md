# Rendering: one component, two contexts

A block's own Blade view renders **both** the public page and the canvas. There is no second renderer to drift from the first. Something outside the view has to say which context is active, and that's `src/PageBuilder.php`: a static render-context stack the view asks through `@editable`, `PageBuilder::shows()`, `slotNames()` and `slot()`.

Related: [BLOCKS.md](BLOCKS.md) (contracts, editables), [STORAGE.md](STORAGE.md) (the tree being walked), [FRONTEND.md](FRONTEND.md) (CSS for the output).

---

## The two paths

| | Canvas | Public |
|---|---|---|
| Entry | `design.blade.php` → `@forelse ($this->rootBlocks)` | `<x-page-builder::blocks :blocks="$page->blocks" />` (`components/blocks.blade.php`) |
| Input | `DesignPage::decorate()`d tree: data already normalised, `children[slot]` pre-built | Raw column → `BlockTree::hydrate()` → `childrenOf(null)`, so it accepts v1 lists and raw DB JSON |
| Per-block view | `components/canvas-block.blade.php` | `components/public-block.blade.php` |
| Wrapper | `.fpb-block` (bar, handle, tools, `.fpb-block-body`) | `.fpb-el` inside a `.fpb-page` root (the root merges `$attributes->class`) |
| Context | `PageBuilder::editing($id, $type, $data)` → `isEditing()` true | `PageBuilder::rendering($type, $data)` → `blockId` null → `isEditing()` false |
| Slot filler | Renders `components/canvas-slot` with the pre-decorated children | Renders `public-block` for each `BlockTree::childrenOf($blocks, $id, $slot)` |
| Unknown type | A hatched "retired block" placeholder that can be moved and removed | **Skipped, with its whole subtree.** The children of a retired container vanish publicly but stay in the data |
| Style tokens | `data-fpb-{token}` on `.fpb-block` | `data-fpb-{token}` on `.fpb-el` |

### How a view is invoked (both paths)
`view()` **containing `::`** → `@include($view, ['data' => $data])`. **Otherwise** → `<x-dynamic-component :component="$view" :data="$data" />`, so `blocks.hero` resolves to `resources/views/components/blocks/hero.blade.php`.

The shipped primitives return *view paths* (`page-builder::components.text`), which are **not component names**. *Verified:* `<x-dynamic-component component="page-builder::components.divider">` throws `Unable to locate a class or view for component`. **A hand-rolled consumer loop that feeds `$registry->view()` to `<x-dynamic-component>` returns a 500 the moment an editor drops a shipped primitive.** It also prints section children as extra top-level blocks. Consumers should render through `<x-page-builder::blocks>`. If they can't, they need to copy both the `::` branch and the tree walk.

An app block whose `view()` is namespaced (for example from another package) is `@include`d too. It gets `$data` as a plain variable, and `@props` still compiles, but `$attributes` is empty.

---

## The context stack (`PageBuilder`)

`editing()` and `rendering()` both **push** a snapshot (`blockId`, `blockType`, `blockData`, `slotNames`, `slotRenderer`) and then set the new context. `idle()` **pops** it, or clears everything if the stack is empty. Nested containers render their children *during* the parent view's `slot()` call, so each child pushes and pops while the parent's context sits underneath.

- **Every push needs exactly one pop.** Incident `dc08dbc`: `public-block` pushed only for containers but popped for every block. A leaf child's `idle()` popped its parent's snapshot and nulled the slot renderer, so every column after the first rendered empty, **on the public site only** (the canvas looked right). Every block now pushes because every block pops, and the test `leaves no render context behind after a public render` guards it.
- **There's no `try/finally` around the include.** An exception inside a block view leaves the context pushed. Under PHP-FPM that dies with the request, but under Octane or a queue worker that renders pages, `isEditing()` could stay true into the next render and `@editable` would emit `contenteditable` on a public page. Wrap any new long-lived rendering in `try/finally`, or call `idle()` defensively.
- Tests that render views directly call `PageBuilder::idle()` first to clear anything a previous test left behind.

### `@editable('field')`
Compiled by `PageBuilderServiceProvider::registerDirectives()` to `PageBuilder::editableAttributes('field')`. It emits `data-fpb-block`, `data-fpb-field`, `data-fpb-kind` (plus `contenteditable="plaintext-only"` and `data-fpb-multiline` for text, and `data-fpb-placeholder` if one is set) **only** when `isEditing()` is true **and** the current type declares the field in `editables()`. Everywhere else it emits an empty string, so the public HTML is identical minus the attributes.

---

## Slots: writing a container view

```blade
<section class="fpb-section" data-fpb-ratio="{{ $ratio }}">
    @foreach (PageBuilder::slotNames() as $name)
        {!! PageBuilder::slot($name) !!}
    @endforeach
</section>
```

- `slotNames()` returns the renderer-provided names, falling back to `registry->slots($type, $data)`.
- **`slot()` always wraps its content in `<div class="fpb-slot">`.** That wrapper keeps a column's blocks in *one* grid cell. Without it, each child of a `display: grid` section becomes its own cell and a column holding two blocks spills into the next (incident `dc08dbc`, test `keeps a column a single grid item on the public page`). In editing mode, only `data-fpb-parent` / `data-fpb-slot` and the "Drop a block here" hint are added.
- The "empty" check strips HTML comments first, because an empty `@foreach` still emits Blade/Livewire markers.
- A new container needs only `implements Container` and a view that outputs **every** `slotNames()` entry through `slot()`. Drop targets, the outline, duplicate/remove and the public render all derive from `slots()`. A slot the view forgets to output becomes a hidden-slot ghost ([STORAGE.md](STORAGE.md)).

---

## Style token emission

Both wrappers emit `data-fpb-{token}="{value}"` only for keys matching **`/^[a-z-]+$/`** with **string** values. A token named `max_width` or `h2` is **stored by the inspector but never emitted**. Token names are also joined into the `data-fpb-*` namespace, so **avoid `field`, `block`, `kind`, `multiline`, `placeholder`, `parent` and `slot`**. A token named `field`, for example, puts `data-fpb-field` on the block wrapper, and the inline-editing JS (`closest('[data-fpb-field]')`) then treats the whole block as an editor.

---

## CSS on the two surfaces

- **Canvas:** `canvasStylesView('view.name')` is `@include`d *inside* `.fpb-canvas`, before the blocks, on every Livewire render. Scope its rules under `.fpb-canvas`. A bare `body` or `h2` rule restyles the builder around it.
- **Public:** the package stylesheet is **not** loaded on public pages automatically (FilamentAsset serves panels only). Either link the published `public/css/carljanzell/filament-page-builder/page-builder.css`, or replicate the rules the markup depends on: the `.fpb-section` grid with its `data-fpb-ratio` tracks, `.fpb-slot { min-width: 0 }`, the primitives (`.fpb-text`, `.fpb-button`, `.fpb-image`, `.fpb-spacer[data-fpb-height]`, `.fpb-divider`) and the `.fpb-el[data-fpb-*]` token rules. Without the section rules, columns simply stack. `.fpb-page .fpb-section { padding: 0 }` is public-only.
- **Responsive preview is a `max-width` on `.fpb-canvas` (768 / 390 px), not an iframe.** `@media` queries in block CSS never fire, so "Mobile" shows the desktop layout squeezed. Only `@container` queries respond (ROADMAP §4.4), and only against an ancestor that declares `container-type`. The package sets none on `.fpb-canvas`, so the app has to add one, inside `canvasStylesView` and on its public wrapper. The canvas stays inline rather than in an iframe on purpose: an iframe breaks cross-boundary drag and Livewire sync.
