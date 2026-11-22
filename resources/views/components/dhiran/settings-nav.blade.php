@props(['active', 'as' => 'nav', 'switcher' => false])
{{-- Sub-navigation shared by the settings pages; each item only for users who can open its page.
     One items list, two parts. as="nav" (default): the section links. as="title": the page <h1> for the header band.
     Pills everywhere by default. A page that opts in uses BOTH parts: <x-dhiran.settings-nav active="receipt" as="title" /> in
     <x-dhiran.page-header> and <x-dhiran.settings-nav active="receipt" switcher /> in the content. Up to 900px the title then
     becomes a switcher (resources/js/section-switch.js) that opens the same links as a list; from 901px the pills show. --}}
@php
    $user = auth()->user();
    $items = array_filter([
        'loan' => ['Loan settings', route('dhiran.settings'), $user->can('dhiran.settings')],
        'receipt' => ['Receipt settings', route('dhiran.settings.receipt.edit'), $user->can('dhiran.settings')],
        'users' => ['Users & roles', route('settings.edit', ['tab' => 'staff']), $user->can('staff.view')],
        'billing' => ['Plan & billing', route('subscription.status'), $user->isShopOwner()],
    ], fn ($i) => $i[2]);
@endphp
@once
    @vite(['resources/css/dhiran-receipt-settings.css'])
@endonce
@if($as === 'title')
    @php($current = $items[$active][0] ?? 'Settings')
    <div class="min-w-0">
        <h1 class="page-title ui-titleswitch">
            <span class="ui-titleswitch-text">{{ $current }}</span>
            <button type="button" class="ui-titleswitch-btn" data-section-switch aria-controls="settings-sections" aria-expanded="false" aria-haspopup="true">
                <span data-section-label>{{ $current }}</span>
                <x-icon name="chevron-down" />
            </button>
        </h1>
    </div>
@else
    <nav class="dh-subnav"{!! $switcher ? ' id="settings-sections" data-section-list' : '' !!} aria-label="Settings sections">
        @foreach($items as $key => [$label, $href])
            <a href="{{ $href }}" class="dh-subnav-link" @if($key === $active) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
@endif
