<x-subscription-page title="{{ $ownAccount ? 'Account switched off' : 'Shop suspended' }}">
    <x-status-card icon="lock" :title="$ownAccount ? 'Your account is switched off' : 'This shop is suspended'">
        @if($ownAccount)
            <p class="sc-lede">Your login for {{ $shop?->name ?: 'MyDhiranBook' }} was switched off, so you cannot open the software. Nothing was deleted. Ask the owner of your shop to switch it back on.</p>
        @else
            <p class="sc-lede"><strong>{{ $shop->name }}</strong> was suspended, so nobody of the shop can open the software for now. Your records are safe and nothing was deleted.</p>
            <div class="sc-note">
                <strong>What to do</strong>
                <p>Contact support {{ collect([config('business.email'), config('business.phone')])->filter()->implode(' or ') }} and tell us your shop name: {{ $shop->name }}. We will tell you why and what is needed to open it again.</p>
            </div>
        @endif
        <div class="sc-actions"><a href="{{ route('dhiran.blocked') }}" class="ui-btn sc-btn">Check again</a></div>
    </x-status-card>
</x-subscription-page>
