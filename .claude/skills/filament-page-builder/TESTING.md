# Testing, CI and release

The package has its own Testbench harness (added in `c511a63`). Before that it was tested only through its first consuming application, which was no basis for the Stage B/C refactors. The count is **113 tests / 271 assertions** at `74f19ce`. ROADMAP says 105, which is stale.

Related: [CANVAS.md](CANVAS.md) (the behaviour most tests pin), [FRONTEND.md](FRONTEND.md) (everything that is *not* tested).

---

## Harness (`tests/TestCase.php`)

| Piece | Detail / reason |
|---|---|
| Base | `Orchestra\Testbench\TestCase` + `RefreshDatabase`, sqlite `:memory:`, with a `pages` table carrying **two** JSON columns: `blocks` and `layout`. `layout` exists so the blocks-attribute override can be tested on the same table. |
| **Provider order** | `LivewireServiceProvider` is listed **after** the Filament providers. `filament/support` rebinds Livewire's `DataStore` without sharing it, which drops Livewire's shared instance. In real apps, package discovery orders `filament/*` before `livewire/livewire`. Get it wrong and every component loses its error bag mid-render. |
| View paths | `tests/Fixtures/views` is **prepended** to `view.paths`, so fixture blocks' `view()` values (`blocks.heading`) resolve to `tests/Fixtures/views/components/blocks/*.blade.php`. |
| `tests/Pest.php` | Only `uses(TestCase::class)->in(__DIR__)`. |

### Fixtures (`tests/Fixtures/`)

| Fixture | Purpose |
|---|---|
| `TestPanelProvider` | The **default** panel, id `testing`, with `includeLayoutBlocks(false)` and the blocks `heading`, `banner`, `restricted`. Resources: `PageResource`, `LayoutPageResource`. |
| `SecondPanelProvider` | Panel `second` with only `heading`. It proves the registries don't pool. |
| `Page` / `LayoutPage` | `HasBlocks`, casting `blocks` and `layout` to arrays. `LayoutPage` sets `protected string $blocksAttribute = 'layout'` on the same table. |
| `PageResource` | `public static bool $canEdit` toggle, and index/edit/design pages. |
| `LayoutPageResource` | index + design, **no edit page**, which exercises `formEditorUrl() === null`. |
| `HeadingBlock` | `text` inline-editable with a placeholder. |
| `BannerBlock` | `caption` multiline editable, `image` declared as a file field. Its view marks `@editable('image')` **on purpose** (the field isn't declared) to prove markup can't open a field. |
| `RestrictedBlock` | `public static bool $visible = false`, and it renders `{!! $data['html'] !!}`. It stands in for custom HTML: reorderable but not authorable. |

Static toggles (`PageResource::$canEdit`, `RestrictedBlock::$visible`) persist across tests in the process. `DesignPageTest`'s `beforeEach` resets them, so any new file that flips them must reset them too.

---

## The shared-helper trap *(verified)*

`page()`, `block()`, `canvas()` and `ids()` are defined in **`tests/Feature/DesignPageTest.php`**. `HistoryTest`, `InlineEditingTest`, `NestingTest` and `StyleInspectorTest` pull them in with `require_once __DIR__.'/DesignPageTest.php'`.

- The **full suite passes** only because Pest loads `DesignPageTest.php` first (alphabetically), which makes the later `require_once` calls no-ops.
- **Running one of those files alone** (`vendor/bin/pest tests/Feature/NestingTest.php`) registers DesignPageTest's 31 tests *inside that file*, under *that file's* `beforeEach`. Right now that gives **1 failure**: `offers only the blocks the user may author in the palette` sees `section`/`text`, which NestingTest's `beforeEach` registered into the `testing` registry.
- So run the whole suite, or use `--filter="…"`, which works fine. Don't extend the pattern: put new shared helpers in `tests/Pest.php` (or a helpers file it requires).

---

## Conventions in the suite

- Canvas tests use `Livewire::test(DesignPage::class, ['record' => $id])` through the `canvas()` helper, and read state with `->get('blocks.0.data.text')`. Livewire's `->set('blockData.x', …)` exercises the real `updatedBlockData()` commit path.
- Rendering tests call `view('page-builder::components.blocks', [...])->render()` directly. Call `PageBuilder::idle()` first when a test asserts that nothing is in editing mode.
- **Public-render tests must put children in more than `col-0`.** Both of the original tests used only the first column, and that's why the "every later column renders empty" bug (`dc08dbc`) shipped.
- `NestingTest` registers `SectionBlock` and `TextBlock` into the testing panel's registry in `beforeEach`, because that panel runs `includeLayoutBlocks(false)`.

## What is **not** tested

**None of the JavaScript is tested.** ROADMAP 0.1 is marked done and claims "Pest browser tests for drag, drop and inline typing", but there are no browser tests in `tests/`. Drag and drop, drop-target resolution, inline edit commit/revert, keyboard shortcuts, the unsaved-work guards, the morph-skip hook and motion are verified only by hand, in a real panel. The JS↔PHP method contract is guarded at best by `scripts/check-drift.sh`. After touching `page-builder.js`, `canvas-block.blade.php` or the `PageBuilder` attribute output, exercise the canvas in a browser.

---

## CI (`.github/workflows/ci.yaml`)

It runs on pushes to `main`/`dev` and on PRs to `main`, with PHP **8.4**: `composer validate --strict --no-check-lock` → `composer install` → `php -l` over `src` → **`vendor/bin/pint --test`** (the `laravel` preset from `pint.json`) → `vendor/bin/pest --compact`. Style is enforced: `962f8fe` exists only to fix a CI failure on `unary_operator_spaces`, `types_spaces` and `not_operator_with_successor_space`. `composer lint` runs the same check-only command.

`composer.lock` is gitignored, as usual for a library. CI resolves fresh each run, so a new Filament 5.x minor can break CI without any change to this repo. Check `scripts/check-drift.sh` first when that happens.

`.cursor/install.sh` + `environment.json` bootstrap a Cursor Cloud Agent (PHP 8.4 + Composer on top of Cursor's default image, so its browser tooling stays available for demoing the canvas).

## Release flow

- Work lands on **`dev`**. `.github/workflows/auto-merge.yaml` opens (or reuses) a `dev → main` PR and merges it once GitHub reports `mergeable === true`. **It doesn't check CI status itself**, so "a green build promotes it" holds only if `main` has required status checks.
- The known consumer (DAME) pins **`dev-main`**, so every merge to `main` ships on the consumer's next `composer update`. Tag `v0.1.0` exists (currently the same commit as `main`). Tag releases if a change needs to be pinned or rolled back.
- Consumers must re-run `php artisan filament:assets` after any JS/CSS change, and a `dev-main` version string doesn't bust browser caches. See [FRONTEND.md](FRONTEND.md).
