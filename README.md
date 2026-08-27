# Filament Page Builder

A drag-and-drop visual page builder for [Filament](https://filamentphp.com), storing page
content as an ordered array of typed blocks in a single JSON column.

> **Status: scaffold.** The plugin contract, block registry and service provider are in
> place. The canvas itself is not built yet — see [VISUAL_BUILDER_PLAN.md](VISUAL_BUILDER_PLAN.md)
> for the staged plan.

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
- **No bundler.** Filament already bundles Alpine and SortableJS. Requiring consumers to run a
  JS build would make this unusable on hosts without Node — which is precisely what it was
  written for.
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

## Requirements

- PHP 8.3+
- Filament 5.x

## Licence

Proprietary — all rights reserved. The source is public for reference only; see
[LICENSE](LICENSE). It is not open source, and no reuse rights are granted.
