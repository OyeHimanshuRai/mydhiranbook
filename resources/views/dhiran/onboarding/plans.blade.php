<x-subscription-page title="Choose your Dhiran plan">
<style>.sub-plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}.sub-plan{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:24px}.sub-plan h2{margin:0 0 8px}.sub-price{font-size:30px;font-weight:600}.sub-price small{font-size:14px;font-weight:400;color:#64748b}.sub-plan ul{padding-left:18px;margin:16px 0 20px}</style>
<div class="content-inner">
    <h1>Start your Dhiran subscription</h1>
    <p class="muted">Choose a plan and pay once through Razorpay. You set up your business right after payment.</p>
    @if($plans->isEmpty())<p class="notice">Plans are not available right now. Please contact support.</p>@endif
    <div class="sub-plans">@foreach($plans as $plan)@include('subscription._plan-card', ['plan' => $plan, 'label' => 'Continue to payment'])@endforeach</div>
</div>
</x-subscription-page>
