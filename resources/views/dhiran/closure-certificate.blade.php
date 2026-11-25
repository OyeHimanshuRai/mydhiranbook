<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closure Certificate - {{ $loan->loan_number }}</title>
    @vite(['resources/css/dhiran-documents.css'])
</head>
<body class="dh-doc-body dh-doc-closure-certificate">
    @php
        $shop = auth()->user()?->shop;
        $shopName = $shop?->name ?: 'MyDhiranBook';
        $shopAddress = $shop?->address ?? null;
        $shopContact = $shop?->mobile ?? $shop?->phone ?? auth()->user()?->mobile ?? null;
        $shopEmail = auth()->user()?->email;
        $shopGstin = $shop?->gst_number ?? null;
        $customer = $loan->customer;
        $settings = \App\Models\Dhiran\DhiranSettings::getForShop($shop->id);
        $closedDate = $loan->closed_at ? $loan->closed_at->format('d M Y') : now()->format('d M Y');
        $loanDate = $loan->loan_date ? $loan->loan_date->format('d M Y') : '-';
        $totalCollected = (float) $loan->total_principal_collected
            + (float) $loan->total_interest_collected
            + (float) $loan->total_penalty_collected
            + (float) $loan->processing_fee;
        $show = \App\Models\Dhiran\DhiranSettings::receiptVisibility((int) $loan->shop_id, 'closure'); // the owner's Settings > Receipt switches; the Loan Number is always printed
        $any = fn (string ...$keys) => collect($keys)->contains(fn ($k) => $show($k));
    @endphp
    @if($sample ?? false) @include('dhiran._sample-banner') @endif

    <div class="receipt-page">
        <div class="receipt">
            <header class="receipt-head">
                <div>
                    @if($show('title'))<div class="brand-mark">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2 4 9l8 13 8-13-8-7Zm0 3.01L16.2 8H7.8L12 5.01ZM8.92 10h6.16L12 15.04 8.92 10Z"/>
                        </svg>
                        CLOSURE CERTIFICATE
                    </div>@endif
                    @if($show('shop_name'))<p class="shop-name">{{ $shopName }}</p>@endif
                    @if($shopAddress && $show('shop_address'))
                        <p class="shop-meta">{{ $shopAddress }}</p>
                    @endif
                    @if($show('shop_contact'))<p class="shop-meta">
                        @if($shopContact) {{ $shopContact }} @endif
                        @if($shopContact && $shopEmail) &middot; @endif
                        @if($shopEmail) {{ $shopEmail }} @endif
                    </p>@endif
                    @if($shopGstin && $show('shop_gstin'))
                        <p class="shop-meta">GSTIN: {{ $shopGstin }}</p>
                    @endif
                </div>
                <div>
                    @if($show('title'))<h1 class="receipt-title">Closure Certificate</h1>@endif
                    @if($show('generated_on'))<div class="receipt-sub">Generated on {{ now()->format('d M Y') }}</div>@endif
                    <span class="receipt-pill">{{ $loan->loan_number }}</span>
                </div>
            </header>

            <div class="receipt-body">
                <section class="detail-grid">
                    @if($show('lender'))<article class="detail-card">
                        <p class="detail-label">Lender</p>
                        <p class="detail-name">{{ $shopName }}</p>
                        @if($shopAddress)
                            <p class="detail-text">{{ $shopAddress }}</p>
                        @endif
                    </article>@endif
                    @if($any('borrower_name', 'borrower_contact'))<article class="detail-card">
                        <p class="detail-label">Borrower</p>
                        @if($show('borrower_name'))<p class="detail-name">{{ $customer?->name ?? 'Walk-in Customer' }}</p>@endif
                        @if($show('borrower_contact'))<p class="detail-text">
                            {{ \App\Support\Mobile::forDisplay($customer?->mobile) ?: 'No mobile available' }}
                            @if($customer?->address)
                                <br>{{ $customer->address }}
                            @endif
                        </p>@endif
                    </article>@endif
                </section>

                <section class="meta-strip">
                    <div class="meta-box">
                        <p class="meta-box-label">Loan No.</p>
                        <p class="meta-box-value">{{ $loan->loan_number }}</p>
                    </div>
                    @if($show('loan_date'))<div class="meta-box">
                        <p class="meta-box-label">Loan Date</p>
                        <p class="meta-box-value">{{ $loanDate }}</p>
                    </div>@endif
                    @if($show('principal'))<div class="meta-box">
                        <p class="meta-box-label">Principal</p>
                        <p class="meta-box-value">{{ number_format((float) $loan->principal_amount, 2) }}</p>
                    </div>@endif
                    @if($show('closed_on'))<div class="meta-box">
                        <p class="meta-box-label">Closed On</p>
                        <p class="meta-box-value">{{ $closedDate }}</p>
                    </div>@endif
                </section>

                @if($show('statement'))<section class="cert-statement">
                    This is to certify that Pledge Loan <strong>{{ $loan->loan_number }}</strong>
                    dated <strong>{{ $loanDate }}</strong>, sanctioned in favour of
                    <strong>{{ $customer?->name ?? 'Walk-in Customer' }}</strong>,
                    for an amount of <strong>{{ number_format((float) $loan->principal_amount, 2) }}</strong>,
                    has been fully settled and closed on <strong>{{ $closedDate }}</strong>.
                    All pledged items have been returned to the borrower and no further obligations remain.
                </section>@endif

                @if($loan->items->count() && $show('items_table'))
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th>Metal</th>
                            <th>Qty</th>
                            <th class="text-right">Gross Wt (g)</th>
                            <th class="text-right">Net Wt (g)</th>
                            <th class="text-center">Purity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($loan->items as $index => $item)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td><strong>{{ $item->description }}</strong></td>
                            <td>{{ ucfirst($item->metal_type ?? '-') }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-right">{{ number_format((float) $item->gross_weight, 3) }}</td>
                            <td class="text-right">{{ number_format((float) $item->net_metal_weight, 3) }}</td>
                            <td class="text-center">{{ $item->purity ? number_format((float) $item->purity, 2) . 'K' : '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif

                @if($any('amount_principal', 'amount_interest', 'amount_penalty', 'amount_fee', 'amount_total'))<section class="ledger">
                    @if($show('amount_principal'))<div class="ledger-row"><span>Principal Amount</span><strong>{{ number_format((float) $loan->principal_amount, 2) }}</strong></div>@endif
                    @if($show('amount_interest'))<div class="ledger-row"><span>Interest Collected</span><strong>{{ number_format((float) $loan->total_interest_collected, 2) }}</strong></div>@endif
                    @if($show('amount_penalty'))<div class="ledger-row"><span>Penalty Collected</span><strong>{{ number_format((float) $loan->total_penalty_collected, 2) }}</strong></div>@endif
                    @if($show('amount_fee'))<div class="ledger-row"><span>Processing Fee</span><strong>{{ number_format((float) $loan->processing_fee, 2) }}</strong></div>@endif
                    @if($show('amount_total'))<div class="ledger-row ledger-total"><span>Total Collected</span><strong>{{ number_format($totalCollected, 2) }}</strong></div>@endif
                </section>@endif

                @if($settings->closure_certificate_text && $show('footer_text'))
                    <div class="cert-footer-text">
                        {!! nl2br(e($settings->closure_certificate_text)) !!}
                    </div>
                @endif

                @if($loan->closure_notes && $show('closure_notes'))
                    <div class="cert-footer-text">
                        <strong>Closure Notes:</strong> {{ $loan->closure_notes }}
                    </div>
                @endif

                @if($show('signatures'))<div class="signature-row">
                    <div class="signature-block">
                        <div class="signature-line">Borrower's Signature</div>
                    </div>
                    <div class="signature-block">
                        <div class="signature-line">Date</div>
                    </div>
                    <div class="signature-block">
                        <div class="signature-line">For {{ $shopName }}</div>
                    </div>
                </div>@endif
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn" onclick="window.print()">Print</button>
            @unless($sample ?? false)<a class="btn" href="{{ route('dhiran.show', $loan) }}">Back to Loan</a>@endunless
        </div>
    </div>
</body>
</html>
