@props(['data' => []])

@php
    use CarlJanzell\FilamentPageBuilder\PageBuilder;

    $columns = max(1, min(4, (int) ($data['columns'] ?? 2)));
    $ratio = $data['ratio'] ?? match ($columns) {
        1 => '1',
        3 => '1-1-1',
        4 => '1-1-1-1',
        default => '1-1',
    };
@endphp

<section
    class="fpb-section"
    data-fpb-columns="{{ $columns }}"
    data-fpb-ratio="{{ $ratio }}"
>
    @foreach (PageBuilder::slotNames() as $name)
        {!! PageBuilder::slot($name) !!}
    @endforeach
</section>
