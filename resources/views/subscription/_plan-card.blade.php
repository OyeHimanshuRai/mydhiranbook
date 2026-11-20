{{-- $plan, $label (button text). One cycle per plan: yearly when it has a yearly price. --}}
@php($yearly = $plan->price_yearly !== null)
<div class="sub-plan">
    <h2>{{ $plan->name }}</h2>
    <div class="sub-price">₹{{ number_format($yearly ? $plan->price_yearly : $plan->price_monthly) }}<small> / {{ $yearly ? 'year' : 'month' }}</small></div>
    <ul>
        <li>Up to {{ $plan->feature('staff_limit') }} staff accounts</li>
        <li>Up to {{ number_format($plan->feature('max_items')) }} items</li>
        <li>{{ $plan->grace_days }} days of full access after expiry</li>
    </ul>
    <form method="post" action="{{ route('subscription.choose') }}" data-turbo="false">@csrf
        <input type="hidden" name="plan_id" value="{{ $plan->id }}"><input type="hidden" name="billing_cycle" value="{{ $yearly ? 'yearly' : 'monthly' }}">
        <button class="gold">{{ $label }}</button>
    </form>
</div>
