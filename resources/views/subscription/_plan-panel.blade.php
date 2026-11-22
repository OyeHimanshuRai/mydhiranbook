{{-- Plan card in the settings family (app shell). $plan, $label. One cycle per plan: yearly when it has a yearly price. --}}
@php($yearly = $plan->price_yearly !== null)
<div class="st-plan">
    <h3>{{ $plan->name }}</h3>
    <p class="st-plan-price">{{ \App\Support\IndianNumber::rupee($yearly ? $plan->price_yearly : $plan->price_monthly, 0) }}<small> / {{ $yearly ? 'year' : 'month' }}</small></p>
    <ul>
        <li><x-icon name="check" size="16" /> Up to {{ $plan->feature('staff_limit') }} staff accounts</li>
        <li><x-icon name="check" size="16" /> Up to {{ number_format($plan->feature('max_items')) }} items</li>
        <li><x-icon name="check" size="16" /> {{ $plan->grace_days }} days of full access after expiry</li>
    </ul>
    <form method="post" action="{{ route('subscription.choose') }}" data-turbo="false" data-once>@csrf
        <input type="hidden" name="plan_id" value="{{ $plan->id }}"><input type="hidden" name="billing_cycle" value="{{ $yearly ? 'yearly' : 'monthly' }}">
        <button class="btn btn-primary btn-sm" data-busy="Please wait…">{{ $label }}</button>
    </form>
</div>
