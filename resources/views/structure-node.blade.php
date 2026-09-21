<li class="fpb-structure-item" style="--fpb-depth: {{ $depth }}">
    <button
        type="button"
        class="fpb-structure-btn"
        @if ($this->selectedId === $node['id']) data-selected="true" @endif
        wire:click="selectBlock('{{ $node['id'] }}')"
    >
        <span class="fpb-structure-label">{{ $node['label'] }}</span>
    </button>

    @if ($node['children'] !== [])
        <ol class="fpb-structure">
            @foreach ($node['children'] as $child)
                @include('page-builder::structure-node', ['node' => $child, 'depth' => $depth + 1])
            @endforeach
        </ol>
    @endif
</li>
