@props(['block', 'blocks' => []])

@php
    use CarlJanzell\FilamentPageBuilder\BlockRegistry;
    use CarlJanzell\FilamentPageBuilder\PageBuilder;
    use CarlJanzell\FilamentPageBuilder\Support\BlockStateNormaliser;
    use CarlJanzell\FilamentPageBuilder\Support\BlockTree;

    $registry = app(BlockRegistry::class);
    $definition = $registry->find($block['type'] ?? null);
    $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
    $data = app(BlockStateNormaliser::class)->normaliseData(
        $block['data'] ?? [],
        $registry->fileFields($block['type'] ?? null),
    );
    $slotNames = $registry->slots($block['type'] ?? null, $block['data'] ?? []);
@endphp

@if ($definition)
    <div
        class="fpb-el"
        @foreach ($settings as $token => $value)
            @if (is_string($token) && preg_match('/^[a-z-]+$/', $token) && is_string($value))
                data-fpb-{{ $token }}="{{ $value }}"
            @endif
        @endforeach
    >
        @php
            // Every block pushes a context, because every block pops one below. Pushing
            // only for containers let a leaf child's idle() pop its parent's snapshot,
            // which wiped the slot renderer and rendered every later column empty.
            PageBuilder::rendering($block['type'], $data);

            if ($slotNames !== []) {
                PageBuilder::provideSlots($slotNames, function (string $name) use ($block, $blocks): string {
                    $html = '';

                    foreach (BlockTree::childrenOf($blocks, $block['id'], $name) as $child) {
                        $html .= view('page-builder::components.public-block', [
                            'block' => $child,
                            'blocks' => $blocks,
                        ])->render();
                    }

                    return $html;
                });
            }
        @endphp
        @if (str_contains($definition::view(), '::'))
            @include($definition::view(), ['data' => $data])
        @else
            <x-dynamic-component :component="$definition::view()" :data="$data" />
        @endif
        @php(PageBuilder::idle())
    </div>
@endif
