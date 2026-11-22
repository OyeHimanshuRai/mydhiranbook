<x-dhiran-layout title="Customer Profile">
    @vite('resources/css/dhiran-detail.css')
    @php
        $rupee = fn ($n) => '₹' . \App\Support\IndianNumber::format($n, 2);
        $tel = \App\Support\Mobile::isValid($customer->mobile) ? \App\Support\Mobile::forWhatsApp($customer->mobile) : null;
        $pan = $kycPan ?? $customer->pan ?? null;
        $hasIdProof = $attachments->contains(fn ($a) => str_starts_with((string) $a->document_type, 'id_proof'));
        $allItems = $loans->flatMap(fn ($l) => $l->items->map(fn ($i) => [$i, $l]));
        $outstanding = $summary['principal_outstanding'] + $summary['interest_outstanding'];
        $counts = ['Active' => 'active', 'Awaiting evidence' => 'pending_evidence', 'Closed' => 'closed', 'Renewed' => 'renewed', 'Forfeited' => 'forfeited'];
    @endphp

    <x-dhiran.page-header>
        <div class="min-w-0">
            <h1 class="page-title">{{ $customer->name }}</h1>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.create', ['customer_id' => $customer->id]) }}" class="btn btn-primary btn-sm" aria-label="New Loan for this customer" title="New Loan for this customer">
                <x-icon name="plus" size="16" class="mr-1" />
                New Loan
            </a>
            <x-dhiran.btn-collapse icon="arrow-left" label="Back to Customers" :href="route('dhiran.borrowers.index')" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dd dd-profile">

        {{-- 1. Basic details --}}
        <section class="dd-card dd-who-card" aria-labelledby="dd-cust-h">
            <h2 id="dd-cust-h" class="dd-h">Customer details</h2>
            <ul class="dd-chips" aria-label="KYC">
                <li class="{{ $pan ? 'is-ok' : 'is-missing' }}"><x-icon :name="$pan ? 'badge-check' : 'triangle-alert'" />PAN {{ $pan ? 'on file' : 'missing' }}</li>
                <li class="{{ $kycAadhaar ? 'is-ok' : 'is-missing' }}"><x-icon :name="$kycAadhaar ? 'badge-check' : 'triangle-alert'" />Aadhaar {{ $kycAadhaar ? 'on file' : 'missing' }}</li>
                <li class="{{ $hasIdProof ? 'is-ok' : 'is-missing' }}"><x-icon :name="$hasIdProof ? 'badge-check' : 'triangle-alert'" />ID proof {{ $hasIdProof ? 'uploaded' : 'not uploaded' }}</li>
            </ul>
            <x-customer-photo :customer="$customer" />
            <dl class="dd-kv">
                <div><dt>Name</dt><dd>{{ $customer->name }}</dd></div>
                <div><dt>Mobile</dt><dd>@if($tel)<a href="tel:+{{ $tel }}" class="dd-tel">{{ \App\Support\Mobile::forDisplay($customer->mobile) }}</a>@else{{ \App\Support\Mobile::forDisplay($customer->mobile) ?: '—' }}@endif</dd></div>
                <div><dt>Customer code</dt><dd>{{ $customer->customer_code ?? '—' }}</dd></div>
                @if($customer->address)<div class="is-span"><dt>Address</dt><dd>{{ $customer->address }}</dd></div>@endif
                @if($customer->email)<div><dt>Email</dt><dd>{{ $customer->email }}</dd></div>@endif
                <div><dt>PAN</dt><dd>{{ $pan ?? '—' }}</dd></div>
                <div><dt>Aadhaar (masked)</dt><dd>{{ $kycAadhaar ?? '—' }}</dd></div>
                @if($customer->notes)<div class="is-span"><dt>Notes</dt><dd>{{ $customer->notes }}</dd></div>@endif
            </dl>
        </section>

        {{-- 2. Loan summary: what is owed, then how many loans in each state --}}
        <section class="dd-card" aria-labelledby="dd-sum-h">
            <h2 id="dd-sum-h" class="dd-sr">Loan summary</h2>
            <div class="dd-total">
                <span class="dd-fig-label">Outstanding</span>
                <span class="dd-total-value">{{ $rupee($outstanding) }}</span>
            </div>
            <dl class="dd-figs">
                @foreach($counts as $label => $key)
                    <div><dt>{{ $label }}</dt><dd class="{{ $summary[$key] > 0 ? '' : 'is-zero' }}">{{ number_format($summary[$key]) }}</dd></div>
                @endforeach
            </dl>
        </section>

        <nav class="dd-jump" aria-label="Sections">
            <a href="#loans" class="dd-jump-link">Loans</a>
            @if($hasPayments)<a href="#payments" class="dd-jump-link">Payments</a>@endif
            @if($allItems->isNotEmpty())<a href="#items" class="dd-jump-link">Pledged items</a>@endif
            <a href="#documents" class="dd-jump-link">Documents</a>
        </nav>

        {{-- 4. Loans: status chips with counts, 10 a page --}}
        <section id="loans" data-profile-section class="dd-card dd-tablecard dd-section" aria-labelledby="dd-loans-h">
            @php
                $loanFilters = ['loan_status' => [
                    'legend' => 'Status', 'any' => 'All loans',
                    'options' => collect(\App\Services\Dhiran\DhiranListFilters::STATUSES)
                        ->filter(fn ($l, $s) => ($summary[$s] ?? 0) > 0 || $loanStatus === $s)
                        ->map(fn ($l, $s) => [$l, $summary[$s] ?? 0])->all(),
                ]];
            @endphp
            <div class="dd-head">
                <h2 id="dd-loans-h" class="dd-h">Loans <span class="dd-count">{{ $loans->count() }}</span></h2>
                @if($loans->isNotEmpty())
                    <x-dhiran.filter-panel :groups="$loanFilters" title="Filter loans" fragment="loans" page-name="loans_page" />
                @endif
            </div>
            <x-dhiran.filter-panel :groups="$loanFilters" fragment="loans" page-name="loans_page" mode="chips" />
            @if($loans->isEmpty())
                <div class="dd-empty">
                    <p class="dd-empty-title">No loans yet</p>
                    <p>This customer has no pledge loans with your shop.</p>
                    <a href="{{ route('dhiran.create', ['customer_id' => $customer->id]) }}" class="btn btn-primary btn-sm">Create loan for this customer</a>
                </div>
            @elseif($loanList->isEmpty())
                <div class="dd-empty"><p>No {{ strtolower(\App\Services\Dhiran\DhiranListFilters::STATUSES[$loanStatus] ?? '') }} loans for this customer.</p></div>
            @else
                <table class="dd-table dd-t-rec">
                    <thead>
                        <tr>
                            <th scope="col">Loan #</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Principal</th>
                            <th scope="col" class="num">Outstanding</th>
                            <th scope="col">Created</th>
                            <th scope="col">Maturity</th>
                            <th scope="col"><span class="dd-sr">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($loanList as $loan)
                            <tr>
                                <td data-cell="title" class="strong"><a href="{{ route('dhiran.show', $loan) }}">{{ $loan->loan_number }}</a></td>
                                <td data-label="Status"><x-dhiran.status :value="$loan->status" /></td>
                                <td data-label="Principal" class="num">{{ $rupee($loan->principal_amount) }}</td>
                                <td data-label="Outstanding" class="num strong">{{ $rupee($loan->totalOutstanding()) }}</td>
                                <td data-label="Created">{{ $loan->loan_date?->format('d M Y') }}</td>
                                <td data-label="Maturity">{{ $loan->maturity_date?->format('d M Y') }}</td>
                                <td data-cell="act"><a href="{{ route('dhiran.show', $loan) }}" class="dd-tlink dd-tlink--link">View loan <span class="dd-sr">{{ $loan->loan_number }}</span></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-dhiran.pager :paginator="$loanList" noun="loans" :sizes="[]" />
            @endif
        </section>

        {{-- 5. Payments: the full history, 10 a page, by type and by loan --}}
        @if($hasPayments)
        <section id="payments" data-profile-section class="dd-card dd-tablecard dd-section" aria-labelledby="dd-pays-h">
            @php
                $paymentFilters = ['payment_type' => [
                    'legend' => 'Type', 'any' => 'All types',
                    'options' => collect($paymentTypes)
                        ->filter(fn ($def, $key) => $paymentTypeCounts[$key] > 0 || $paymentType === $key)
                        ->map(fn ($def, $key) => [$def[0], $paymentTypeCounts[$key]])->all(),
                ]];
                if ($loans->count() > 1) {
                    $paymentFilters['payment_loan'] = ['legend' => 'Loan', 'any' => 'All loans',
                        'options' => $loans->mapWithKeys(fn ($l) => [$l->id => [$l->loan_number, null]])->all()];
                }
            @endphp
            <div class="dd-head">
                <h2 id="dd-pays-h" class="dd-h">Payments <span class="dd-count">{{ array_sum($paymentTypeCounts) }}</span></h2>
                <div class="dd-tools">
                    <x-dhiran.filter-panel :groups="$paymentFilters" title="Filter payments" fragment="payments" page-name="payments_page" />
                    <x-dhiran.btn-collapse icon="download" label="Export CSV" :href="request()->fullUrlWithQuery(['export' => 'payments', 'payments_page' => null])" data-turbo="false" download />
                </div>
            </div>
            <x-dhiran.filter-panel :groups="$paymentFilters" fragment="payments" page-name="payments_page" mode="chips" />
            <p class="dd-summary" data-payments-summary>
                {{ $payments->total() }} {{ \Illuminate\Support\Str::plural('payment', $payments->total()) }}
                · <strong>{{ $rupee($paymentSummary['received']) }}</strong> received
                · <strong>{{ $rupee($paymentSummary['interest']) }}</strong> interest
                @if($paymentSummary['penalty'] > 0) · <strong>{{ $rupee($paymentSummary['penalty']) }}</strong> penalty @endif
            </p>
            @if($payments->isEmpty())
                <div class="dd-empty"><p>No payments match these filters.</p></div>
            @else
                <table class="dd-table dd-t-pay">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Loan</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="num">Principal</th>
                            <th scope="col" class="num">Interest</th>
                            <th scope="col" class="num">Penalty</th>
                            <th scope="col" class="num">Total</th>
                            <th scope="col"><span class="dd-sr">Receipt</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($payments as $p)
                            <tr>
                                <td data-label="Date">{{ optional($p->payment_date)->format('d M Y') ?? $p->created_at->format('d M Y') }}</td>
                                <td data-label="Loan">@if($p->loan)<a href="{{ route('dhiran.show', $p->loan) }}">{{ $p->loan->loan_number }}</a>@else — @endif</td>
                                <td data-cell="title" class="strong">{{ ucwords(str_replace('_', ' ', $p->type)) }}</td>
                                <td data-label="Principal" class="num">{{ $rupee($p->principal_component) }}</td>
                                <td data-label="Interest" class="num">{{ $rupee($p->interest_component) }}</td>
                                <td data-label="Penalty" class="num">{{ $rupee($p->penalty_component) }}</td>
                                <td data-cell="amount" data-label="Total" class="num strong">{{ $rupee($p->amount) }}</td>
                                <td data-cell="act">@if($p->loan)<a href="{{ route('dhiran.payment-receipt', [$p->loan, $p]) }}" class="dd-tlink">Receipt</a>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-dhiran.pager :paginator="$payments" noun="payments" :sizes="[]" />
            @endif
        </section>
        @endif

        {{-- 6. Pledged items history --}}
        @if($allItems->isNotEmpty())
        <section id="items" data-profile-section class="dd-card dd-tablecard dd-section" aria-labelledby="dd-items-h">
            <div class="dd-head"><h2 id="dd-items-h" class="dd-h">Pledged items</h2></div>
            <table class="dd-table dd-t-rec">
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Metal</th>
                        <th scope="col">Purity</th>
                        <th scope="col" class="num">Gross (g)</th>
                        <th scope="col" class="num">Net (g)</th>
                        <th scope="col" class="num">Fine (g)</th>
                        <th scope="col">Status</th>
                        <th scope="col">Loan</th>
                        <th scope="col">Photo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($allItems as [$item, $loan])
                        <tr>
                            <td data-cell="title" class="strong"><a href="{{ route('dhiran.items.show', $item) }}">{{ $item->description }}</a></td>
                            <td data-label="Metal" class="capitalize">{{ $item->metal_type ?? 'gold' }}</td>
                            <td data-label="Purity">@if(($item->metal_type ?? "gold") === "other")—@else{{ rtrim(rtrim((string) $item->purity, "0"), ".") }}{{ ($item->metal_type ?? "gold") === "silver" ? "" : "K" }}@endif</td>
                            <td data-label="Gross (g)" class="num">{{ number_format($item->gross_weight, 3) }}</td>
                            <td data-label="Net (g)" class="num">{{ number_format($item->net_metal_weight, 3) }}</td>
                            <td data-label="Fine (g)" class="num strong">{{ number_format($item->fine_weight, 3) }}</td>
                            <td data-label="Status" class="capitalize">{{ $item->status ?? 'pledged' }}</td>
                            <td data-label="Loan"><a href="{{ route('dhiran.show', $loan) }}">{{ $loan->loan_number }}</a></td>
                            <td data-label="Photo">@if(in_array($item->id, $itemsWithPhoto))<span class="dd-photo-tag">Photo</span>@else<span aria-label="No photo">—</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif

        {{-- 3. KYC / Documents --}}
        <section id="documents" class="dd-card dd-card-pad dd-section" aria-labelledby="dd-docs-h">
            <h2 id="dd-docs-h" class="dd-h">Documents &amp; evidence</h2>
            <p class="dd-sub">ID proof, photos, and loan documents for this customer. Files are private to your shop.</p>
            <x-dhiran.evidence-gallery :attachments="$attachments" />

            {{-- The customer's own documents; an ID proof here counts for every loan of this customer. --}}
            @can('dhiran.create')
            @if($errors->has('file'))
                <p class="dd-error" role="alert">{{ $errors->first('file') }}</p>
            @endif
            <form method="POST" action="{{ route('dhiran.attachments.store') }}" enctype="multipart/form-data" data-turbo-frame="_top" class="dh-upload-form dd-upload">
                @csrf
                <input type="hidden" name="owner_type" value="customer">
                <input type="hidden" name="owner_id" value="{{ $customer->id }}">
                <div>
                    <label for="cd-type">Document type</label>
                    <select id="cd-type" name="document_type" required class="dh-form-control dh-native-select">
                        <option value="id_proof_front">ID proof (front)</option>
                        <option value="id_proof_back">ID proof (back)</option>
                        <option value="address_proof">Address proof</option>
                        <option value="borrower_photo">Borrower photo</option>
                    </select>
                </div>
                <div>
                    <label for="cd-file">File <small>(JPG/PNG/PDF, max 8 MB)</small></label>
                    <input id="cd-file" type="file" name="file" required accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="dh-file-input">
                </div>
                <button type="submit" class="btn btn-dark btn-sm" data-turbo-submits-with="Uploading…">Upload</button>
            </form>
            @endcan
        </section>
    </div>
</x-dhiran-layout>
