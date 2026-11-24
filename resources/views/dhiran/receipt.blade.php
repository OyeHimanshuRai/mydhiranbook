@php
    // $show('key'): the owner's Settings > Receipt switches. The Loan Number has no switch and is always printed.
    $cur = $currencySymbol ?? '₹';
    $any = fn (string ...$keys) => collect($keys)->contains(fn ($k) => $show($k));
    $terms = $loan->terms_text;
    $metal = fn ($i) => $i->metal_type ?? 'gold';
    $cols = [
        'item_description' => ['Description', '', fn ($i) => e($i->description) . ($show('item_huid') && $i->huid ? '<span class="rc-item-meta">HUID: ' . e($i->huid) . '</span>' : '')],
        'item_metal'       => ['Metal', '', fn ($i) => e(ucfirst($metal($i)))],
        'item_gross'       => ['Gross wt (g)', 'r', fn ($i) => number_format($i->gross_weight, 3)],
        'item_net'         => ['Net wt (g)', 'r', fn ($i) => number_format($i->net_metal_weight, 3)],
        'item_purity'      => ['Purity', 'c', fn ($i) => $metal($i) === 'other' ? '—' : rtrim(rtrim((string) $i->purity, '0'), '.') . ($metal($i) === 'silver' ? '' : 'K')],
        'item_fine'        => ['Fine wt (g)', 'r', fn ($i) => number_format($i->fine_weight, 3)],
        'item_value'       => ['Value', 'r', fn ($i) => $cur . number_format($i->market_value, 2)],
    ];
    $cols = array_filter($cols, fn ($c, $k) => $show($k), ARRAY_FILTER_USE_BOTH);
    $items = $loan->items ?? collect();
    $sums = ['item_gross' => $items->sum('gross_weight'), 'item_net' => $items->sum('net_metal_weight'), 'item_fine' => $items->sum('fine_weight'), 'item_value' => $items->sum('market_value')];
    $totalValue = $sums['item_value'];
    $sumKeys = array_values(array_intersect(array_keys($cols), array_keys($sums)));
    $lead = $sumKeys ? array_search($sumKeys[0], array_keys($cols)) + 1 : 0; // "#" plus the columns before the first total
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pledge Receipt &mdash; {{ $loan->loan_number }}</title>
    @include('dhiran._receipt-css')
</head>
<body class="rc-body dh-doc-pledge-receipt">
    <div class="rc-bar receipt-no-print">
        <button type="button" class="rc-btn receipt-print-btn" onclick="window.print()"><x-icon name="printer" /> Print Receipt</button>
    </div>

    <div class="receipt-container">
        @if($any('shop_name', 'shop_address', 'shop_phone', 'title'))
        <header class="rc-head">
            @if($show('shop_name'))<div class="receipt-shop-name">{{ $shop->name ?? 'Dhiran' }}</div>@endif
            @if($show('shop_address') && ($shop->address ?? false))<div class="rc-muted">{{ $shop->address }}</div>@endif
            @if($show('shop_phone') && ($shop->phone ?? false))<div class="rc-muted">Phone: {{ $shop->phone }}</div>@endif
            @if($show('title'))<div class="rc-title">Pledge Receipt / Girvi Parchi</div>@endif
        </header>
        @endif

        <section class="rc-sec">
            <dl class="rc-rows rc-cols">
                <div class="rc-row"><dt>Loan Number</dt><dd>{{ $loan->loan_number }}</dd></div>
                @if($show('loan_date'))<div class="rc-row"><dt>Date</dt><dd>{{ $loan->loan_date->format('d M Y') }}</dd></div>@endif
                @if($show('principal_amount'))<div class="rc-row"><dt>Principal Amount</dt><dd>{{ $cur }}{{ number_format($loan->principal_amount, 2) }}</dd></div>@endif
                @if($show('interest_rate'))<div class="rc-row"><dt>Interest Rate</dt><dd>{{ $loan->interest_rate_monthly }}% / month ({{ ucfirst($loan->interest_type) }})</dd></div>@endif
                @if($show('tenure'))<div class="rc-row"><dt>Tenure</dt><dd>{{ $loan->tenure_months }} months</dd></div>@endif
                @if($show('maturity_date'))<div class="rc-row"><dt>Maturity Date</dt><dd>{{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}</dd></div>@endif
            </dl>
        </section>

        @if($any('customer_name', 'customer_mobile') || ($any('kyc_aadhaar') && $loan->kyc_aadhaar) || ($any('kyc_pan') && $loan->kyc_pan))
        <section class="rc-sec">
            <h2 class="rc-sec-title">Borrower</h2>
            <dl class="rc-rows rc-cols">
                @if($show('customer_name'))<div class="rc-row"><dt>Customer</dt><dd>{{ $loan->customer->name ?? '---' }}</dd></div>@endif
                @if($show('customer_mobile'))<div class="rc-row"><dt>Contact</dt><dd>{{ \App\Support\Mobile::forDisplay($loan->customer?->mobile) ?: '---' }}</dd></div>@endif
                @if($show('kyc_aadhaar') && $loan->kyc_aadhaar)<div class="rc-row"><dt>Aadhaar</dt><dd>{{ $loan->kyc_aadhaar }}</dd></div>@endif
                @if($show('kyc_pan') && $loan->kyc_pan)<div class="rc-row"><dt>PAN</dt><dd>{{ $loan->kyc_pan }}</dd></div>@endif
            </dl>
        </section>
        @endif

        @if($cols)
        <table class="rc-table">
            <thead>
                <tr>
                    <th>#</th>
                    @foreach($cols as $c)<th class="{{ $c[1] }}">{{ $c[0] }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        @foreach($cols as $c)<td class="{{ $c[1] }}">{!! $c[2]($item) !!}</td>@endforeach
                    </tr>
                @endforeach
            </tbody>
            @if($show('item_totals') && $sumKeys)
            <tfoot>
                <tr>
                    <td colspan="{{ $lead }}">Total</td>
                    @foreach(array_slice($cols, $lead - 1, null, true) as $k => $c)
                        <td class="{{ $c[1] }}">@if(isset($sums[$k])){{ $k === 'item_value' ? $cur . number_format($sums[$k], 2) : number_format($sums[$k], 3) }}@endif</td>
                    @endforeach
                </tr>
            </tfoot>
            @endif
        </table>
        @endif

        @php $advance = $loan->advanceInterestAmount(); @endphp
        @if($any('summary_market_value', 'summary_ltv', 'summary_principal') || ($show('summary_fee') && ($loan->processing_fee ?? 0) > 0) || ($advance > 0 && $any('summary_advance', 'summary_cash')))
        <dl class="rc-rows rc-summary">
            @if($show('summary_market_value'))<div class="rc-row"><dt>Total Market Value</dt><dd>{{ $cur }}{{ number_format($totalValue, 2) }}</dd></div>@endif
            @if($show('summary_ltv'))<div class="rc-row"><dt>LTV Applied</dt><dd>{{ number_format($loan->ltv_percent ?? 0, 1) }}%</dd></div>@endif
            @if($show('summary_principal'))<div class="rc-row rc-strong"><dt>Principal Amount Disbursed</dt><dd>{{ $cur }}{{ number_format($loan->principal_amount, 2) }}</dd></div>@endif
            @if($show('summary_fee') && ($loan->processing_fee ?? 0) > 0)<div class="rc-row"><dt>Processing Fee</dt><dd>{{ $cur }}{{ number_format($loan->processing_fee, 2) }}</dd></div>@endif
            @if($advance > 0 && $show('summary_advance'))<div class="rc-row"><dt>Advance Interest ({{ $loan->advance_interest_months }} month{{ $loan->advance_interest_months > 1 ? 's' : '' }})</dt><dd>{{ $cur }}{{ number_format($advance, 2) }}</dd></div>@endif
            @if($advance > 0 && $show('summary_cash'))<div class="rc-row rc-strong"><dt>Cash Handed to Borrower</dt><dd>{{ $cur }}{{ number_format((float) $loan->principal_amount - $advance - (float) ($loan->processing_fee ?? 0), 2) }}</dd></div>@endif
        </dl>
        @endif

        @if($show('terms') && $terms)
        <section class="rc-sec">
            <h2 class="rc-sec-title">Terms &amp; Conditions</h2>
            <div class="rc-terms">{{ $terms }}</div>
        </section>
        @endif

        @if($show('signatures'))
        <div class="rc-sigs">
            <div class="rc-sig">Pledger's Signature @if($show('customer_name'))<br><span class="rc-muted">{{ $loan->customer->name ?? '' }}</span>@endif</div>
            <div class="rc-sig">Authorized Signatory @if($show('shop_name'))<br><span class="rc-muted">{{ $shop->name ?? 'Dhiran' }}</span>@endif</div>
        </div>
        @endif

        @if($show('footer_note'))
        <div class="rc-foot">This is a computer-generated document. &nbsp;|&nbsp; Generated on {{ now()->format('d M Y, h:i A') }}</div>
        @endif
    </div>
</body>
</html>
