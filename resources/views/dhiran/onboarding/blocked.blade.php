<x-subscription-page title="{{ $ownAccount ? 'Account switched off' : 'Shop suspended' }}">
    <div class="ck ck-narrow">
        @if($ownAccount)
            <h1>Your account is switched off</h1>
            <p class="lede">Your login for {{ $shop?->name ?: 'MyDhiranBook' }} was switched off, so you cannot open the software. Nothing was deleted. Ask the owner of your shop to switch it back on.</p>
        @else
            <h1>This shop is suspended</h1>
            <p class="lede"><strong>{{ $shop->name }}</strong> was suspended, so nobody of the shop can open the software for now. Your records are safe and nothing was deleted.</p>
            <div class="ui-card">
                <strong>What to do</strong>
                <p>Contact support {{ collect([config('business.email'), config('business.phone')])->filter()->implode(' or ') }} and tell us your shop name: {{ $shop->name }}. We will tell you why and what is needed to open it again.</p>
            </div>
        @endif
        <a href="{{ route('dhiran.blocked') }}" class="ui-btn ui-btn--block">Check again</a>
    </div>
</x-subscription-page>
