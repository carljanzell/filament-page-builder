@props(['parent', 'slot', 'children' => [], 'selectedId' => null])

@foreach ($children as $child)
    <x-page-builder::canvas-block :block="$child" :selected-id="$selectedId" />
@endforeach
