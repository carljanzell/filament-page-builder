<x-filament-panels::page>
    <div
        class="fpb"
        x-data="pageBuilderCanvas()"
        wire:key="fpb-{{ $this->getRecord()->getKey() }}"
    >
        <header class="fpb-chrome">
            <div class="fpb-chrome-start">
                @if ($exitUrl = $this->exitUrl())
                    <a href="{{ $exitUrl }}" class="fpb-back">{{ $this->exitLabel() }}</a>
                @endif

                <h1 class="fpb-chrome-title">{{ $this->getRecordTitle() }}</h1>

                <span class="fpb-status" @class(['fpb-status-dirty' => $this->isDirty])>
                    {{ $this->isDirty ? 'Unsaved changes' : 'All changes saved' }}
                </span>
            </div>

            <div class="fpb-preview-toggle" role="group" aria-label="Preview width">
                <button type="button" class="fpb-preview-btn" :data-active="preview === 'desktop'" x-on:click="preview = 'desktop'" title="Desktop">Desktop</button>
                <button type="button" class="fpb-preview-btn" :data-active="preview === 'tablet'" x-on:click="preview = 'tablet'" title="Tablet">Tablet</button>
                <button type="button" class="fpb-preview-btn" :data-active="preview === 'mobile'" x-on:click="preview = 'mobile'" title="Mobile">Mobile</button>
            </div>

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
        </header>

        {{-- Palette --}}
        <aside class="fpb-panel fpb-palette">
            <div class="fpb-side-tabs" role="tablist">
                <button
                    type="button"
                    role="tab"
                    class="fpb-side-tab"
                    :data-active="sideTab === 'blocks'"
                    x-on:click="sideTab = 'blocks'"
                >Blocks</button>
                <button
                    type="button"
                    role="tab"
                    class="fpb-side-tab"
                    :data-active="sideTab === 'structure'"
                    x-on:click="sideTab = 'structure'"
                >Structure</button>
            </div>

            <div x-show="sideTab === 'blocks'">
                <p class="fpb-panel-hint">Drag onto the page or into a column. Click to insert at the selection.</p>

                @foreach ($this->paletteGroups as $group)
                    <h3 class="fpb-palette-group">{{ $group['label'] }}</h3>
                    <ul class="fpb-palette-list">
                        @foreach ($group['items'] as $item)
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
                @endforeach
            </div>

            <div x-show="sideTab === 'structure'" x-cloak>
                <h2 class="fpb-panel-title">Document</h2>
                <p class="fpb-panel-hint">Click a block to select it. The outline follows the page.</p>

                @if ($this->structure === [])
                    <p class="fpb-panel-hint">This page has no blocks yet.</p>
                @else
                    <ol class="fpb-structure">
                        @foreach ($this->structure as $node)
                            @include('page-builder::structure-node', ['node' => $node, 'depth' => 0])
                        @endforeach
                    </ol>
                @endif
            </div>

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
            <div class="fpb-canvas-frame">
                <div
                    class="fpb-canvas"
                    :data-preview="preview"
                    x-on:dragover.prevent="onDragOver($event)"
                    x-on:drop.prevent="onDrop($event)"
                    x-on:dragleave="onDragLeave($event)"
                >
                    {{-- Application supplied design tokens and block styles, so the canvas
                         renders blocks exactly as the public site does. --}}
                    @if ($stylesView = $this->canvasStylesView())
                        @include($stylesView)
                    @endif
                    @forelse ($this->rootBlocks as $block)
                        <x-page-builder::canvas-block :block="$block" :selected-id="$this->selectedId" />
                    @empty
                        <p class="fpb-empty">This page has no blocks yet. Drag one in from the left, or drop a section to start a layout.</p>
                    @endforelse
                </div>
            </div>
        </main>

        {{-- Inspector --}}
        <aside class="fpb-panel fpb-inspector">
            <h2 class="fpb-panel-title">
                {{ $this->selectedId ? 'Block settings' : 'Nothing selected' }}
            </h2>

            @if (! $this->selectedId)
                <p class="fpb-panel-hint">Click a block on the page to edit it. Use the Style tab for spacing, width and alignment.</p>
            @else
                <div class="fpb-inspector-tabs" role="tablist">
                    <button type="button" class="fpb-side-tab" :data-active="inspectorTab === 'content'" x-on:click="inspectorTab = 'content'">Content</button>
                    <button type="button" class="fpb-side-tab" :data-active="inspectorTab === 'style'" x-on:click="inspectorTab = 'style'">Style</button>
                </div>

                <div x-show="inspectorTab === 'content'">
                    @if ($this->isSelectedBlockEditable())
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
                </div>

                <div x-show="inspectorTab === 'style'" x-cloak>
                    <p class="fpb-panel-hint">Tokens from your theme, not raw CSS.</p>

                    @foreach ($this->styleTokens() as $token => $options)
                        <label class="fpb-style-field">
                            <span>{{ ucfirst($token) }}</span>
                            <select wire:model.live="blockSettings.{{ $token }}">
                                <option value="">Default</option>
                                @foreach ($options as $value => $label)
                                    @if (is_int($value))
                                        <option value="{{ $label }}">{{ $label }}</option>
                                    @else
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
            @endif
        </aside>
    </div>
</x-filament-panels::page>
