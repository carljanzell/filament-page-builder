---
name: filament-page-builder
description: "Domain expertise for carljanzell/filament-page-builder, a Composer library (PHP 8.3, Filament 5, Livewire, Alpine, no bundler) that adds a drag-and-drop nested page-builder canvas to Filament and stores content as a flat tree of typed blocks in one JSON column. Load when working on FilamentPageBuilderPlugin, PageBuilderServiceProvider, BlockRegistry, BlockRegistries, PageBlock, Container, InlineEditable, Editable, @editable, PageBuilder::slot / slotNames / shows / editing / rendering / idle, HasBlocks, BlockTree (hydrate, move, insert, duplicate, parent/slot/position, MAX_DEPTH), BlockHistory (undo/redo), BlockStateNormaliser, DesignPage (moveBlock, insertBlock, setBlockField, commitSelectedBlock, cacheSchema, save), SectionBlock, TextBlock, ImageBlock, ButtonBlock, SpacerBlock, DividerBlock, <x-page-builder::blocks>, public-block / canvas-block / canvas-slot views, page-builder.js (pageBuilderCanvas, drag-and-drop, drop marker, inline editing, keyboard shortcuts, Anime.js motion, morph.updating hook), page-builder.css (fpb-*, data-fpb-*, fpb-edit-mode full-screen, style tokens, dark mode), styleTokens, canvasStylesView, blocksAttribute, includeLayoutBlocks, retired/unknown block types, ghost/orphan blocks, the Filament Builder form-editor bridge, filament:assets, docs/index.html, ROADMAP.md, the Testbench/Pest suite, or any file in src/, resources/views/, resources/js/, resources/css/, tests/, docs/."
---

# Filament Page Builder: package skill

**`carljanzell/filament-page-builder`**: a Laravel package (`type: library`) · PHP ^8.3 · Filament ^5 (locked 5.8.2) · Livewire 4 · Alpine (Filament's) · Anime.js 3.2.2 vendored · **no Node, no bundler, ever** · Pest 4 + Orchestra Testbench 11 · proprietary licence, public source.

It adds a full-screen visual canvas beside Filament's `Builder` form field. Both surfaces read and write the same JSON column. **The package owns the mechanism** (registry, canvas, renderer, state handling). **The application owns the content**: block classes, their Blade views, the model and the migration. The package never ships any of those.

---

## Module Deep-Dives

| Working on… | Read |
|---|---|
| The stored JSON shape, `parent`/`slot`/`position`, `BlockTree` ops, the depth cap, orphan/hidden-slot **ghosts**, why form-editor reorders and clones misbehave, `HasBlocks` | [STORAGE.md](STORAGE.md) |
| `DesignPage`: mutations and their auth gates, inspector commit/sync (`getState`, `cacheSchema`), style-settings allow-list, `save()`, **undo history in the session**, the full-screen page overrides, Livewire hook-name traps | [CANVAS.md](CANVAS.md) |
| `page-builder.js` / `page-builder.css`: the `$wire` and DOM contracts, drag and drop, motion, keyboard, inline editing, `.fi-*` full-screen coupling, token CSS pairs, dark mode, **asset publishing and caching** | [FRONTEND.md](FRONTEND.md) |
| Writing or changing a block: the `PageBlock` / `Container` / `InlineEditable` contracts, optional `defaults()`/`category()`, the per-panel registry, shipped primitives (and their bugs), `Editable` kinds, `BlockStateNormaliser` | [BLOCKS.md](BLOCKS.md) |
| Canvas vs public rendering, `view()` resolution (`::` include vs component), the `PageBuilder` context stack, writing a container view with `slot()`, token emission rules, **public-site CSS**, the responsive preview | [RENDERING.md](RENDERING.md) |
| The Testbench harness, fixtures, **the `require_once DesignPageTest.php` trap**, what's untested (all JS), CI, the dev→main auto-merge, consumers on `dev-main` | [TESTING.md](TESTING.md) |
| **How DAME (the first consumer) integrates**: its block library, `PageBlocks` Builder bridge, canvas styles | `../DAME_UPLB/.claude/skills/dame-uplb/CMS.md`. This skill is authoritative for package behaviour, so don't duplicate DAME specifics here. |

---

## Common Commands

```bash
composer install
vendor/bin/pest                          # full suite: 113 tests. Running one file that require_once's DesignPageTest misleads (TESTING.md)
vendor/bin/pest --filter="moves a block" # safe way to run a subset
composer lint                            # pint --test (check only). CI fails on style
.claude/skills/filament-page-builder/scripts/check-drift.sh   # JS↔DesignPage contract, dataset attrs, Filament .fi-* classes

# in a consuming app, after updating the package (JS/CSS are served as published copies):
php artisan filament:assets              # filament:upgrade also runs it; filament:optimize does NOT
```

---

## Architecture

```
src/
  PageBuilderServiceProvider.php  # views namespace `page-builder`, @editable directive, FilamentAsset registration;
                                  #   binds BlockRegistry → the current panel's registry
  FilamentPageBuilderPlugin.php   # fluent config: blocks(), includeLayoutBlocks(), styleTokens(), canvasStylesView(),
                                  #   blocksAttribute(), recordModel() [stored, read by nothing]; register() fills the panel's registry
  BlockRegistries.php             # one BlockRegistry per panel id; current() → current or DEFAULT panel
  BlockRegistry.php               # type → class; has() vs isVisible(); view/fileFields/slots/defaults/category
  PageBuilder.php                 # static render-context stack: editing()/rendering()/idle(), slot(), editableAttributes(), shows()
  Editable.php                    # inline-edit declaration: text | richText, multiline(), placeholder(), accepts()
  Contracts/                      # PageBlock · Container · InlineEditable
  Concerns/HasBlocks.php          # optional model trait: getBlocks(), $blocksAttribute override, ensureBlockIds()
  Blocks/                         # shipped primitives: Section (Container), Text, Image, Button, Spacer, Divider
  Support/BlockTree.php           # the flat tree: hydrate(), move/insert/remove/duplicate, MAX_DEPTH = 5
  Support/BlockHistory.php        # session undo stack, LIMIT 30, tagged by Livewire component id
  Support/BlockStateNormaliser.php # TipTap doc → HTML, upload array → path / temporary URL
  Filament/Pages/DesignPage.php   # abstract canvas page: every mutation, the inspector, save()
resources/
  views/design.blade.php          # editor chrome: toolbar, Blocks/Structure tabs, canvas, Content/Style inspector
  views/structure-node.blade.php  # recursive outline item
  views/components/blocks.blade.php        # <x-page-builder::blocks>: the PUBLIC tree renderer (.fpb-page)
  views/components/public-block.blade.php  # one public block: .fpb-el wrapper, rendering() context, slot filler
  views/components/canvas-block.blade.php  # one canvas block: bar/handle/tools, editing() context, retired placeholder
  views/components/canvas-slot.blade.php   # a slot's children on the canvas
  views/components/{section,text,image,button,spacer,divider}.blade.php  # primitive views (@include'd view paths)
  js/page-builder.js              # Alpine `pageBuilderCanvas`: native DnD, keyboard, inline edit, motion, morph hook
  js/vendor/anime.min.js          # optional; the canvas works without it
  css/page-builder.css            # chrome, primitives, token rules, full-screen .fi-* overrides, dark-mode vars
tests/                            # Testbench harness with its own panels/resources/blocks (TESTING.md)
docs/index.html                   # hand-written GitHub Pages docs, one self-contained file, no build
ROADMAP.md                        # staged plan (Stage 0/B-text/C done; rich text, then Stage D draft/publish next)
```

---

## Global DRY: reuse before writing

| Need | Where |
|---|---|
| Read a record's blocks defensively (accepts v1 lists, repairs keys) | `BlockTree::hydrate()` / `HasBlocks::getBlocks()` |
| Walk or change the tree | `BlockTree::childrenOf()`, `move()`, `insert()`, `remove()`, `duplicate()`, `descendantIds()`, `wouldExceedDepth()` |
| Which column holds the blocks | `$record->blocksAttribute()` → `FilamentPageBuilderPlugin::configuredBlocksAttribute()` (safe off-panel) |
| Registered? Authorable? | `BlockRegistry::has()` vs **`isVisible()`** |
| The current panel's registry | `app(BlockRegistry::class)`, never a cold `BlockRegistries::for()` |
| Editing state → stored state | `BlockStateNormaliser::normaliseData()` (one block) / `normalise()` (whole array) |
| Render blocks on a public page | `<x-page-builder::blocks :blocks="…" />` |
| A container's columns inside its view | `PageBuilder::slotNames()` + `{!! PageBuilder::slot($name) !!}` |
| Canvas-only markup in a block view | `@editable('field')`, `PageBuilder::shows($field, $value)`, `PageBuilder::isEditing()` |
| "Does this block hold anything worth confirming?" | `DesignPage::blockHasContent()` (ignores values equal to `defaults()`) |
| Allowed style-token values | `FilamentPageBuilderPlugin::getStyleTokens()` + `DesignPage::tokenValues()` |
| A window/document listener in the canvas JS | `bind()` (removed again in `destroy()`) |
| Any animation | `motion.play()` / `motion.flash()` / `motion.reset()` |
| A canvas feature test | the `page()` / `block()` / `canvas()` / `ids()` helpers (in `DesignPageTest.php`, see its trap in TESTING.md) |

---

## Critical Warnings

1. **Never prune at persist time.** `save()` writes `$blocks` whole: retired types, ghosts, application keys. Skipping something is correct only at *render* time. Commit `83310f3` fixed a canvas that silently destroyed retired blocks on Save.
2. **Every authoring path asks `isVisible()`, not `has()`.** The canvas is a public Livewire surface, and the palette hiding a block is only cosmetic. Move and remove are *deliberately* ungated. `duplicateBlock()` checks only the root, so it copies descendants the user may not author (CANVAS.md).
3. **Every `PageBuilder::editing()` / `rendering()` needs exactly one `idle()`.** Asymmetric push/pop emptied every column after the first on the public site (`dc08dbc`).
4. **History is recorded only after a change is confirmed**, and tree ops signal refusal by returning their input unchanged (`===`). A no-op recorded as a step makes the next undo look broken.
5. **Commit the inspector with `getState()`, drop `cacheSchema('form', null)` before `fill()`, and never commit inside `syncInspector()`.** Each rule prevents a way of wiping or corrupting block data (CANVAS.md).
6. **No `hydrate{Prop}` / `dehydrate{Prop}` / `updated{Prop}` method names** on `DesignPage` unless you want a Livewire lifecycle hook (that's why it's `prepareBlocks`).
7. **The JS has zero tests.** Renaming a `DesignPage` method, a `data-*` attribute or `$root` lookups breaks drag and inline editing *silently*. Run `scripts/check-drift.sh`, then click through the canvas in a real panel.
8. **No build step.** Never add `package.json`, a bundler or a CDN. Vendor a prebuilt UMD file under `resources/js/vendor/` if you must, register it in `registerAssets()`, and add it to `AssetRegistrationTest`.
9. **Consumers must render through `<x-page-builder::blocks>`.** A hand-rolled `@foreach` + `<x-dynamic-component :component="$registry->view(...)">` prints section children at the top level and *throws* on every shipped primitive, whose views are namespaced paths (verified, RENDERING.md).
10. **The docs have drifted. Trust the code, and fix the doc in the same commit** (ROADMAP §8):

| Claim | Where | Reality |
|---|---|---|
| `filament:optimize` publishes the assets | docs "Assets" | Only `filament:assets` / `filament:upgrade` do |
| `.fpb-toolbar` is an overridable class | docs "Styling the canvas" | It doesn't exist. Use `.fpb-chrome` / `.fpb-toolbar-actions` |
| `recordModel()` is honoured | ROADMAP 0.3, docs API table | Stored and read by nothing |
| Storage v2 `{"version":2,"blocks":[…]}` + `pages:upgrade-blocks` | ROADMAP §4.1 | Never shipped. The upgrade happens implicitly in `hydrate()` |
| `EmbedBlock`, per-breakpoint visibility, anchor id | ROADMAP §4.2–4.3 (marked ✅) | None exist |
| SortableJS vendored for nesting | ROADMAP §6 | Native DnD. Anime.js is the only vendored library |
| Undo capped at ~50 | ROADMAP 0.6 | `BlockHistory::LIMIT = 30` |
| Pest browser tests for drag/drop/typing | ROADMAP 0.1 (✅) | None exist |
| "The suite is at 105 tests" | ROADMAP status | 113 |
| Shortcuts are ignored while typing | commit `c76513c` | ⌘S / ⌘Z / ⇧⌘Z are intercepted anyway |

---

## Global Conventions

- **Mechanism, not content.** No model, migration, `Page` class or site branding in the package. Consumer plans and specifics stay in the consumer's repo (`71aa1ac` removed DAME's plan from here). Describe features on their own terms, without product or brand comparisons, in docs and comments (`12ecc43`).
- **Comments explain *why*** in full-sentence docblocks, often recounting the bug that forced the design. Spelling is British (`normalise`, `authorisation`, `behaviour`, `colour`). Match both.
- **Block definitions are all-static**, and block views read `$data` defensively (`$data['x'] ?? ''`). Content written under older schemas is still in the DB, and the canvas hands views half-finished data mid-edit.
- **Unknown is normal, never exceptional:** registry lookups return `null`/`[]`, and renderers skip what they don't recognise.
- Namespaces: CSS classes `fpb-*`, attributes `data-fpb-*`, Livewire keys `fpb-block-{id}`, views `page-builder::…`, asset package key `carljanzell/filament-page-builder`, session key `filament-page-builder.history`.
- **Docs travel with behaviour.** `docs/index.html` (hand-written, self-contained) and `README.md` change in the same commit as the code they describe, including their "Known limitations" lists.
- Commits use conventional prefixes (`feat:`, `fix:`, `docs:`, `test:`, `style:`, `chore:`), with a body that narrates the incident (what looked wrong → root cause → the fix). Work lands on `dev`, which auto-promotes to `main`.
