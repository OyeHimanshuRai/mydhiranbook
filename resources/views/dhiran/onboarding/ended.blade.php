<x-subscription-page title="Access ended">
    <x-status-card icon="lock" title="Your access has ended">
        <p class="sc-lede">The one-year access for <strong>{{ $shop->name }}</strong>@if($endedOn) ended on {{ \Illuminate\Support\Carbon::parse($endedOn)->format('d M Y') }}@endif. Your records are safe and nothing was deleted. The software opens again as soon as we extend your access by another year.</p>
        <div class="sc-note">
            <strong>To extend</strong>
            <p>Contact us {{ collect([config('business.email'), config('business.phone')])->filter()->implode(' or ') }} and tell us your shop name: {{ $shop->name }}.</p>
        </div>
        <div class="sc-actions"><a href="{{ route('dhiran.expired') }}" class="ui-btn sc-btn">Check again</a></div>
    </x-status-card>
</x-subscription-page>
