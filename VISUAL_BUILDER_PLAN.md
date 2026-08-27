# Filament Page Builder — Visual Builder Plan

> Planning document. **Nothing here is built.**
> Proposes a WordPress/Gutenberg-style drag-and-drop editing canvas, delivered as a **standalone
> Filament plugin** rather than app code. DAME UPLB is the plugin's first consumer and reference
> implementation.
>
> **Author:** Carl Janzell (`cnoropesa@up.edu.ph`)
> **Working package name:** `carljanzell/filament-page-builder` (confirm before `composer init`)
> Prerequisite context: [PHASE_1_PROMPT.md](PHASE_1_PROMPT.md).

---

## What changed in this plan

The original CMS plan said "build in-app first, extract to a plugin later" (Phase 4). That still
holds for the *existing* form-based builder. The decision now is that **the visual builder is built
inside the plugin from day one, not extracted into it afterwards.**

That is the right call, and it changes more than packaging. The canvas is exactly the component
where app-specific coupling would creep in — it touches block definitions, the model, the renderer,
and the assets all at once. Building it behind a package boundary forces the extension API to be
correct while the code is still small. Building it in-app and extracting later means designing that
boundary twice, with a much larger surface the second time.

---

## Where we are

The CMS works and is deployed. `Page` records store content as an ordered array of typed blocks in a
`blocks` JSON column. Nine block types exist (`hero`, `rich_text`, `image_text`, `cta`, `stat_row`,
`card_grid`, `accordion`, `staff_grid`, `custom_html`), each with a Filament `Builder` definition and
a matching Blade component. Editing is a **form**: a stack of collapsible panels with a live preview
beside it. 149 Pest tests cover it.

What that is not: you cannot drop a block onto the page where you want it, you cannot type directly
on the rendered page, and you cannot set a block's width or spacing.

## The two decisions that govern everything

**1. Both editing modes read and write the same `blocks` JSON.** The canvas is a second editing
surface over existing data, not a second CMS. A page started in the form opens in the canvas and
back again. If the canvas is a dead end, deleting it costs nothing — the content survives. Any
design that forks the data format should be rejected outright.

**2. The plugin owns the *mechanism*; the app owns the *content*.** This is the boundary that makes
the package reusable, and it is the part most likely to be got wrong. See below.

---

## The boundary: plugin vs app

This is the heart of the work. Everything currently in `app/` has to be sorted into one of two
buckets, and some of it is genuinely coupled today.

| Concern | Lives in | Why |
|---|---|---|
| The canvas, palette, inspector | **Plugin** | Generic mechanism |
| Block registry and the block contract | **Plugin** | Generic mechanism |
| `Builder` field integration | **Plugin** | Generic mechanism |
| Renderer dispatcher (`<x-blocks.render>`) | **Plugin** | Generic mechanism |
| Preview state normalisation (rich text, uploads) | **Plugin** | Filament behaviour, not app behaviour |
| `HasBlocks` trait + block `id` management | **Plugin** | Generic mechanism |
| Canvas JS/CSS assets | **Plugin** | Ships with the package |
| `Page` model, migration, factory | **App** | The plugin must not own your schema |
| Concrete blocks (`hero`, `staff_grid`, …) | **App** | `staff_grid` queries `App\Models\Personnel` |
| Block Blade components + design tokens | **App** | UPLB brand, not a package concern |
| Permissions, policies, Shield wiring | **App** | Every app authorises differently |
| Public routes, `PageController`, nav handling | **App** | `handlesOwnNavArea` is a DAME layout detail |

Three pieces of current code are coupled and must change during extraction:

- **`PageBlocks::all()` is a static list.** It becomes a registry the app populates. This is the
  single biggest refactor in the plan.
- **`PageBlocks::FILE_FIELDS = ['image']`** hardcodes which fields hold uploads. In a plugin each
  block must *declare* its own file fields, since the plugin cannot know a consumer's field names.
  The extraction forces a better design here.
- **`custom_html` is gated on `hasRole('super_admin')`.** That becomes an authorisation callback the
  consuming app supplies; the plugin must not assume `spatie/laravel-permission` is installed.

## Block registration API

Blocks become classes implementing a plugin contract, rather than entries in a static array:

```php
// In the plugin
interface PageBlock
{
    public static function type(): string;          // 'hero'
    public static function label(): string;
    public static function icon(): ?string;
    public static function schema(): array;         // Filament fields
    public static function view(): string;          // Blade component name
    public static function fileFields(): array;     // fields holding uploads
    public static function isVisible(): bool;       // authorisation hook
}
```

Registered by the app on the panel:

```php
FilamentPageBuilderPlugin::make()
    ->blocks([
        HeroBlock::class,
        RichTextBlock::class,
        StaffGridBlock::class,   // free to reference App\Models\Personnel
        CustomHtmlBlock::class,
    ])
    ->recordModel(Page::class)
    ->blocksAttribute('blocks')
```

`recordModel()` and `blocksAttribute()` keep the plugin from owning your schema — it works against
any model with a JSON blocks column, via a `HasBlocks` trait the app applies.

## Package structure

```
packages/filament-page-builder/
├── composer.json                  # carljanzell/filament-page-builder, MIT
├── src/
│   ├── FilamentPageBuilderPlugin.php      # implements Filament\Contracts\Plugin
│   ├── PageBuilderServiceProvider.php
│   ├── Contracts/PageBlock.php
│   ├── BlockRegistry.php
│   ├── Concerns/HasBlocks.php             # trait for the consuming model
│   ├── Filament/Pages/DesignPage.php      # the canvas
│   ├── Support/BlockStateNormaliser.php   # rich text + upload state
│   └── Commands/…
├── resources/
│   ├── views/                     # canvas, palette, inspector
│   ├── js/page-builder.js         # hand-written, no bundler
│   └── css/page-builder.css
└── tests/
```

Verified against the installed Filament: `Filament\Contracts\Plugin` requires `getId()`,
`register(Panel $panel)` and `boot(Panel $panel)`; panels accept plugins via `->plugin()` /
`->plugins()`.

## Development workflow — develop in-repo, extract cleanly

Do **not** start by moving files into a separate git repository. Use a Composer path repository so
the package lives inside this repo while it is being built:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "packages/filament-page-builder", "options": { "symlink": true } }
],
"require": { "carljanzell/filament-page-builder": "@dev" }
```

This matters because it lets extraction be **incremental with tests green the whole way**: move one
class, run the 149 existing tests, repeat. The app keeps working throughout, and the package gets
split into its own repository only once it is stable and the API has stopped moving.

⚠️ **This changes `composer.json`, so the deploy pipeline is involved.** A path repository with
`symlink: true` does not survive `composer install --no-dev --optimize-autoloader` on a machine
where `packages/` is not present — but here it *is* present, since it is committed in the same repo.
Verify a deploy before relying on it. Once the package moves to its own repository it becomes a
normal VCS/Packagist dependency and this concern disappears.

---

## The constraint that shapes the build

**The production host has no Node or npm.** It cannot run `npm run build`, and the committed
Tailwind bundle is a stale hand-made snapshot. This already bit us once — the first pass of block
components used Tailwind utilities that do not exist in the deployed CSS.

A drag-and-drop canvas is normally a JS build problem. Here it cannot be. All three enabling facts
are verified against the installed version:

| Need | Already available | Where |
|---|---|---|
| Reactivity / state | Alpine, bundled by Filament | `public/js/filament/support/support.js` |
| Drag & drop | SortableJS, bundled by Filament | `vendor/filament/support/dist/index.js` |
| Shipping package JS | `FilamentAsset::register([...], package: 'carljanzell/filament-page-builder')` | `AssetManager::register(array $assets, string $package = 'app')` |

`AssetManager::register()` takes a package argument and namespaces assets per package, so the plugin
ships its own JS and CSS without touching the app's build. `php artisan filament:assets` publishes
them, and deploy already runs `filament:optimize` after `composer install`.

**Rule for this plugin: if a feature needs a bundler, it is out of scope.** A package that requires
consumers to run a JS build is a worse package anyway.

---

## Being honest about scope

"WordPress-like" covers a wide range. Gutenberg is roughly a decade of work by a full-time team.
Ranked by how much of the *felt* experience each delivers per unit of effort:

| Capability | Perceived value | Effort | Verdict |
|---|---|---|---|
| Drag a block from a palette onto the page | High | Medium | **Stage A** |
| Drag to reorder on the rendered page | High | Low | **Stage A** |
| Click a block to select and edit it | High | Low | **Stage A** |
| Type directly on the page | High | Medium–High | **Stage B** |
| Per-block width, spacing, background | Medium | Medium | **Stage C** |
| Saved/reusable sections | Medium | Low | **Stage D** |
| Free positioning, arbitrary nesting, columns-in-columns | Low | Very High | **Out of scope** |
| Theme/template editing, global styles | Low | Very High | **Out of scope** |

The last two are where page builders go to die. A constrained block palette that always produces
on-brand output is a *feature* — free positioning mostly gives editors ways to break the design.
As a published package this is also a positioning decision: "an opinionated block canvas for
Filament" is a defensible product; "a worse Gutenberg" is not.

**Stages A + B get roughly 80% of the WordPress feel.**

---

## Stage 0 — extraction (new, and it comes first)

Before any canvas work, move the existing mechanism into the package behind the path repository:

1. Scaffold the package, service provider, and plugin class; register it on the admin panel.
2. Introduce the `PageBlock` contract and `BlockRegistry`; convert the nine existing blocks to
   classes in the **app**, registered through the plugin.
3. Move the renderer dispatcher and state normaliser into the package. Keep the Blade components and
   design tokens in the app.
4. Move `blocks` handling onto a `HasBlocks` trait applied to `App\Models\Page`.
5. Keep all 149 tests green throughout; add package-level tests as classes move.

No user-visible change. That is the point — it is a refactor with test cover, not a rewrite.

## Stage A — the canvas

A full-screen Filament page rendering the real blocks, with selection and drag-and-drop.

Provided by the plugin as `DesignPage`, reachable at `/admin/pages/{record}/design` and added as a
"Design" header action next to the existing editor. `EditPage` stays exactly as it is — structured
work (SEO, slug, publish date) belongs in the form; layout work happens on the canvas.

Layout: block palette left, canvas centre, selected-block inspector right.

### Rendering

Render the same Blade components the public site uses, each wrapped in a selectable shell:

```blade
@foreach ($blocks as $block)
    <div class="fpb-block" data-id="{{ $block['id'] }}" wire:key="blk-{{ $block['id'] }}">
        <x-dynamic-component :component="$registry->view($block['type'])" :data="$block['data']" />
    </div>
@endforeach
```

**Inline, not an iframe.** An iframe gives perfect CSS isolation but makes drag-and-drop across the
boundary and Livewire state sync painful. Inline rendering inside a token-scoped container is the
approach the existing live preview already uses successfully. Cost: panel CSS can leak in — scope
block styles under a wrapper class.

**Every block needs a stable `id`.** Identity is positional today, which breaks drag-and-drop, undo,
and Livewire's DOM diffing.

### Mutations

SortableJS on the canvas for reordering, a second Sortable group for the palette. On drop, Alpine
calls the Livewire component:

```php
public function moveBlock(string $id, int $to): void
public function insertBlock(string $type, int $at): void
public function removeBlock(string $id): void
public function duplicateBlock(string $id): void
```

Reorder optimistically in Alpine and persist on an explicit Save — not on every drag.

### The inspector

Selecting a block renders that block's `schema()` from the registry. One source of truth for what
fields a block has; a second set of definitions is how the two modes start telling editors different
things.

### Stage A deliverables

- `DesignPage` + canvas/palette/inspector views, in the package
- One hand-written Alpine/JS asset registered under the package namespace, no bundler
- Block `id` backfill migration (app-side, since the app owns the table)
- Reorder, insert, delete, duplicate, select, save
- Tests: mutations produce valid `blocks` JSON; a page round-trips form → canvas → form unchanged

---

## Stage B — editing on the page

Mark simple text fields (`heading`, `subheading`, CTA labels) `contenteditable` and sync on blur.
Blur rather than keystroke, for the same reason the current preview uses `->live(onBlur: true)`.

Rich text is the trap. A `contenteditable` region that must reproduce `RichEditor`'s HTML will
diverge — TipTap already owns that format, and we have already been bitten once by the difference
between TipTap document state and stored HTML. **Recommendation: clicking a `rich_text` block opens
the real `RichEditor` in the inspector rather than editing in place.** If editors specifically ask
for inline rich text later, mount an actual TipTap instance; do not improvise a second producer.

## Stage C — layout controls

An optional `layout` key per block: width (`normal` / `wide` / `full`), spacing (a small scale, not
free pixels), background (`white` / `tint` / `dark`).

Deliberately a **constrained scale**. Three spacing values that always look right beat a pixel field
that lets someone set 7px. The plugin defines the vocabulary; the **app** maps it to CSS classes, so
each consumer stays on its own brand.

## Stage D — reusable sections

Save a run of blocks as a named section, insertable into any page. Cheap once Stage A exists: a
`sections` table holding a blocks fragment plus an insert action. This is what editors ask for once
they have built the same CTA five times.

---

## Data model changes

Two additive, backward-compatible changes:

```jsonc
{
  "id": "0193f2a1-…",   // NEW — stable identity for drag/undo/DOM diffing
  "type": "hero",
  "data": { … },        // unchanged
  "layout": {           // NEW, Stage C, optional
    "width": "wide",
    "spacing": "loose",
    "background": "tint"
  }
}
```

A migration backfills `id`. `layout` is absent until set, and the renderer defaults it. No column
changes — still one JSON column. Block components already read fields defensively with `??`
fallbacks, which is exactly why this stays backward compatible.

---

## Risks

**Getting the plugin boundary wrong.** The new top risk. If DAME specifics leak into the package it
is not reusable; if the extension API is too abstract it is unusable. Mitigation: the app is the
first consumer and must not be special-cased — if something cannot be expressed through the public
plugin API, the API is wrong.

**`memory_limit`.** Raised to 256M in production, confirmed working. The canvas re-renders every
block per Livewire round-trip on a box with `max_children = 5` — still the heaviest thing the panel
will do, so watch it under real editing load.

**Livewire round-trip latency.** Every drag hitting the server feels sluggish. Reorder optimistically
in Alpine, sync without a full re-render, save explicitly.

**Two editing surfaces diverging.** Prevented structurally by one registry and one set of Blade
components.

**Path-repository deploys.** New. Verify `composer install --no-dev` resolves the symlinked package
on the VM before depending on it.

**Scope creep toward "real WordPress".** Every request will be to go one step further — columns, then
nested columns, then per-block CSS. Hold the line at the block palette.

---

## Sequencing

Phase 3 (migrating the hardcoded pages) is largely done: ten of eleven pages are imported as drafts
via `custom_html`, pending per-page cutover. `faculty` is excluded — it is already a Livewire
component on live `Personnel` data.

**Recommended order:**

1. **Finish Phase 3 cutover.** One page end-to-end first, then the rest. This delivers the actual
   business goal — editors owning content without a deploy — and is independent of everything below.
2. **Stage 0 — extraction.** Mechanical, test-covered, no user-visible change.
3. **Stage A — the canvas.**
4. Stages B → D as appetite allows.

Doing extraction before the canvas is the whole point of this revision: it is far cheaper to define
the boundary around nine known block types than around a canvas that has already grown into the app.

## Alternatives considered

**Build in-app, extract later.** The original plan. Superseded — it means designing the plugin
boundary twice, the second time around a much larger surface.

**Use an off-the-shelf plugin.** Worth a look before building, but the mainstream Filament
page-builder packages are all `Builder`-field based — the same form-stack editing we already have.
None provide a drag-and-drop canvas.

**Do nothing.** Still genuinely viable for DAME. The current builder is functional and on-brand, and
editors can already work without a developer. The honest case for continuing is that the *plugin*
has value beyond this one site — as a DAME-only feature, the cost/benefit is much thinner. Worth
revisiting once real editors have used the form builder on real content and said what actually hurts.
