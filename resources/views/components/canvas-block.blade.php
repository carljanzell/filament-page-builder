@props(['block', 'selectedId' => null])

@php
    use CarlJanzell\FilamentPageBuilder\PageBuilder;

    $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
@endphp

<div
    class="fpb-block"
    data-id="{{ $block['id'] }}"
    data-parent="{{ $block['parent'] ?? '' }}"
    data-slot="{{ $block['slot'] ?? '' }}"
    data-has-content="{{ $block['hasContent'] ? 'true' : 'false' }}"
    @if ($block['isContainer'] ?? false) data-container="true" @endif
    @if ($selectedId === $block['id']) data-selected="true" @endif
    @unless ($block['isKnown']) data-unknown="true" @endunless
    @foreach ($settings as $token => $value)
        @if (is_string($token) && preg_match('/^[a-z-]+$/', $token) && is_string($value))
            data-fpb-{{ $token }}="{{ $value }}"
        @endif
    @endforeach
    wire:click.stop="selectBlock('{{ $block['id'] }}')"
    wire:key="fpb-block-{{ $block['id'] }}"
>
    <div class="fpb-block-bar">
        <button
            type="button"
            class="fpb-block-handle"
            title="Drag to move"
            draggable="true"
            x-on:dragstart.stop="startMove($event, '{{ $block['id'] }}')"
            x-on:dragend="clearDrag()"
            x-on:click.stop
        >⋮⋮</button>
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
            @php
                PageBuilder::editing($block['id'], $block['type'], $block['data'] ?? []);

                if ($block['isContainer'] ?? false) {
                    PageBuilder::provideSlots($block['slotNames'] ?? [], function (string $name) use ($block, $selectedId): string {
                        return view('page-builder::components.canvas-slot', [
                            'parent' => $block['id'],
                            'slot' => $name,
                            'children' => $block['children'][$name] ?? [],
                            'selectedId' => $selectedId,
                        ])->render();
                    });
                }
            @endphp
            @if (str_contains((string) $block['view'], '::'))
                @include($block['view'], ['data' => $block['data']])
            @else
                <x-dynamic-component :component="$block['view']" :data="$block['data']" />
            @endif
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
