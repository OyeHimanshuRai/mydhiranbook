<x-subscription-page title="Choose your Dhiran plan">
<div class="ck">
    <h1>Start your Dhiran subscription</h1>
    <p class="lede">Choose a plan and pay once through Razorpay. You set up your business right after payment.</p>
    @if($plans->isEmpty())<p class="ui-notice">Plans are not available right now. Please contact support.</p>@endif
    <div class="ck-plans">@foreach($plans as $plan)@include('subscription._plan-card', ['plan' => $plan, 'label' => 'Continue to payment'])@endforeach</div>
</div>
</x-subscription-page>
