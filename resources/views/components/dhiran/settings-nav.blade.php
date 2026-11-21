@props(['active'])
{{-- Sub-navigation shared by the settings pages; each item only for users who can open its page. --}}
@php
    $user = auth()->user();
    $items = array_filter([
        'loan' => ['Loan', route('dhiran.settings'), $user->can('dhiran.settings')],
        'receipt' => ['Receipt', route('dhiran.settings.receipt.edit'), $user->can('dhiran.settings')],
        'users' => ['Users & roles', route('settings.edit', ['tab' => 'staff']), $user->can('staff.view')],
        'billing' => ['Plan & billing', route('subscription.status'), $user->isShopOwner()],
    ], fn ($i) => $i[2]);
@endphp
<nav class="dh-subnav" aria-label="Settings sections">
    @foreach($items as $key => [$label, $href])
        <a href="{{ $href }}" class="dh-subnav-link" @if($key === $active) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
