<x-subscription-page title="{{ $rejected ? 'Request not approved' : 'Waiting for approval' }}">
    @unless($rejected)<meta http-equiv="refresh" content="60">@endunless
    <div class="ck ck-narrow">
        @if($rejected)
            <h1>We could not approve your shop</h1>
            <p class="lede">{{ $shop->name }} was not approved.</p>
            @if($shop->review_note)<div class="ui-card"><strong>Reason</strong><p>{{ $shop->review_note }}</p></div>@endif
            <p class="lede">Write to us at {{ config('business.email') ?: 'the contact page' }} if you think this is a mistake.</p>
        @else
            <h1>Your shop is waiting for approval</h1>
            <p class="lede">Thank you. We are checking the details of <strong>{{ $shop->name }}</strong>. You will get an email as soon as it is approved, and this page opens your dashboard by itself.</p>
            <div class="ui-card">
                <dl>
                    <dt>Business</dt><dd>{{ $shop->name }}</dd>
                    <dt>Phone</dt><dd>{{ $shop->phone }}</dd>
                    <dt>Address</dt><dd>{{ $shop->address }}</dd>
                    <dt>Sent</dt><dd>{{ $shop->created_at?->format('d M Y, h:i A') }}</dd>
                </dl>
            </div>
            <a href="{{ route('dhiran.pending') }}" class="ui-btn ui-btn--block">Check again</a>
        @endif
    </div>
</x-subscription-page>
