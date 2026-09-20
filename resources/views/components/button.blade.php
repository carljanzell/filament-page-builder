@props(['data' => []])

<a
    class="fpb-button"
    href="{{ filled($data['url'] ?? null) ? $data['url'] : '#' }}"
    @editable('label')
>{{ $data['label'] ?? '' }}</a>
