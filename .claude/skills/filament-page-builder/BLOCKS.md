# Blocks: contracts, registry, shipped primitives, editables

The package owns the mechanism and the application owns the block types. A block is a class of **static** methods (so the registry can describe a type without instantiating it) plus a Blade view that renders **both** the public page and the canvas.

Related: [RENDERING.md](RENDERING.md) (how the view is invoked, and slots), [CANVAS.md](CANVAS.md) (where `isVisible()` is enforced).

---

## `Contracts\PageBlock`

| Method | Rule |
|---|---|
| `type(): string` | Stored in the JSON. **Permanent**: renaming it turns every existing instance into a "retired" block. |
| `label()`, `icon(): ?string` | Palette, block bar, outline. `icon` is a Heroicon name. |
| `schema(): array` | Filament components, used by the canvas inspector **and** the consumer's `Builder` form editor. |
| `view(): string` | See [RENDERING.md](RENDERING.md): a string containing `::` is `@include`d, anything else is rendered as `<x-dynamic-component>`. |
| `fileFields(): array` | Field names that hold uploads. **Declared, not inferred**: an upload is an array in form state and a path at rest, and a map of strings is indistinguishable from a repeater item. The names match **at any depth** inside `data` (a repeater's `image` works if `'image'` is listed, and an unrelated nested key with the same name gets coerced too). |
| `isVisible(): bool` | "May the current user **author** this type?" It's a callback rather than a role check, so the package makes no assumption about the permission system. |

**Optional statics, detected with `method_exists`** and not declared on the interface, so a typo is silently ignored:

| Static | Used by | Default |
|---|---|---|
| `defaults(): array` | `insertBlock()` seeds `data`, and `blockHasContent()` treats values equal to the defaults as "not content" so an untouched drop deletes without a confirmation | `[]` |
| `category(): string` | Palette group. `layout` / `content` / `design` / `blocks` have labels, anything else gets `ucfirst()` | `'blocks'` |

**`Contracts\Container`** (implemented by `SectionBlock`) requires `slots(array $data): array` (the slot names *this instance* exposes, derived from its data) and `defaults()`. The canvas drop targets, the outline and the public renderer all ask `slots()`, so changing a section's column count is a data change and not a new type.

---

## Registry: one per panel

`BlockRegistry` is the single source of truth, used by the form, the canvas and the public renderer alike. `BlockRegistries` (a container singleton) holds **one registry per panel id** (incident `b08837e`: a shared singleton pooled every panel's blocks into every palette).

| Access | Resolves to |
|---|---|
| `app(BlockRegistry::class)` | A **bind** (not a singleton) → `BlockRegistries::current()` → `Filament::getCurrentOrDefaultPanel()`. Code that injects it never needs to know panels exist. |
| `FilamentPageBuilderPlugin::get()->getRegistry()` | The registry of the panel that plugin instance was registered on. |
| `BlockRegistries::for($id)` called **cold** | **Empty.** Panels (and so `plugin->register()` → `registry->register()`) are built lazily by a `resolving(PanelRegistry)` hook. Go through `app(BlockRegistry::class)`, or resolve the panel first (`PanelRegistryTest` calls `Filament::getPanel()` for exactly this reason). |

**The public site has no current panel, so it uses the *default* panel's registry.** Register the plugin, with every block type the public pages use, on the panel marked `->default()`. Otherwise `<x-page-builder::blocks>` finds no definitions and silently renders nothing.

| Registry method | Meaning |
|---|---|
| `has($type)` / `find()` | Registered at all. **Not** an authorisation check. |
| `isVisible($type)` | Registered **and** `$block::isVisible()`. Every authoring path must ask this (see CANVAS.md). |
| `visible()` | The palette source, in registration order. |
| `view()`, `fileFields()`, `slots()`, `defaults()`, `category()`, `isContainer()` | Return `null` / `[]` / `false` / `'blocks'` for an unknown type. Unknown is expected, never an exception, because content outlives schema. |

`register()` keys by `type()`, so **a later class with the same type replaces the earlier one**. `FilamentPageBuilderPlugin::register()` registers `[...layoutBlockClasses(), ...$this->blocks]`, so an app class overrides a shipped primitive. It keeps the primitive's position in the palette, because PHP key reassignment preserves order. A non-`PageBlock` class throws `InvalidArgumentException` when the panel is built. `includeLayoutBlocks(false)` drops the primitives entirely (the test panels do this).

`FilamentPageBuilderPlugin::recordModel()` is **accepted, stored, and read by nothing**. ROADMAP 0.3 and the docs present it as honoured, but only `blocksAttribute()` actually is. Don't build on it without wiring it up first.

---

## Shipped primitives (`src/Blocks/`, views in `resources/views/components/`)

| type | Class | category | Editables | Data / notes |
|---|---|---|---|---|
| `section` | `SectionBlock` (Container) | layout | none | `defaults()` `columns: 2, ratio: '1-1'`. `slots()` = `col-0 … col-{n-1}`, with `columns` clamped to **1–4** (the view clamps too). **`ratio` doesn't follow `columns`**: picking 3 columns while `ratio` is still `'1-1'` gives a 2-track grid, and the third column wraps to a new row. The view derives a ratio only when `ratio` is absent. |
| `text` | `TextBlock` | content | `body`: text, **multiline**, placeholder | A `<p class="fpb-text">`. It needs `white-space: pre-line` for multiline to survive, see FRONTEND.md. |
| `image` | `ImageBlock` | content | `alt`: text | `FileUpload('src')->disk('public')->directory('pages')`. **Bug:** the view prints `src="{{ $data['src'] }}"` raw, so a stored `pages/x.jpg` becomes a relative URL and breaks once saved. It only works mid-upload, when the normaliser supplies Livewire's absolute temporary URL. The docs' HeroBlock example shows the correct `Storage::disk('public')->url(...)`. |
| `button` | `ButtonBlock` | content | `label`: text | `url` is a `TextInput` with only `maxLength`. A `javascript:` URL passes and lands in `href`. Validate it if untrusted editors can author buttons (it's visible to everyone by default). |
| `spacer` | `SpacerBlock` | design | none | `defaults()` `height: 'md'` → `data-fpb-height`. |
| `divider` | `DividerBlock` | design | none | An empty schema. |

All six return `isVisible() === true`. They are the reference implementations: `SectionBlock` for a container, `TextBlock` / `ButtonBlock` for inline-editable leaves.

---

## Editing on the page: `InlineEditable` + `Editable` + `@editable`

It's a separate interface from `PageBlock`, so opting in is explicit and older blocks keep working untouched.

```php
public static function editables(): array            // InlineEditable
{
    return ['heading' => Editable::text()->placeholder('Write a heading')];
}
```
```blade
@if (PageBuilder::shows('heading', $data['heading'] ?? ''))
    <h1 @editable('heading')>{{ $data['heading'] ?? '' }}</h1>
@endif
```

- **The declaration is the authority, not the markup.** `@editable('x')` for an undeclared `x` emits nothing (the `BannerBlock` fixture marks `image` on purpose to prove it), and `setBlockField()` refuses undeclared fields on its own.
- Non-`Editable` values in `editables()` are silently filtered out.
- **`PageBuilder::shows($field, $value)`, not `filled()`.** It's true for a filled value, *or* for an empty field that's editable in the current canvas render, so an unfilled block still offers a clickable placeholder. Off the canvas it behaves like `filled()`.
- Only **top-level string fields** can be edited in place: `setBlockField()` writes `data[$field]` literally. A dotted path like `items.0.title` would create a literal key, and repeaters stay inspector-only.

| Kind | On the canvas | `accepts()` |
|---|---|---|
| `Editable::text()` | `contenteditable="plaintext-only"`. Enter commits unless `->multiline()` | `is_string` |
| `Editable::richText()` | Emits `data-fpb-field/kind` but **no `contenteditable`**, so it's not editable in place yet (ROADMAP Stage B: mount Filament's TipTap bundle) | `is_string`, **unsanitised** |

**Treat `richText` as a security boundary until in-place rich text lands.** `setBlockField()` accepts *any* string for it, which bypasses the HTML allowlist that TipTap enforces through the inspector. If the block renders the field with `{!! !!}`, anyone who passes the block's `isVisible()` can inject arbitrary markup with a crafted call. No shipped block declares `richText`.

---

## `BlockStateNormaliser` (`src/Support/`)

It reconciles **editing** state with **stored** state. The canvas applies it in `decorate()`, the public renderer applies it in `public-block`, and consumers call `normalise()` for a live preview beside the form editor.

| Field | While editing | Stored | How it's resolved |
|---|---|---|---|
| RichEditor | A TipTap doc array | An HTML string | **By shape**: any array with `type === 'doc'`, at any depth, goes through `RichContentRenderer::make()->toHtml()`. App data that happens to carry `type: 'doc'` gets converted too. |
| FileUpload | `[uuid => TemporaryUploadedFile]` or a wrapped path | A path string | **By declared name** (`fileFields`). It resolves to a string path, or the temp URL for an in-progress upload, or `null` for empty. |

`normalise()` (the whole-array form) keeps only entries with a `type` and sets `id => null` when it's missing. It **doesn't mint ids**, unlike `BlockTree::hydrate()`.
