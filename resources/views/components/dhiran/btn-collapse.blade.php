{{-- Icon-only on phones and tablets, icon + text on desktop (owner 2026-11-22). Only for universally understood, non-destructive
     actions (download, filter, print, back, add, edit, search...). The text stays in the DOM, so screen readers still read it.
     <x-dhiran.btn-collapse icon="download" label="Export CSV" :href="$url" /> or without href for a button; extra attributes pass through. --}}
@props(['icon', 'label', 'href' => null, 'variant' => 'secondary'])
@if($href)
<a href="{{ $href }}" {{ $attributes->class(['btn', "btn-{$variant}", 'btn-sm', 'btn-collapse'])->merge(['title' => $label]) }}>
@else
<button type="button" {{ $attributes->class(['btn', "btn-{$variant}", 'btn-sm', 'btn-collapse'])->merge(['title' => $label]) }}>
@endif
    <x-icon :name="$icon" size="16" /><span class="btn-text">{{ $label }}</span>
@if($href)</a>@else</button>@endif
