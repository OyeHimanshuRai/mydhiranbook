@props(['icon' => 'clock', 'title'])
<div class="ck sc">
    <div class="sc-ic sc-ic--{{ $icon }}" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            @if($icon === 'x')<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>
            @elseif($icon === 'lock')<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>
            @else<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@endif
        </svg>
    </div>
    <h1 class="sc-h">{{ $title }}</h1>
    {{ $slot }}
</div>
