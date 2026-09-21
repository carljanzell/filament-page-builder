@props(['blocks' => []])

@php
    $blocks = \CarlJanzell\FilamentPageBuilder\Support\BlockTree::hydrate($blocks);
    $roots = \CarlJanzell\FilamentPageBuilder\Support\BlockTree::childrenOf($blocks, null);
@endphp

<div {{ $attributes->class('fpb-page') }}>
    @foreach ($roots as $block)
        <x-page-builder::public-block :block="$block" :blocks="$blocks" />
    @endforeach
</div>
