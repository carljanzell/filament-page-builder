# Filament Page Builder

A drag-and-drop visual page builder for [Filament](https://filamentphp.com), storing page
content as an ordered array of typed blocks in a single JSON column.

**📖 [Documentation](https://carljanzell.github.io/filament-page-builder/)**

> **Status: the canvas is a nested layout editor.** Palette with layout
> primitives, drag into columns, a document outline, token style inspector, inline text
> editing, undo/redo and keyboard shortcuts — all persisting to the same JSON the form
> editor uses. Still to come: rich text in place, draft/publish and reusable sections. See
> [ROADMAP.md](ROADMAP.md).

## Why

Filament's `Builder` field is an excellent structured editor, but it is a *form*: a vertical
stack of collapsible panels. You cannot drop a block where you want it on the page, or see
the layout you are actually building. This package adds a canvas alongside it — both editing
surfaces read and write the same JSON, so neither owns the content.

## Design principles

- **The package owns the mechanism, the application owns the content.** Blocks are classes in
  your app, free to query your models and render your markup. The package never ships a `Page`
  model or a migration.
- **One registry, one set of components.** The form, the canvas and the public renderer all
  resolve through the same registry, so a block cannot mean different things in each.
- **Consumers never run a bundler.** The canvas assets are shipped ready to serve and
  registered under the package's own namespace, so they never touch your application's
  build. This was written for hosts with no Node installed.
- **Unknown block types are skipped, not fatal.** Content outlives schema changes.

## Installation

```bash
composer require carljanzell/filament-page-builder
```

Register the plugin on a panel:

```php
use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;

$panel->plugin(
    FilamentPageBuilderPlugin::make()
        ->blocks([
            HeroBlock::class,
            RichTextBlock::class,
        ])
        // Section, text, image, button, spacer and divider ship with the package.
        // Register a class with the same type() to replace one.
        ->recordModel(\App\Models\Page::class)
        ->blocksAttribute('blocks'),
);
```

Apply the trait to the model that stores blocks:

```php
use CarlJanzell\FilamentPageBuilder\Concerns\HasBlocks;

class Page extends Model
{
    use HasBlocks;
}
```

## The canvas

Extend the packaged page and bind it to your resource:

```php
use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;

class DesignPage extends BaseDesignPage
{
    protected static string $resource = PageResource::class;
}
```

Register it as a resource page and the canvas is available at
`/admin/pages/{record}/design`:

```php
public static function getPages(): array
{
    return [
        // …
        'design' => DesignPage::route('/{record}/design'),
    ];
}
```

Blocks are mutated in memory and written on an explicit save, so a drag never waits on a
database round trip. Drop a **Section** to get columns; drag text, images and your own
blocks into a column. Click a block and open the **Style** tab for
padding, width, background and alignment — tokens, not raw CSS.

### Making the canvas match your site

The package styles the builder chrome but knows nothing about how you style your blocks.
Point it at a view that supplies your design tokens and block stylesheet:

```php
FilamentPageBuilderPlugin::make()
    ->canvasStylesView('filament.pages.canvas-styles')
```

## Editing on the page

A block can open its own text fields for editing directly on the canvas. Declare which
fields, then mark the matching element in your own markup:

```php
use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use CarlJanzell\FilamentPageBuilder\Editable;

class HeroBlock implements PageBlock, InlineEditable
{
    public static function editables(): array
    {
        return [
            'heading' => Editable::text()->placeholder('Write a heading'),
            'subheading' => Editable::text()->multiline(),
        ];
    }
}
```

```blade
<h1 @editable('heading')>{{ $data['heading'] ?? '' }}</h1>
```

`@editable` expands to editing attributes while the canvas is rendering and to nothing
anywhere else, so the public page ships the same markup without them — one component,
two contexts.

The declaration is the authority, not the markup: `@editable` on a field the block never
listed emits nothing, and the canvas independently refuses to write an undeclared field, a
value of the wrong kind, or a block the current user may not author.

## Defining a block

```php
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use Filament\Forms\Components\TextInput;

class HeroBlock implements PageBlock
{
    public static function type(): string { return 'hero'; }
    public static function label(): string { return 'Hero'; }
    public static function icon(): ?string { return 'heroicon-o-photo'; }
    public static function view(): string { return 'blocks.hero'; }
    public static function fileFields(): array { return ['image']; }
    public static function isVisible(): bool { return true; }

    public static function schema(): array
    {
        return [
            TextInput::make('heading')->required(),
        ];
    }
}
```

`fileFields()` is explicit rather than inferred: an upload is an array in form state but a
plain path once stored, and a map of strings is indistinguishable from a repeater item.

## Rendering publicly

A naive `@foreach` of the stored array will also print the children of a section as
top-level blocks. Use the shipped renderer, which walks the tree:

```blade
<x-page-builder::blocks :blocks="$page->blocks" />
```

## Tests

```bash
composer install
vendor/bin/pest
```

The suite boots a real Filament panel under Testbench, with its own resource, canvas page
and block fixtures.

## Requirements

- PHP 8.3+
- Filament 5.x

## Licence

Proprietary — all rights reserved. The source is public for reference only; see
[LICENSE](LICENSE). It is not open source, and no reuse rights are granted.
