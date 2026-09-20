@props(['data' => []])

<p class="fpb-text" @editable('body')>{{ $data['body'] ?? '' }}</p>
