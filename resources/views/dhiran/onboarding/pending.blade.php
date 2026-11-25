<x-subscription-page title="{{ $rejected ? 'Request not approved' : 'Waiting for approval' }}">
    @unless($rejected)<meta http-equiv="refresh" content="60">@endunless
    @if($rejected)
        @php $contact = collect([config('business.email'), config('business.phone')])->filter()->implode(' or ') ?: 'the contact page'; @endphp
        <x-status-card icon="x" title="We could not approve your shop">
            <p class="sc-lede"><strong>{{ $shop->name }}</strong> was not approved. You can fix the details below and send them again.</p>
            @if($shop->review_note)<div class="sc-note"><strong>Reason</strong><p>{{ $shop->review_note }}</p></div>@endif
            <form method="POST" action="{{ route('dhiran.resubmit') }}" data-turbo="false" data-once class="sc-form">@csrf
                <div class="ck-grid">
                    <x-site.field wide name="name" label="Business name" req :value="old('name', $shop->name)" required maxlength="150" autocomplete="organization" />
                    <x-site.field name="phone" label="Business phone" type="tel" req :value="old('phone', $shop->phone)" required inputmode="numeric" maxlength="16" autocomplete="tel" placeholder="10-digit number" />
                    <x-site.field name="loan_number_prefix" label="Loan receipt prefix" hint="Printed before every loan number." :value="old('loan_number_prefix', $prefix)" maxlength="10" autocapitalize="characters" />
                    <x-site.field wide name="address" label="Business address" req :value="old('address', $shop->address)" required maxlength="500" autocomplete="street-address" />
                </div>
                <div class="sc-actions"><button type="submit" class="ui-btn sc-btn" data-busy="Sending…">Fix and send again</button></div>
            </form>
            <p class="sc-quiet">Not sure what to change? Write to us at {{ $contact }}.</p>
        </x-status-card>
    @else
        @php $sent = ($shop->resubmitted_at ?? $shop->created_at)?->format('d M Y, h:i A'); @endphp
        <x-status-card icon="clock" title="Your shop is waiting for approval">
            <p class="sc-lede">We check every new shop. You will get an email as soon as <strong>{{ $shop->name }}</strong> is approved, and this page opens your dashboard by itself.</p>
            <ol class="sc-steps">
                @foreach([['Account created', null], ['Email verified', null], ['Details sent', $sent]] as [$label, $meta])
                    <li class="is-done"><span class="sc-dot"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span><div><b>{{ $label }}</b><span class="sr-only"> (done)</span>@if($meta)<small>{{ $meta }}</small>@endif</div></li>
                @endforeach
                <li class="is-now" aria-current="step"><span class="sc-dot"></span><div><b>Approval</b><small>We are checking your details now</small></div></li>
                <li class="is-next"><span class="sc-dot"></span><div><b>Start using MyDhiranBook</b></div></li>
            </ol>
            <dl class="sc-box">
                <dt>Business</dt><dd>{{ $shop->name }}</dd>
                <dt>Phone</dt><dd>{{ $shop->phone }}</dd>
                <dt>Address</dt><dd>{{ $shop->address }}</dd>
            </dl>
            <div class="sc-actions">
                <a href="{{ route('dhiran.pending') }}" class="ui-btn sc-btn">Check again</a>
                <p class="sc-quiet">This page also refreshes by itself every minute.</p>
            </div>
        </x-status-card>
    @endif
</x-subscription-page>
