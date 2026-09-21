@props(['data' => []])

@if (filled($data['src'] ?? null))
    <figure class="fpb-image">
        <img src="{{ $data['src'] }}" alt="{{ $data['alt'] ?? '' }}">
        @if (\CarlJanzell\FilamentPageBuilder\PageBuilder::shows('alt', $data['alt'] ?? ''))
            <figcaption @editable('alt')>{{ $data['alt'] ?? '' }}</figcaption>
        @endif
    </figure>
@else
    <div class="fpb-image-placeholder">Choose an image in the sidebar</div>
@endif
