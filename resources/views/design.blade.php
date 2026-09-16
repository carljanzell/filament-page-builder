@use('CarlJanzell\FilamentPageBuilder\PageBuilder')

<x-filament-panels::page>
    <div
        class="fpb"
        x-data="pageBuilderCanvas()"
        wire:key="fpb-{{ $this->getRecord()->getKey() }}"
    >
        {{-- Keyboard shortcuts and the unsaved-changes guard are bound to the document,
             so they work wherever the focus happens to be on the page. --}}
        {{-- Palette --}}
        <aside class="fpb-panel fpb-palette">
            <h2 class="fpb-panel-title">Blocks</h2>
            <p class="fpb-panel-hint">Drag onto the page, or click to append.</p>

            <ul class="fpb-palette-list">
                @foreach ($this->palette as $item)
                    <li>
                        <button
                            type="button"
                            class="fpb-palette-item"
                            draggable="true"
                            data-type="{{ $item['type'] }}"
                            x-on:dragstart="startInsert($event, '{{ $item['type'] }}')"
                            x-on:dragend="clearDrag()"
                            wire:click="insertBlock('{{ $item['type'] }}')"
                        >
                            @if ($item['icon'])
                                <x-filament::icon :icon="$item['icon']" class="fpb-palette-icon" />
                            @endif
                            <span>{{ $item['label'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>

            <dl class="fpb-shortcuts">
                <dt>&#8984;Z</dt><dd>Undo</dd>
                <dt>&#8984;&#8679;Z</dt><dd>Redo</dd>
                <dt>&#8984;D</dt><dd>Duplicate</dd>
                <dt>&#8984;S</dt><dd>Save</dd>
                <dt>&#8679;&uarr; &#8679;&darr;</dt><dd>Move block</dd>
                <dt>&uarr; &darr;</dt><dd>Select</dd>
                <dt>&#9003;</dt><dd>Delete</dd>
                <dt>Esc</dt><dd>Deselect</dd>
            </dl>
        </aside>

        {{-- Canvas --}}
        <main class="fpb-canvas-wrap">
            <div class="fpb-toolbar">
                <span class="fpb-status" @class(['fpb-status-dirty' => $this->isDirty])>
                    {{ $this->isDirty ? 'Unsaved changes' : 'All changes saved' }}
                </span>

                <div class="fpb-toolbar-actions">
                    <x-filament::icon-button
                        icon="heroicon-m-arrow-uturn-left"
                        label="Undo"
                        color="gray"
                        size="sm"
                        wire:click="undo"
                        :disabled="! $this->canUndo"
                    />

                    <x-filament::icon-button
                        icon="heroicon-m-arrow-uturn-right"
                        label="Redo"
                        color="gray"
                        size="sm"
                        wire:click="redo"
                        :disabled="! $this->canRedo"
                    />

                    @if ($formEditorUrl = $this->formEditorUrl())
                        <x-filament::button
                            tag="a"
                            href="{{ $formEditorUrl }}"
                            color="gray"
                            size="sm"
                        >
                            Form editor
                        </x-filament::button>
                    @endif

                    <x-filament::button
                        wire:click="save"
                        wire:loading.attr="disabled"
                        size="sm"
                    >
                        Save layout
                    </x-filament::button>
                </div>
            </div>

            <div
                class="fpb-canvas"
                x-on:dragover.prevent="onDragOver($event)"
                x-on:drop.prevent="onDrop($event)"
                x-on:dragleave="onDragLeave($event)"
            >
                {{-- Application supplied design tokens and block styles, so the canvas
                     renders blocks exactly as the public site does. --}}
                @if ($stylesView = $this->canvasStylesView())
                    @include($stylesView)
                @endif
                @forelse ($this->renderableBlocks as $index => $block)
                    <div
                        class="fpb-block"
                        data-id="{{ $block['id'] }}"
                        data-index="{{ $index }}"
                        data-has-content="{{ $block['hasContent'] ? 'true' : 'false' }}"
                        draggable="true"
                        @if ($this->selectedId === $block['id']) data-selected="true" @endif
                        @unless ($block['isKnown']) data-unknown="true" @endunless
                        x-on:dragstart="startMove($event, '{{ $block['id'] }}')"
                        x-on:dragend="clearDrag()"
                        wire:click="selectBlock('{{ $block['id'] }}')"
                        wire:key="fpb-block-{{ $block['id'] }}"
                    >
                        <div class="fpb-block-bar">
                            <span class="fpb-block-label">{{ $block['label'] }}</span>
                            <span class="fpb-block-tools">
                                <button type="button" title="Duplicate"
                                        wire:click.stop="duplicateBlock('{{ $block['id'] }}')">⧉</button>
                                <button type="button" title="Delete"
                                        x-on:click.stop="remove('{{ $block['id'] }}', {{ $block['hasContent'] ? 'true' : 'false' }})">✕</button>
                            </span>
                        </div>

                        <div class="fpb-block-body">
                            @if ($block['isKnown'] && $block['view'])
                                {{-- While this is set, `@editable` inside the block's own
                                     markup expands to editing attributes. Off the canvas
                                     it expands to nothing, so the public page ships the
                                     same markup without them. --}}
                                @php(PageBuilder::editing($block['id'], $block['type']))
                                <x-dynamic-component :component="$block['view']" :data="$block['data']" />
                                @php(PageBuilder::idle())
                            @elseif (! $block['isKnown'])
                                <p class="fpb-block-retired">
                                    This page holds a <code>{{ $block['type'] }}</code> block, which this
                                    site no longer offers. Its content is kept and saved untouched; it
                                    cannot be shown or edited here.
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="fpb-empty">This page has no blocks yet. Drag one in from the left.</p>
                @endforelse
            </div>
        </main>

        {{-- Inspector --}}
        <aside class="fpb-panel fpb-inspector">
            <h2 class="fpb-panel-title">
                {{ $this->selectedId ? 'Block settings' : 'Nothing selected' }}
            </h2>

            @if (! $this->selectedId)
                <p class="fpb-panel-hint">Click a block on the page to edit it.</p>
            @elseif ($this->isSelectedBlockEditable())
                {{ $this->form }}
            @elseif (! $this->isSelectedBlockKnown())
                <p class="fpb-panel-hint">
                    This block's type is no longer registered, so there are no fields to show.
                    Its stored content is preserved. You can still move or remove it.
                </p>
            @else
                <p class="fpb-panel-hint">
                    You do not have permission to edit this block's content. You can still
                    move or remove it.
                </p>
            @endif
        </aside>
    </div>
</x-filament-panels::page>
