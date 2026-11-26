@extends('site.layout')
@section('title', 'Pricing')

@section('content')
<section class="lp-subhero">
    <div class="lp-in lp-subhero-grid">
        <div>
            <h1>Pricing and shop access</h1>
            <p class="lp-lead">
                Access is granted for 1 year after we approve your shop; we extend it when the year ends. There is nothing to pay on this site.
            </p>
            <div class="lp-actions">
                <a class="lp-btn lp-btn--primary" href="/register">Register your shop</a>
                <a class="lp-btn lp-btn--outline" href="/contact">Contact support</a>
            </div>
        </div>

        <figure class="lp-media">
            <img src="/images/landing/owner-receipt.jpg"
                 alt="Printed A5 counter receipt beside an oxblood ledger folder and owner phone"
                 width="1200" height="896">
        </figure>
    </div>
</section>

<section class="lp-band lp-band--surface" aria-labelledby="lp-access-heading">
    <div class="lp-in lp-pricing-grid">
        <article class="lp-access-card">
            <h2 id="lp-access-heading">1 year of access</h2>
            <p class="lp-access-note">
                One direct shop approval term covering counter and mobile access for your team.
            </p>
            <ul class="lp-checks">
                <li>Register and verify your email</li>
                <li>Send us your shop details</li>
                <li>We approve your shop and your 1-year access starts</li>
                <li>When the year ends, <a href="/contact">contact us</a> and we extend it</li>
            </ul>
            <a class="lp-btn lp-btn--primary" href="/register">Register your shop</a>
        </article>

        <div class="lp-spec-list">
            <div class="lp-spec">
                <h3>Email verification before setup</h3>
                <p>Create your owner account and confirm your email address with a verification code so account alerts and security notices reach your inbox.</p>
            </div>
            <div class="lp-spec">
                <h3>Manual shop onboarding review</h3>
                <p>Submit your shop name, phone number, and address after verifying your email. Our team reviews your request and approves your business space with no online payment steps.</p>
            </div>
            <div class="lp-spec">
                <h3>Staff logins and counter devices included</h3>
                <p>One approved term covers your daily counter workflow, A5 and A4 receipt printing, owner phone views, and role-scoped logins for your shop staff.</p>
            </div>
            <div class="lp-spec">
                <h3>Year-end renewal on your request</h3>
                <p>Your 1-year access term never renews automatically. When the year ends, write or call our team and we extend your access with all existing records kept intact.</p>
            </div>
        </div>
    </div>
</section>
@endsection
