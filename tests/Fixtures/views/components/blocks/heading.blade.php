@props(['data' => []])

<h2 class="blk-heading" @editable('text')>{{ $data['text'] ?? '' }}</h2>
