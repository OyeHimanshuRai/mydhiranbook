<x-subscription-page title="Access ended">
    <div class="ck ck-narrow">
        <h1>Your access has ended</h1>
        <p class="lede">The one-year access for <strong>{{ $shop->name }}</strong>@if($endedOn) ended on {{ \Illuminate\Support\Carbon::parse($endedOn)->format('d M Y') }}@endif. Your records are safe and nothing was deleted. The software opens again as soon as we extend your access by another year.</p>
        <div class="ui-card">
            <strong>To extend</strong>
            <p>Contact us {{ collect([config('business.email'), config('business.phone')])->filter()->implode(' or ') }} and tell us your shop name: {{ $shop->name }}.</p>
        </div>
        <a href="{{ route('dhiran.expired') }}" class="ui-btn ui-btn--block">Check again</a>
    </div>
</x-subscription-page>
