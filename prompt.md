# Roadmap: full-screen live editing (WordPress-like)

> Implementation prompt for the next stages of `carljanzell/filament-page-builder`.
> Stage A (drag-and-drop canvas inside Filament) is shipped. This document is the plan
> for escaping the panel chrome and giving editors the *front of the site*, not a
> three-column form that happens to render blocks.
>
> Keep [VISUAL_BUILDER_PLAN.md](VISUAL_BUILDER_PLAN.md) as the original Stage 0–D
> design. This file supersedes only the *presentation* of the canvas (it is not
> actually full-screen today) and adds a frontend-editing track. Stages B–D still
> apply; they become more valuable once the canvas is the real page.

---

## What we have

The package is a second editing surface over the same `blocks` JSON as Filament's
`Builder` field. That contract must not change.

| Piece | Today |
|---|---|
| Entry | Resource page at `/admin/pages/{record}/design` |
| Shell | `<x-filament-panels::page>` — sidebar, topbar, breadcrumbs, content padding |
| Layout | Three columns inside that content: palette · canvas · inspector |
| Blocks | Real Blade components, **inline** (not an iframe), styles injected via `canvasStylesView()` |
| Mutations | Alpine native drag-and-drop → Livewire `moveBlock` / `insertBlock` / … ; persist on Save |
| Interaction | `.fpb-block-body { pointer-events: none }` so clicks select the block instead of hitting links, forms, accordions, tabs |
| JS constraint | Hand-written Alpine, **no bundler**, hosts without Node |

This is a competent *admin canvas*. It is not live editing. The editor never leaves
Filament, never sees site header/nav/footer, never walks the public IA, and cannot
use the page as a visitor would.

The original plan called Stage A a “full-screen Filament page.” The implementation
is a normal resource page. That gap is the starting point, not a new product.

---

## What “WordPress-like” means here

WordPress is several products. Copy the *felt* ones, not Gutenberg’s internals.

| WordPress behaviour | What editors actually feel | Our analogue |
|---|---|---|
| Customizer / Elementor | The **public page** is the canvas; chrome is a thin overlay | Render (or iframe) the site layout; palette/inspector as drawers |
| Admin bar on the front | “Edit this page” from the URL you are looking at | Authenticated front-end entry into the designer |
| Preview vs edit | Click links, open menus, then go back to selecting blocks | Explicit **Interact** vs **Select** modes |
| Navigate the site while designing | Open another page without returning to `wp-admin` lists | In-canvas page switcher + click-through in Interact mode |
| Fullscreen Gutenberg | Sidebar/admin UI gone; only top bar + optional side panels | Filament shell gone; collapsible inserter and inspector |

Hold the existing out-of-scope line: no free positioning, no nested columns-in-columns,
no theme/template editor. A constrained block palette that stays on-brand is still
the product.

**Stages A + this track + Stage B ≈ the WordPress feel.** Layout scales (C) and
reusable sections (D) stay as in the original plan.

---

## Non-negotiables (do not redesign these)

1. **One JSON.** Form editor and canvas remain interchangeable. No second document
   format, no “design mode” column.
2. **Plugin owns mechanism; app owns content.** Site header, nav, footer, tokens,
   and block Blade views stay in the app. The plugin adds *hooks* (`canvasLayoutView`,
   preview URL, record resolver), not a `Page` model.
3. **No bundler.** If a feature needs a JS build, it is out of scope.
4. **Unknown block types are skipped**, not fatal.
5. **Optimistic drag, explicit save.** Do not persist on every drop.

---

## Why the current canvas cannot become this by CSS alone

Three structural choices fight front-end editing:

1. **Filament layout.** Sidebar + topbar + padded content steal viewport and leak
   panel CSS (fonts, buttons, dark mode) onto blocks. `canvasStylesView()` only
   injects *inside* `.fpb-canvas`.
2. **`pointer-events: none` on `.fpb-block-body`.** Selection requires it. Real
   navigation, accordions, tabs, sliders, and forms are dead. WordPress-like
   “trigger the controls” is impossible until this is a **mode**, not a constant.
3. **No site chrome.** The canvas is a stack of blocks on a white card. It cannot
   show whether a hero sits under the real header, or whether a CTA is above the
   real footer.

The original plan rejected an iframe because Sortable/Livewire across a document
boundary is painful. That trade-off still stands for **Select** mode (drag, insert,
inspector). It does **not** forbid an iframe (or a full public-layout render) for
**Interact / Preview**. Split the modes instead of picking one rendering strategy
for everything.

---

## Target UX

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [☰ Blocks]  Home / About / …     Desktop|Tablet|Mobile    Interact|Select │
│ Unsaved · [Form editor] [View live] [Save]                    [Inspector]│
├────┬─────────────────────────────────────────────────────────────┬───────┤
│    │  PUBLIC HEADER / NAV (app layout)                           │ Block │
│ P  │  ┌─────────────────────────────────────────────────────┐    │ sett. │
│ a  │  │ block (hover outline, Gutenberg-style toolbar)      │    │       │
│ l  │  │ block                                                │    │ form  │
│ e  │  │ block                                                │    │ from  │
│ t  │  └─────────────────────────────────────────────────────┘    │ schema│
│ t  │  PUBLIC FOOTER                                              │       │
│    │                                                             │       │
└────┴─────────────────────────────────────────────────────────────┴───────┘
```

- Default: **Select** — click selects, drag reorders, palette inserts, inspector
  edits `schema()`. Drawers collapsed until needed (Gutenberg inserter pattern).
- **Interact** — pointer events reach the page. Links, buttons, accordions, maps
  work. A floating “back to Select” control stays visible. Optionally load the
  public URL in an iframe so CSS isolation is perfect.
- **Page switcher** in the top bar lists editable records (app supplies query).
  In Interact mode, clicking an internal link to another managed page can offer
  “open in designer” instead of dumping the user into Filament.
- Viewport widths are a constrained scale (`desktop` / `tablet` / `mobile`), not
  a pixel field — same philosophy as Stage C layout tokens.

---

## Architecture

### Rendering: two modes, one Livewire component

Keep `DesignPage` as the Livewire brain (blocks state, mutations, inspector form,
save). Change only the **shell**.

| Mode | Render | Pointer events | Drag |
|---|---|---|---|
| Select | Inline blocks inside the **app canvas layout** (header/nav/footer Blade view the app registers) | Block bodies none; chrome yes | Yes |
| Interact | Same layout **or** `iframe` of `previewUrl(record)` with `?fpb=1` | All | No |

Prefer **inline + app layout** for Select so drop markers and `$wire` stay in one
document. Prefer **iframe** for Interact if panel CSS still leaks after the
fullscreen shell; the iframe is preview-only, so the original iframe objection
does not apply.

### Escape Filament chrome

Do not keep `<x-filament-panels::page>` as the outer wrapper.

- Register a package layout (e.g. `page-builder::layouts.fullscreen`) that still
  boots Filament/Livewire/Alpine/assets (inspector needs Filament form CSS/JS)
  but **omits** sidebar, topbar, and content max-width.
- Filament 5: override the page layout on `DesignPage` (`getLayout()` / equivalent)
  rather than hiding the sidebar with CSS hacks that break on panel updates.
- The fullscreen view is `position: fixed; inset: 0; z-index: …` so nothing from
  the panel peeks through.

Inspector and palette become **drawers** (overlay, toggle from the top bar), not
permanent 15rem + 22rem columns. On small screens they already stack; drawers
are the desktop equivalent of that honesty.

### App-owned site chrome

New plugin API (names indicative):

```php
FilamentPageBuilderPlugin::make()
    ->blocks([/* … */])
    ->recordModel(Page::class)
    ->blocksAttribute('blocks')
    ->canvasStylesView('filament.pages.canvas-styles')
    ->canvasLayoutView('page-builder.canvas-layout') // NEW: wraps block stack
    ->previewUrl(fn (Model $record): string => url($record->slug)) // NEW
    ->editableRecords(fn () => Page::query()->published()->…); // NEW: switcher
```

`canvasLayoutView` receives the block stack as a slot (or a View Composer). The
app’s public header/footer go there. The plugin must not assume a `layouts.app`
name.

If the app does not register a layout view, fall back to today’s white card so
existing consumers do not break.

### Front-end entry (admin bar)

This is the WordPress moment: edit from the URL you are looking at.

- App (or a thin plugin Blade component the app `@include`s) shows a bar when
  `auth` + `canEdit($page)`.
- Link: `PageResource::getUrl('design', ['record' => $page])` (or the fullscreen
  route once it exists).
- Optional later: same Livewire component mounted on a **non-panel** route
  `/__design/{record}` so the URL feels like the site. Only do this if Filament
  form assets can be loaded without the full panel; if they cannot, stay on the
  panel route with the fullscreen layout. Do not invent a second Livewire class.

### Select vs Interact (required for “trigger the controls”)

Alpine state on the existing `pageBuilderCanvas` component:

- `mode: 'select' | 'interact'`
- Select: keep current drag + `pointer-events: none` on bodies
- Interact: skip dragstart on blocks, restore pointer events, do not `selectBlock`
  on click

Do not try to make one mode do both. Gutenberg does not; Elementor does not.

### Inline text (Stage B, after the shell)

Once the canvas *is* the page, typing on headings matters.

- Simple strings (`heading`, `subheading`, button labels): `contenteditable`,
  sync on **blur** into `blocks[i].data`, mark dirty. Declare editable fields on
  the block contract (e.g. `inlineFields(): array`) so the plugin does not guess.
- Rich text: **inspector only** (existing TipTap). Do not invent a second HTML
  producer. See VISUAL_BUILDER_PLAN Stage B.

### Navigation while designing

1. Top-bar select of editable records (same resource).
2. Interact mode: intercept in-site links (data attribute or same-origin +
   known slug map) → `window.confirm` / small toast → `Livewire` navigate to
   that record’s design URL. External links stay normal.
3. Unsaved dirty flag: prompt before switching pages (browser `beforeunload` +
   Livewire).

---

## Suggested stages (replace “paint CSS fullscreen” as the next slice)

Work in this order. Each stage is shippable alone.

### Stage E0 — Fullscreen shell (plugin)

Leave Filament’s sidebar. Top bar is **only** builder chrome (status, save,
form-editor link, palette/inspector toggles). Palette and inspector become
drawers. Canvas uses the remaining viewport.

**Done when:** an editor can hide both drawers and see only the block stack at
near-viewport size, still saving the same JSON.

No app API changes required.

### Stage E1 — Interact / Select

Toggle pointer events and drag. Keyboard: `Esc` returns to Select.

**Done when:** an accordion or a link inside a block can be used in Interact and
ignored in Select.

### Stage E2 — App canvas layout

`canvasLayoutView` wraps the block list with the public header/footer. Document
the slot contract. Scope plugin chrome CSS so it cannot restyle the site nav.

**Done when:** the consuming app (DAME) shows its real header above the blocks
in Select mode.

### Stage E3 — Viewport presets

Constrained width on the canvas frame (`100%` / `768px` / `390px`), centered,
with a device-like outline. CSS only.

### Stage E4 — Front-end “Edit page” + page switcher

Admin bar snippet + top-bar record switcher + dirty navigation guard.

**Done when:** an editor can open the public URL, click Edit, design, switch to
another page from the top bar, and return to View live.

### Stage E5 — Interact iframe (only if E2 still leaks Filament CSS)

`previewUrl` in an iframe for Interact/Preview. Select stays inline.

### Then original Stage B → C → D

B (inline text) is much less awkward after E0–E2. C (`layout` width/spacing/
background) should apply in the **canvas layout**, not only on the white card.
D (reusable sections) is unchanged: named fragments in a table the **app** owns.

---

## Block contract additions (additive)

Keep existing methods. Add optionals with defaults in a trait or empty array
returns so old blocks keep compiling:

```php
// Fields safe to contenteditable on the canvas (Stage B)
public static function inlineFields(): array; // e.g. ['heading', 'subheading']

// Optional per-block layout vocabulary consumed in Stage C
// (plugin stores JSON; app maps to classes)
```

Do not add `isVisible` replacements or role checks.

---

## Data model

No change for E0–E4. Still one JSON column.

Stage C still adds optional `layout` on each block (see VISUAL_BUILDER_PLAN).
Do not invent a “fullscreen document” type.

---

## Risks specific to this track

**Filament form CSS in a fullscreen layout.** The inspector is a Filament
schema. The layout must still enqueue Filament assets. Isolate them to the
inspector drawer (`#fpb-inspector`) as much as CSS allows.

**Site JS vs builder JS.** Public layouts often ship sliders, menus, Alpine
already. Namespaces: keep `pageBuilderCanvas` on `.fpb` only; do not bind
global click handlers.

**Livewire morphing the public header.** The layout view should be **static
chrome** (not Livewire) wrapping a Livewire island for the block list, or a
single component with `wire:ignore` on header/footer, so a drag does not
re-render the whole nav.

**`memory_limit` / full re-render.** Unchanged: every Livewire round-trip
re-renders blocks. Fullscreen does not make this cheaper. Keep optimistic
reorder; consider `wire:ignore` on unselected block bodies if morphs get
expensive (measure first).

**Auth on preview URLs.** `?fpb=1` preview of drafts must be authorised the
same as `canEdit`. Never make drafts publicly cacheable.

**Scope creep.** “Navigate like WordPress” is page switching + Interact, not a
site editor, menu builder, or widget area manager.

---

## Implementation notes for agents

- Read `src/Filament/Pages/DesignPage.php`, `resources/views/design.blade.php`,
  `resources/js/page-builder.js`, `resources/css/page-builder.css`,
  `src/FilamentPageBuilderPlugin.php` before changing API.
- Preserve `prepareBlocks` naming (not `hydrateBlocks` — Livewire lifecycle).
- Preserve `commitSelectedBlock` + `cacheSchema` behaviour when selection
  changes; drawers must not remount the form in a way that wipes state.
- `HasBlocks` / `BlockStateNormaliser` stay the source of truth for ids and
  TipTap/upload shapes.
- Tests: this package currently has lint-only CI. When adding behaviour, add
  Pest tests in the package for mutations and for “layout view omitted → old
  canvas”. App-level visual tests stay in the consuming app.
- Do not require Node. Do not add SortableJS as an npm dependency; Filament
  still does not expose it as a public global (that is why native DnD exists).

---

## Acceptance (the experience, not the tickets)

An editor can:

1. Open Design and see **no Filament sidebar**.
2. Collapse chrome and look at the page at roughly site scale, **with the app’s
   header and footer**.
3. Switch to **Interact** and use nav, links, and in-page controls.
4. Switch back to **Select**, drag a block, edit it in the inspector, save.
5. From the **public page**, click Edit and land in that same designer.
6. Open another page from the designer without going back to the resource list.
7. Open the existing **form editor** for SEO/slug/publish; JSON round-trips.

If those seven are true, the canvas has become a live editor. Until then it is
still a Filament page with a preview in the middle.
