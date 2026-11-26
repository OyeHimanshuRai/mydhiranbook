@extends('site.layout')
@section('title', 'Pledge-loan software for jewellers')

@section('content')
<section class="lp-hero">
    <div class="lp-in lp-hero-grid">
        <div class="lp-hero-copy">
            <h1>Pledge-loan accounting built for jewellery counters</h1>
            <p class="lp-lead">
                MyDhiranBook records gold and silver pledges with purity and net weight, tracks monthly interest and repayments, prints counter receipts, and keeps every borrower balance ready on your tablet or phone.
            </p>
            <div class="lp-actions">
                <a class="lp-btn lp-btn--primary" href="/register">Get started</a>
                <a class="lp-btn lp-btn--outline" href="/pricing">See pricing</a>
            </div>
            <p class="lp-note">
                Accounts open for one full year after we approve your shop, with staff logins included.
            </p>
        </div>

        <figure class="lp-media">
            <img src="/images/landing/hero-counter.jpg"
                 alt="Jewellery showroom counter with precision digital gold scale, tablet ledger screen, and oxblood pledge notebook"
                 width="1200" height="896">
        </figure>
    </div>
</section>

<section class="lp-band lp-band--surface" aria-labelledby="lp-specimen-heading">
    <div class="lp-in lp-specimen-grid">
        <div class="lp-specimen-intro">
            <h2 id="lp-specimen-heading">See how every pledge step calculates cleanly</h2>
            <p class="lp-section-lead">
                From the first ornament weighing to part repayments and printed counter copies, every figure updates transparently using exact Indian lakh rupee grouping.
            </p>
            <div class="lp-tabs lp-tabs--stacked" role="tablist" aria-label="Sample counter views">
                <button type="button" class="lp-tab" role="tab" id="lp-tab-pledge" aria-selected="true" aria-controls="lp-panel-pledge" data-lp-tab="pledge">Pledge entry</button>
                <button type="button" class="lp-tab" role="tab" id="lp-tab-repay" aria-selected="false" aria-controls="lp-panel-repay" tabindex="-1" data-lp-tab="repay">Repayment</button>
                <button type="button" class="lp-tab" role="tab" id="lp-tab-receipt" aria-selected="false" aria-controls="lp-panel-receipt" tabindex="-1" data-lp-tab="receipt">Receipt preview</button>
            </div>
        </div>

        <div class="lp-frame">
            <div class="lp-panel" role="tabpanel" id="lp-panel-pledge" aria-labelledby="lp-tab-pledge" data-lp-panel="pledge">
                <div class="lp-panel-head">
                    <div>
                        <h3>Loan LN-1048</h3>
                        <p class="lp-panel-sub">Sample pledge calculation</p>
                    </div>
                    <span class="lp-status lp-status--ok">Active</span>
                </div>
                <dl class="lp-kv">
                    <div><dt>Borrower</dt><dd>Ramesh Soni</dd></div>
                    <div><dt>Pledged item</dt><dd>Gold chain (22K, 916)</dd></div>
                    <div><dt>Gross and stone weight</dt><dd>19.200 g less 0.800 g</dd></div>
                    <div><dt>Net and fine metal</dt><dd>18.400 g net, 16.854 g fine</dd></div>
                    <div><dt>Principal lent</dt><dd class="lp-fig">&#8377;52,000.00</dd></div>
                    <div><dt>Interest rate</dt><dd>1.50% per month (6 months)</dd></div>
                </dl>
                <div class="lp-panel-foot">
                    <span>Maximum loan at 75% LTV</span>
                    <strong>&#8377;1,05,337.50 limit available</strong>
                </div>
            </div>

            <div class="lp-panel" role="tabpanel" id="lp-panel-repay" aria-labelledby="lp-tab-repay" data-lp-panel="repay" hidden>
                <div class="lp-panel-head">
                    <div>
                        <h3>Repayment LN-1048</h3>
                        <p class="lp-panel-sub">Sample part-payment entry</p>
                    </div>
                    <span class="lp-status lp-status--accent">Part paid</span>
                </div>
                <dl class="lp-kv">
                    <div><dt>Opening principal</dt><dd>&#8377;52,000.00</dd></div>
                    <div><dt>Interest collected (2 months)</dt><dd>&#8377;1,560.00</dd></div>
                    <div><dt>Principal reduced</dt><dd>&#8377;10,000.00</dd></div>
                    <div><dt>Total paid at counter</dt><dd class="lp-fig">&#8377;11,560.00</dd></div>
                    <div><dt>Remaining principal</dt><dd class="lp-fig">&#8377;42,000.00</dd></div>
                    <div><dt>Next monthly interest</dt><dd>&#8377;630.00 per month</dd></div>
                </dl>
                <div class="lp-panel-foot">
                    <span>Balance status</span>
                    <strong>Recalculated on &#8377;42,000.00</strong>
                </div>
            </div>

            <div class="lp-panel" role="tabpanel" id="lp-panel-receipt" aria-labelledby="lp-tab-receipt" data-lp-panel="receipt" hidden>
                <div class="lp-panel-head">
                    <div>
                        <h3>Pledge receipt</h3>
                        <p class="lp-panel-sub">Sample A5 counter printout</p>
                    </div>
                    <span class="lp-status">Copy</span>
                </div>
                <dl class="lp-kv">
                    <div><dt>Issue date</dt><dd>25 Nov 2026</dd></div>
                    <div><dt>Borrower</dt><dd>Ramesh Soni</dd></div>
                    <div><dt>Ornament detail</dt><dd>Gold chain (22K, HUID)</dd></div>
                    <div><dt>Verified weight</dt><dd>18.400 g net metal</dd></div>
                    <div><dt>Loan principal</dt><dd class="lp-fig">&#8377;52,000.00</dd></div>
                    <div><dt>Monthly terms</dt><dd>1.50% flat monthly</dd></div>
                </dl>
                <div class="lp-panel-foot">
                    <span>Printed field rules</span>
                    <strong>Configured in shop settings</strong>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="lp-band" aria-labelledby="lp-ops-heading">
    <div class="lp-in lp-ops-layout">
        <div class="lp-ops-visual">
            <figure class="lp-media">
                <img src="/images/landing/weighing-kyc.jpg"
                     alt="Silver anklets and gold bangles beside a digital weighing scale and counter smartphone camera"
                     width="1200" height="896" loading="lazy">
            </figure>
        </div>

        <div class="lp-ops-content">
            <div class="lp-ops-intro">
                <h2 id="lp-ops-heading">Built for everyday counter speed and ledger accuracy</h2>
                <p class="lp-section-lead">
                    Every workflow follows how jewellery pledge counters operate in practice, from weighing ornaments and verifying KYC to closing out repayments cleanly.
                </p>
            </div>

            <div class="lp-ops-grid">
                <div class="lp-op">
                    <h3>Gold and silver valuation</h3>
                    <p>Enter gross weight, stone deduction, and standard or custom purity in karats or fineness so net weight and loan-to-value ceilings calculate automatically.</p>
                </div>
                <div class="lp-op">
                    <h3>Interest and settlement ledger</h3>
                    <p>Collect interest, record part or full principal payments, handle renewals, pre-close early, or process forfeiture with every step appended chronologically.</p>
                </div>
                <div class="lp-op">
                    <h3>Borrower profiles and KYC scans</h3>
                    <p>Keep mobile numbers, addresses, PAN, ID proofs, and pledged item photos attached to each borrower using live camera capture or file uploads.</p>
                </div>
                <div class="lp-op">
                    <h3>Instant A5 and A4 receipts</h3>
                    <p>Print formatted pledge and payment receipts directly from the counter in clean ink layout while choosing in settings which columns customers see.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="lp-band lp-band--surface" aria-labelledby="lp-owners-heading">
    <div class="lp-in lp-owners-layout">
        <div class="lp-owners-copy">
            <h2 id="lp-owners-heading">Clear control whether you are at the shop or away</h2>
            <p class="lp-section-lead">
                Shop owners often check active books from a phone away from the counter while staff record daily entries on a tablet or desktop. Permission boundaries keep financial totals separate from clerk actions.
            </p>

            <div class="lp-spec-list">
                <div class="lp-spec">
                    <h3>Password-locked financial reports</h3>
                    <p>Active loans, overdue accounts, interest collected, forfeiture notices, cashbook summaries, and profitability exports sit behind an owner report lock.</p>
                </div>
                <div class="lp-spec">
                    <h3>Role-scoped staff logins</h3>
                    <p>Create staff accounts and grant each counter clerk only the loan creation, repayment, or borrower screens required for their role.</p>
                </div>
                <div class="lp-spec">
                    <h3>Bring forward existing loans</h3>
                    <p>Upload active pledges from spreadsheets or record prior-date opening balances directly instead of re-entering your existing register by hand.</p>
                </div>
            </div>
        </div>

        <div class="lp-owner-col">
            <figure class="lp-media">
                <img src="/images/landing/owner-receipt.jpg"
                     alt="Printed A5 counter receipt sheet beside a pen, oxblood folder, and owner smartphone"
                     width="1200" height="896" loading="lazy">
            </figure>

            <div class="lp-sheet-frame">
                <table class="lp-sheet">
                    <caption>Software details for counter and owner use</caption>
                    <thead>
                        <tr>
                            <th scope="col">Capability</th>
                            <th scope="col">Included behaviour</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Collateral types</th>
                            <td>Gold, silver, and custom appraised ornaments</td>
                        </tr>
                        <tr>
                            <th scope="row">Regional formats</th>
                            <td>Indian lakh rupee grouping and dd Mon yyyy dates</td>
                        </tr>
                        <tr>
                            <th scope="row">Printed documents</th>
                            <td>Pledge receipts, payment receipts, and notices</td>
                        </tr>
                        <tr>
                            <th scope="row">Device support</th>
                            <td>Full counter and owner views on phones and tablets</td>
                        </tr>
                        <tr>
                            <th scope="row">Shop onboarding</th>
                            <td>One-year shop approval without online payment steps</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<section class="lp-band lp-close" aria-labelledby="lp-close-heading">
    <div class="lp-in lp-close-grid">
        <div class="lp-close-copy">
            <h2 id="lp-close-heading">Move your pledge register off paper notebooks</h2>
            <p class="lp-section-lead">
                Register your account, verify your email address, and send your shop details. Once approved, your business opens with one year of counter access for your team.
            </p>
        </div>
        <div class="lp-close-actions">
            <a class="lp-btn lp-btn--primary" href="/register">Get started</a>
            <a class="lp-btn lp-btn--text" href="/contact">Ask a question before registering</a>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
(() => {
    const tabs = Array.from(document.querySelectorAll('[data-lp-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-lp-panel]'));
    if (!tabs.length || !panels.length) return;

    const activate = (name, focusTab = false) => {
        tabs.forEach((tab) => {
            const on = tab.dataset.lpTab === name;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            if (on && focusTab) tab.focus();
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.lpPanel !== name;
        });
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.lpTab));
        tab.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                const next = (index + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                activate(tabs[next].dataset.lpTab, true);
            }
        });
    });
})();
</script>
@endpush
