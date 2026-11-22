@php
    $f = $filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request());
    $rupee = fn ($n) => '₹' . \App\Support\IndianNumber::format($n, 2);
@endphp
<x-dhiran-layout title="Loans">
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

        {{-- Loans: a table from 700px of list width, one card per loan below that. --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Loan records">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Loan #</th>
                            <th scope="col">Customer</th>
                            <th scope="col" data-low="2">Mobile</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col" class="text-right">Outstanding</th>
                            <th scope="col" class="text-center" data-low="1">Rate</th>
                            <th scope="col" class="text-center">Status</th>
                            <th scope="col">Maturity</th>
                            <th scope="col" class="text-center" data-low="2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans ?? [] as $loan)
                            <tr>
                                <x-dhiran.sno :rows="$loans ?? null" :n="$loop->iteration" />
                                <td data-label="Loan #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-[var(--kh-ink-2)]">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="font-medium text-slate-700">{{ $loan->customer->name ?? '---' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Principal" class="whitespace-nowrap text-right">{{ $rupee($loan->principal_amount) }}</td>
                                <td data-label="Outstanding" class="whitespace-nowrap text-right font-semibold text-slate-800">{{ $rupee($loan->total_outstanding) }}</td>
                                <td data-label="Rate" data-low="1" class="whitespace-nowrap text-center">{{ $loan->interest_rate_monthly }}%/mo</td>
                                <td data-label="Status" data-cell="status" class="text-center">
                                    {{-- The "upload proof" hint is the badge's tooltip, not a second line under it (one value per cell). --}}
                                    <x-dhiran.status :value="$loan->status" :title="$loan->status === 'pending_evidence' ? 'Upload the required proof to activate this loan' : null" />
                                </td>
                                <td data-label="Maturity" class="whitespace-nowrap">{{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}</td>
                                <td data-label="Actions" data-cell="hide" data-low="2" class="text-center">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="jf-row-action jf-row-action--view" title="View Loan"><x-icon name="eye" size="16" /><span class="dh-act-label">View loan<span class="jf-sr-only"> {{ $loan->loan_number }}</span></span></a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
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
</x-dhiran-layout>
