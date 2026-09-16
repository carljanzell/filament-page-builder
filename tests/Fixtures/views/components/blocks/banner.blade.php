@props(['data' => []])

<div class="blk-banner">
    <p @editable('caption')>{{ $data['caption'] ?? '' }}</p>
    <span @editable('image')>not declared editable</span>
</div>
