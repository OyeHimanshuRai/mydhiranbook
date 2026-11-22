{{-- Empty state: what is missing, why, and the next step (the slot is the action, a link or button). --}}
@props(['title', 'text' => null, 'icon' => 'info'])
<div class="dh-empty">
    <x-icon :name="$icon" />
    <p class="dh-empty-title">{{ $title }}</p>
    @if($text)<p class="dh-empty-text">{{ $text }}</p>@endif
    {{ $slot }}
</div>
