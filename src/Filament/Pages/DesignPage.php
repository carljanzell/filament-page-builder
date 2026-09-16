<?php

namespace CarlJanzell\FilamentPageBuilder\Filament\Pages;

use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\FilamentPageBuilderPlugin;
use CarlJanzell\FilamentPageBuilder\PageBuilder;
use CarlJanzell\FilamentPageBuilder\Support\BlockHistory;
use CarlJanzell\FilamentPageBuilder\Support\BlockStateNormaliser;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The drag-and-drop canvas.
 *
 * Subclass this in the consuming application to bind it to a resource:
 *
 *     class DesignPage extends BaseDesignPage
 *     {
 *         protected static string $resource = PageResource::class;
 *     }
 *
 * Blocks are mutated in memory and persisted on an explicit save. Making every drag a
 * database write would put a round trip in the middle of a drag gesture.
 */
abstract class DesignPage extends Page
{
    use InteractsWithRecord;

    protected string $view = 'page-builder::design';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $blocks = [];

    public ?string $selectedId = null;

    /**
     * Form state for the selected block only.
     *
     * @var array<string, mixed>
     */
    public array $blockData = [];

    public bool $isDirty = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->authorizeAccess();

        $this->blocks = $this->prepareBlocks($this->record->{$this->blocksAttribute()} ?? []);

        $this->history()->clear();
    }

    public function getTitle(): string
    {
        return 'Design: '.$this->getRecordTitle();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);
    }

    /**
     * Which attribute on the record holds the blocks.
     *
     * A record that uses HasBlocks answers for itself, which is what lets one panel host
     * two models that name the column differently. Anything else falls back to the
     * panel's configuration.
     */
    public function blocksAttribute(): string
    {
        $record = $this->getRecord();

        if (method_exists($record, 'blocksAttribute')) {
            return $record->blocksAttribute();
        }

        return FilamentPageBuilderPlugin::get()->getBlocksAttribute();
    }

    public function canvasStylesView(): ?string
    {
        return FilamentPageBuilderPlugin::get()->getCanvasStylesView();
    }

    /**
     * The resource's form editor, when it has one.
     *
     * A resource is not obliged to expose an edit page — the canvas may be the only
     * editing surface — so the toolbar link is conditional rather than assumed.
     */
    public function formEditorUrl(): ?string
    {
        $resource = static::getResource();

        if (! $resource::hasPage('edit')) {
            return null;
        }

        return $resource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    public function registry(): BlockRegistry
    {
        return app(BlockRegistry::class);
    }

    public function normaliser(): BlockStateNormaliser
    {
        return app(BlockStateNormaliser::class);
    }

    public function history(): BlockHistory
    {
        return new BlockHistory(session()->driver(), $this->getId());
    }

    /**
     * Record the state a mutation is about to replace.
     *
     * Called by each mutation immediately before it changes anything, and only once it
     * knows it will: recording a step that turns out to be a no-op would make the first
     * undo appear to do nothing.
     */
    protected function remember(): void
    {
        $this->history()->push($this->blocks);
    }

    /* ── Reading ───────────────────────────────────────── */

    /**
     * Blocks with ids guaranteed and their own keys left intact.
     *
     * Types the registry does not know are kept rather than dropped. The canvas cannot
     * render or edit them, but it loads and saves the whole array, so pruning here would
     * mean opening a page holding a retired block type and pressing Save destroyed that
     * content. Skipping an unknown type at render time is correct; doing it at persist
     * time is data loss.
     *
     * Anything the application has attached to a block beyond id/type/data is carried
     * through untouched for the same reason.
     *
     * Deliberately not named hydrateBlocks: Livewire treats hydrate{Property} as a
     * lifecycle hook and would try to call it on every request.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function prepareBlocks(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        return array_values(array_map(
            fn (array $block): array => [
                ...$block,
                'id' => $block['id'] ?? (string) Str::uuid(),
                'type' => $block['type'],
                'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
            ],
            array_filter(
                $blocks,
                fn (mixed $block): bool => is_array($block) && is_string($block['type'] ?? null),
            ),
        ));
    }

    /**
     * Blocks ready to render on the canvas, with editing state reconciled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRenderableBlocksProperty(): array
    {
        return array_map(function (array $block): array {
            $definition = $this->registry()->find($block['type']);

            return [
                ...$block,
                'data' => $this->normaliser()->normaliseData(
                    $block['data'] ?? [],
                    $this->registry()->fileFields($block['type']),
                ),
                'view' => $definition === null ? null : $definition::view(),
                'label' => $definition === null ? $block['type'] : $definition::label(),
                'isKnown' => $definition !== null,
                'hasContent' => $this->hasContent($block['data'] ?? []),
            ];
        }, $this->blocks);
    }

    /**
     * Whether a block holds anything a user would mind losing.
     *
     * Drives the delete confirmation: prompting before removing a block the editor has
     * only just dropped in and not yet filled would train them to dismiss the prompt.
     */
    protected function hasContent(mixed $data): bool
    {
        if (! is_array($data)) {
            return filled($data);
        }

        foreach ($data as $value) {
            if ($this->hasContent($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{type: string, label: string, icon: ?string}>
     */
    public function getPaletteProperty(): array
    {
        return array_values(array_map(
            fn (string $block): array => [
                'type' => $block::type(),
                'label' => $block::label(),
                'icon' => $block::icon(),
            ],
            $this->registry()->visible(),
        ));
    }

    /* ── Mutations ─────────────────────────────────────── */

    public function moveBlock(string $id, int $to): void
    {
        $from = $this->indexOf($id);

        if ($from === null) {
            return;
        }

        $this->remember();

        $block = $this->blocks[$from];
        array_splice($this->blocks, $from, 1);

        // Removing the block first shifts everything after it down by one.
        $to = max(0, min($to > $from ? $to - 1 : $to, count($this->blocks)));

        array_splice($this->blocks, $to, 0, [$block]);

        $this->isDirty = true;
    }

    public function insertBlock(string $type, ?int $at = null): void
    {
        if (! $this->registry()->isVisible($type)) {
            return;
        }

        $this->remember();

        $block = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => [],
        ];

        $at = $at === null ? count($this->blocks) : max(0, min($at, count($this->blocks)));

        array_splice($this->blocks, $at, 0, [$block]);

        $this->isDirty = true;

        $this->selectBlock($block['id']);
    }

    public function duplicateBlock(string $id): void
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            return;
        }

        if (! $this->registry()->isVisible($this->blocks[$index]['type'])) {
            return;
        }

        $this->remember();

        $copy = $this->blocks[$index];
        $copy['id'] = (string) Str::uuid();

        array_splice($this->blocks, $index + 1, 0, [$copy]);

        $this->isDirty = true;
    }

    public function removeBlock(string $id): void
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            return;
        }

        $this->remember();

        array_splice($this->blocks, $index, 1);

        if ($this->selectedId === $id) {
            $this->selectBlock(null);
        }

        $this->isDirty = true;
    }

    /* ── Selection and the inspector ───────────────────── */

    public function selectBlock(?string $id): void
    {
        $this->commitSelectedBlock();

        $this->selectedId = $id;

        $this->syncInspector();
    }

    /**
     * Point the inspector at whatever the selected block now holds.
     *
     * Deliberately does not commit first. Undo replaces the block array while the
     * inspector still holds the state that was just undone, and committing it would
     * write that state straight back.
     */
    protected function syncInspector(): void
    {
        $index = $this->selectedId === null ? null : $this->indexOf($this->selectedId);

        if ($index === null) {
            $this->selectedId = null;
        }

        $this->blockData = $index === null ? [] : ($this->blocks[$index]['data'] ?? []);

        // The schema is cached per request and built from $selectedId. Selecting a
        // different block mid-request leaves that cached schema pointing at the previous
        // block — or at no block at all — so it is dropped and rebuilt before the new
        // state is filled in. Without this the inspector hydrates an empty editor and
        // committing it would wipe the block's content.
        $this->cacheSchema('form', null);

        $this->form->fill($this->blockData);
    }

    /**
     * Copy the inspector's state back onto the selected block.
     *
     * Uses getState() rather than raw state, because the two are different shapes: a
     * RichEditor holds a TipTap document while editing but stores HTML, and an upload
     * holds an array while storing a path. getState() also runs the dehydration hooks
     * that move an uploaded file out of temporary storage.
     *
     * getState() validates, and a block being filled in is often incomplete, so an
     * invalid block falls back to converting the raw state by hand. Declared file fields
     * keep their stored value in that path: an in-progress upload has no permanent path
     * yet, and writing its temporary URL would leave a dead link in the database.
     */
    public function commitSelectedBlock(): void
    {
        if ($this->selectedId === null) {
            return;
        }

        $index = $this->indexOf($this->selectedId);

        if ($index === null) {
            return;
        }

        // The inspector renders no fields for a block the user may not author, so the
        // form state is empty. Committing that would erase the block's content.
        if (! $this->registry()->isVisible($this->blocks[$index]['type'])) {
            return;
        }

        $stored = $this->blocks[$index]['data'] ?? [];

        try {
            $data = $this->form->getState();
        } catch (ValidationException) {
            $data = $this->normaliser()->normaliseData($this->blockData);

            foreach ($this->registry()->fileFields($this->blocks[$index]['type']) as $field) {
                $data[$field] = $stored[$field] ?? null;
            }
        }

        if ($data !== $stored) {
            $this->remember();

            $this->blocks[$index]['data'] = $data;
            $this->isDirty = true;
        }
    }

    /**
     * Write one field of one block, from an edit made directly on the page.
     *
     * Everything here is checked rather than trusted. The canvas is a public Livewire
     * surface: the field has to be one the block itself declared editable, and the value
     * has to suit that kind. Marking an element up with `@editable` is not enough — the
     * block's own declaration is the authority, so no amount of markup can open a field
     * the block never offered.
     */
    public function setBlockField(string $id, string $field, mixed $value): void
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            return;
        }

        $type = $this->blocks[$index]['type'];

        if (! $this->registry()->isVisible($type)) {
            return;
        }

        $editable = PageBuilder::editablesFor($type)[$field] ?? null;

        if ($editable === null || ! $editable->accepts($value)) {
            return;
        }

        if (($this->blocks[$index]['data'][$field] ?? null) === $value) {
            return;
        }

        $this->remember();

        $this->blocks[$index]['data'][$field] = $value;
        $this->isDirty = true;

        // The inspector is a second view of the same field and would otherwise keep
        // showing what the page said before the edit.
        if ($this->selectedId === $id) {
            $this->blockData[$field] = $value;
            $this->cacheSchema('form', null);
            $this->form->fill($this->blockData);
        }
    }

    public function updatedBlockData(): void
    {
        $this->commitSelectedBlock();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(fn (): array => $this->selectedBlockSchema())
            ->statePath('blockData');
    }

    /**
     * Fields for whichever block is selected, or none.
     *
     * @return array<int, mixed>
     */
    protected function selectedBlockSchema(): array
    {
        if (! $this->isSelectedBlockEditable()) {
            return [];
        }

        return $this->registry()->find($this->selectedBlockType())::schema();
    }

    /**
     * Whether the selected block is one the current user is allowed to author.
     *
     * A block can be present on a page and still be off limits to whoever opened it:
     * custom markup is the usual case. The block stays visible on the canvas and can be
     * moved or deleted, but its fields are withheld.
     */
    public function isSelectedBlockEditable(): bool
    {
        return $this->registry()->isVisible($this->selectedBlockType());
    }

    /**
     * Whether the selected block is a type this application still registers.
     *
     * Distinct from editability: a retired type cannot be edited by anyone, and telling
     * the editor they lack permission for it would be a lie.
     */
    public function isSelectedBlockKnown(): bool
    {
        return $this->selectedId === null || $this->registry()->has($this->selectedBlockType());
    }

    protected function selectedBlockType(): ?string
    {
        $index = $this->selectedId === null ? null : $this->indexOf($this->selectedId);

        return $index === null ? null : $this->blocks[$index]['type'];
    }

    /* ── History ───────────────────────────────────────── */

    public function undo(): void
    {
        $this->travel($this->history()->undo($this->blocks));
    }

    public function redo(): void
    {
        $this->travel($this->history()->redo($this->blocks));
    }

    public function getCanUndoProperty(): bool
    {
        return $this->history()->canUndo();
    }

    public function getCanRedoProperty(): bool
    {
        return $this->history()->canRedo();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $blocks
     */
    protected function travel(?array $blocks): void
    {
        if ($blocks === null) {
            return;
        }

        $this->blocks = $blocks;
        $this->isDirty = true;

        // The selected block may not exist in the state we travelled to.
        $this->syncInspector();
    }

    /* ── Persistence ───────────────────────────────────── */

    public function save(): void
    {
        $this->commitSelectedBlock();

        /** @var Model $record */
        $record = $this->getRecord();

        $record->{$this->blocksAttribute()} = $this->blocks;
        $record->save();

        $this->isDirty = false;

        Notification::make()
            ->title('Layout saved')
            ->success()
            ->send();
    }

    protected function indexOf(string $id): ?int
    {
        foreach ($this->blocks as $index => $block) {
            if (($block['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }
}
