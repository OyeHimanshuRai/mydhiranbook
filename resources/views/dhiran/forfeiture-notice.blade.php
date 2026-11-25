<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forfeiture Notice - {{ $loan->loan_number }}</title>
    @vite(['resources/css/dhiran-documents.css'])
</head>
<body class="dh-doc-body dh-doc-forfeiture-notice">
    @php
        $shop = auth()->user()?->shop;
        $shopName = $shop?->name ?: 'MyDhiranBook';
        $shopAddress = $shop?->address ?? null;
        $shopContact = $shop?->mobile ?? $shop?->phone ?? auth()->user()?->mobile ?? null;
        $shopEmail = auth()->user()?->email;
        $shopGstin = $shop?->gst_number ?? null;
        $customer = $loan->customer;
        $settings = \App\Models\Dhiran\DhiranSettings::getForShop($shop->id);
        $loanDate = $loan->loan_date ? $loan->loan_date->format('d M Y') : '-';
        $maturityDate = $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '-';
        $graceEnd = ($loan->maturity_date && $loan->grace_period_days)
            ? $loan->maturity_date->copy()->addDays($loan->grace_period_days)->format('d M Y')
            : $maturityDate;
        $totalOutstanding = $loan->totalOutstanding();
        $forfeitureNoticeDays = $settings->forfeiture_notice_days ?? 30;
        // A reprint keeps the day the notice was issued and the deadline that was given, whatever day it is printed on.
        $issuedOn = $loan->forfeiture_notice_sent_at ? \Illuminate\Support\Carbon::parse($loan->forfeiture_notice_sent_at)->timezone(config('app.timezone')) : now();
        $deadline = $loan->forfeitureDeadline() ?? $issuedOn->copy()->addDays($forfeitureNoticeDays);
        // The system-generated wording (plain text, so it can be edited), and the owner's own wording when they saved one.
        $n2 = fn ($v) => number_format((float) $v, 2);
        $systemBody = $loan->forfeiture_notice_text ?: "This notice is issued in connection with Pledge Loan {$loan->loan_number} dated {$loanDate} for a principal amount of {$n2($loan->principal_amount)}.\n\n"
            . "The above loan has matured on {$maturityDate} and the grace period ended on {$graceEnd}. Despite reminders, the outstanding amount of {$n2($totalOutstanding)} remains unpaid.\n\n"
            . "You are hereby requested to settle the total outstanding amount within {$forfeitureNoticeDays} days from the date of this notice, failing which we shall be constrained to forfeit and dispose of the pledged ornaments as per the terms of the loan agreement.";
        $systemWarning = "WARNING: If the outstanding amount is not settled within {$forfeitureNoticeDays} days from the date of this notice (i.e. by {$deadline->format('d M Y')}), the pledged gold ornaments will be forfeited and disposed of to recover the dues without further notice.";
        $show = \App\Models\Dhiran\DhiranSettings::receiptVisibility((int) $loan->shop_id, 'notice'); // the owner's Settings > Receipt switches; the Loan Number is always printed
        $any = fn (string ...$keys) => collect($keys)->contains(fn ($k) => $show($k));
        $edit = $loan->forfeiture_notice_edit;
        $showSystem = request()->boolean('original') || ! $edit;
        $bodyText = $showSystem ? $systemBody : $edit['body'];
        $warningText = $showSystem ? $systemWarning : $edit['warning'];
        $canEdit = ! ($sample ?? false) && auth()->user()?->can('dhiran.forfeit') && $loan->status === 'active' && $loan->forfeitureNoticeSent();
    @endphp

    @if($sample ?? false) @include('dhiran._sample-banner') @endif
    <style>@media print { .notice-tools { display: none !important; } }
        .notice-tools { max-width: 760px; margin: 12px auto 0; display: grid; gap: 8px; }
        .notice-tools .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: flex-end; }
        .notice-tools .note { font-size: 13px; color: #555; margin: 0; flex: 1 1 14rem; }
        .notice-tools form { display: grid; gap: 8px; padding: 12px; border: 1px solid #bbb; border-radius: 6px; background: #fff; }
        .notice-tools label { font-weight: 600; font-size: 13px; display: grid; gap: 4px; }
        .notice-tools textarea { width: 100%; font: inherit; font-weight: 400; padding: 8px; border: 1px solid #999; border-radius: 4px; }
    </style>
    <div class="notice-tools">
        <div class="row">
            @if($edit && $showSystem)<p class="note">Showing the system-generated notice. Your edited version is saved.</p>@elseif($edit)<p class="note">Showing your edited notice (saved by {{ $edit['by'] ?? 'someone' }}).</p>@else<p class="note">Showing the system-generated notice.</p>@endif
            <button type="button" class="btn" onclick="window.print()">{{ $showSystem ? 'Print system notice' : 'Print edited notice' }}</button>
            @if($edit && ! $showSystem)<a class="btn" href="{{ route('dhiran.forfeiture-notice', ['loan' => $loan, 'original' => 1]) }}">View system notice</a>@endif
            @if($edit && $showSystem)<a class="btn" href="{{ route('dhiran.forfeiture-notice', $loan) }}">View edited notice</a>@endif
            @if($canEdit)<button type="button" class="btn" onclick="document.getElementById('notice-editor').hidden = !document.getElementById('notice-editor').hidden">Edit notice</button>@endif
        </div>
        @if($canEdit)
            <form id="notice-editor" method="POST" action="{{ route('dhiran.forfeiture-notice.update', $loan) }}" hidden>
                @csrf @method('PATCH')
                <label>Letter <textarea name="body" rows="10" maxlength="5000" required>{{ old('body', $edit['body'] ?? $systemBody) }}</textarea></label>
                <label>Warning <textarea name="warning" rows="4" maxlength="1000" required>{{ old('warning', $edit['warning'] ?? $systemWarning) }}</textarea></label>
                <p class="note">The loan facts and amounts above the letter always come from the loan. Every edit is written to the loan's activity log.</p>
                <div class="row">
                    <button type="submit" name="then" value="print" class="btn">Save and print</button>
                    <button type="submit" class="btn">Save</button>
                    @if($edit)<button type="submit" name="reset" value="1" class="btn" formnovalidate onclick="return confirm('Go back to the system-generated notice? Your edited wording will be removed (it stays in the activity log).')">Use system text</button>@endif
                </div>
            </form>
        @endif
        @if(session('success'))<p class="note" role="status">{{ session('success') }}</p>@endif
        @if($errors->any())<p class="note" role="alert">{{ $errors->first() }}</p>@endif
    </div>
    @if(request()->boolean('print'))<script>window.addEventListener('load', () => window.print());</script>@endif

    <div class="receipt-page">
        <div class="receipt">
            <header class="receipt-head">
                <div>
                    @if($show('title'))<div class="brand-mark">
                        <x-icon name="triangle-alert" />
                        FORFEITURE NOTICE
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
                    @if($show('title'))<h1 class="receipt-title">Forfeiture Notice</h1>@endif
                    @if($show('notice_date'))<div class="receipt-sub">Date: {{ $issuedOn->format('d M Y') }}</div>@endif
                    <span class="receipt-pill">{{ $loan->loan_number }}</span>
                </div>
            </header>

            <div class="receipt-body">
                <section class="detail-grid">
                    @if($show('from'))<article class="detail-card">
                        <p class="detail-label">From</p>
                        <p class="detail-name">{{ $shopName }}</p>
                        @if($shopAddress)
                            <p class="detail-text">{{ $shopAddress }}</p>
                        @endif
                    </article>@endif
                    @if($any('to_name', 'to_contact'))<article class="detail-card">
                        <p class="detail-label">To</p>
                        @if($show('to_name'))<p class="detail-name">{{ $customer?->name ?? 'Walk-in Customer' }}</p>@endif
                        @if($show('to_contact'))<p class="detail-text">
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
                    @if($show('maturity_date'))<div class="meta-box">
                        <p class="meta-box-label">Maturity Date</p>
                        <p class="meta-box-value">{{ $maturityDate }}</p>
                    </div>@endif
                    @if($show('days_overdue'))<div class="meta-box">
                        <p class="meta-box-label">Days Overdue</p>
                        <p class="meta-box-value">{{ $loan->maturity_date ? max(0, (int) $loan->maturity_date->copy()->startOfDay()->diffInDays($issuedOn->copy()->startOfDay(), false)) : 0 }}</p>
                    </div>@endif
                </section>

                @if($any('principal_due', 'interest_due', 'penalty_due', 'total_due'))<section class="ledger">
                    @if($show('principal_due'))<div class="ledger-row"><span>Outstanding Principal</span><strong>{{ number_format((float) $loan->outstanding_principal, 2) }}</strong></div>@endif
                    @if($show('interest_due'))<div class="ledger-row"><span>Outstanding Interest</span><strong>{{ number_format((float) $loan->outstanding_interest, 2) }}</strong></div>@endif
                    @if($show('penalty_due'))<div class="ledger-row"><span>Outstanding Penalty</span><strong>{{ number_format((float) $loan->outstanding_penalty, 2) }}</strong></div>@endif
                    @if($show('total_due'))<div class="ledger-row ledger-total"><span>Total Outstanding</span><strong>{{ number_format($totalOutstanding, 2) }}</strong></div>@endif
                </section>@endif

                @if($show('letter'))<section class="notice-body">
                    <p>Dear <strong>{{ $customer?->name ?? 'Sir/Madam' }}</strong>,</p>
                    @foreach(preg_split('/\n\s*\n/', trim($bodyText)) as $para)
                        <p>{!! nl2br(e($para)) !!}</p>
                    @endforeach
                </section>@endif

                @if($show('warning'))<section class="warning-box">{!! nl2br(e($warningText)) !!}</section>@endif

                @if($show('signatures'))<div class="signature-row">
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
            @unless($sample ?? false)<a class="btn" href="{{ route('dhiran.show', $loan) }}">Back to Loan</a>@endunless
        </div>
    </div>
</body>
</html>
