@php
    $f = $filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request());
    $rupee = fn ($n) => '₹' . \App\Support\IndianNumber::format($n, 2);
@endphp
<x-dhiran-layout title="Loans" list>
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Pledge Loans</h1>
            <p class="text-sm text-gray-500 mt-1">All gold &amp; silver pledge loans</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" size="14" class="mr-1" />
                New Loan
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>

        {{-- Filters --}}
        <x-dhiran.filter-bar :filters="$f" :action="route('dhiran.loans')" period-label="Loan date"
            :fields="['search', 'period', 'status', 'metal', 'amount', 'overdue', 'maturing']" />
        <turbo-frame id="dh-results" target="_top">

        {{-- Phones show each loan as a card and hide the table head, so the select-all box is repeated here (shown only in card layout). --}}
        <div class="dh-pick-all-sm">
            <label><input type="checkbox" data-bulk-all> <span>Select all on this page</span></label>
        </div>

        {{-- Loans: a table from 700px of list width, one card per loan below that. --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Loan records">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-pick-th"><label class="dh-pick-box"><input type="checkbox" data-bulk-all aria-label="Select all on this page"></label></th>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Loan #</th>
                            <th scope="col">Customer</th>
                            <th scope="col" data-low="2">Mobile</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col" class="text-right">Outstanding</th>
                            <th scope="col" class="text-right" data-low="1">Rate</th>
                            <th scope="col" class="text-center">Status</th>
                            <th scope="col">Maturity</th>
                            <th scope="col" class="text-center" data-low="2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans ?? [] as $loan)
                            <tr>
                                <td data-label="" data-cell="pick" class="dh-pick">
                                    <label class="dh-pick-box"><input type="checkbox" data-bulk-id="{{ $loan->id }}" aria-label="Select loan {{ $loan->loan_number }}"><span class="dh-pick-text" aria-hidden="true">Select</span></label>
                                </td>
                                <x-dhiran.sno :rows="$loans ?? null" :n="$loop->iteration" />
                                <td data-label="Loan #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-[var(--kh-ink-2)]">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="dh-clip font-medium text-slate-700" title="{{ $loan->customer->name ?? '---' }}">{{ $loan->customer->name ?? '---' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Principal" class="whitespace-nowrap text-right">{{ $rupee($loan->principal_amount) }}</td>
                                <td data-label="Outstanding" class="whitespace-nowrap text-right font-semibold text-slate-800">{{ $rupee($loan->total_outstanding) }}</td>
                                <td data-label="Rate" data-low="1" class="whitespace-nowrap text-right">{{ $loan->interest_rate_monthly }}%/mo</td>
                                <td data-label="Status" data-cell="status" class="text-center">
                                    {{-- The "upload proof" hint is the badge's tooltip, not a second line under it (one value per cell). --}}
                                    <x-dhiran.status :value="$loan->status" :title="$loan->status === 'pending_evidence' ? 'Upload the required proof to activate this loan' : null" />
                                    {{-- Paid in advance, month by month: only when the next installment is due within a week or late --}}
                                    @php $instDue = $loan->status === 'active' ? $loan->nextInstallmentDue() : null; $instDays = $instDue ? (int) today()->diffInDays($instDue, false) : null; @endphp
                                    @if($instDue && $instDays <= 7)
                                        <div><span class="dh-status dh-status--{{ $instDays < 0 ? 'danger' : 'warning' }}" title="Interest is paid in advance: next installment due {{ $instDue->format('d M Y') }}">{{ $instDays < 0 ? 'Interest late ' . abs($instDays) . ' d' : 'Interest due ' . $instDue->format('d M') }}</span></div>
                                    @endif
                                </td>
                                <td data-label="Maturity" class="whitespace-nowrap">{{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}</td>
                                <td data-label="Actions" data-cell="hide" data-low="2" class="text-center">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="jf-row-action jf-row-action--view" title="View Loan"><x-icon name="eye" size="16" /><span class="dh-act-label">View loan<span class="jf-sr-only"> {{ $loan->loan_number }}</span></span></a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="jf-table-empty">
                                    @if($f->any())
                                        <x-dhiran.empty icon="search" title="No loans match" text="Nothing fits the search or filters you chose.">
                                            <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">Clear search and filters</a>
                                        </x-dhiran.empty>
                                    @else
                                        <x-dhiran.empty icon="inbox" title="No loans yet" text="Pledge loans you create will be listed here.">
                                            <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm">Create the first loan</a>
                                        </x-dhiran.empty>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @isset($loans)<x-dhiran.pager :paginator="$loans" noun="loans" />@endisset
        </div>
        </turbo-frame>
    </div>

    {{-- Bulk actions. Only safe, read-only ones live here (CSV export, print item tags). Money and legal-state changes (repay, close,
         forfeit, delete) are never bulk: they stay one loan at a time, on the loan's own page. The bar sits OUTSIDE the list container
         (a container query box would trap its fixed phone docking) and outside the results frame, so live search keeps it. --}}
    <div class="content-inner dh-bulk-dock">
        <div class="dh-bulk" data-bulk-bar hidden role="region" aria-label="Bulk actions"
             data-export-url="{{ route('dhiran.loans') }}" data-tags-url="{{ route('dhiran.tags.bulk') }}" data-max="100">
            <span class="dh-bulk-count" data-bulk-count role="status" aria-live="polite" aria-atomic="true">0 selected</span>
            <span class="dh-bulk-note" data-bulk-note role="status" aria-live="polite"></span>
            <div class="dh-bulk-actions">
                @can('dhiran.reports')
                <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm" data-bulk-export data-turbo="false" download>Export CSV</a>
                @endcan
                <a href="{{ route('dhiran.tags.bulk') }}" class="btn btn-secondary btn-sm" data-bulk-tags target="_blank" rel="noopener">Print item tags</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bulk-clear>Clear</button>
            </div>
        </div>
    </div>
</x-dhiran-layout>
