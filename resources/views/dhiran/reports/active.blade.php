<x-dhiran-layout title="Active Loans Report">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Active Loans Report</h1>
            <p class="text-sm text-gray-500 mt-1">All currently active pledge loans</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <span class="header-badge">{{ $totals['count'] }} Active Loans</span>
            <x-dhiran.btn-collapse icon="printer" label="Print" onclick="window.print()" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-bar :filters="$filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request())" :action="route('dhiran.reports.active')" period-label="Loan date"
            :fields="['search', 'period', 'metal', 'amount', 'maturing']" />
        <turbo-frame id="dh-results" target="_top">
        {{-- Summary Cards --}}
        <div class="dh-stats">
            <x-dhiran.stat label="Total Active" :value="$totals['count']" total="count" />
            <x-dhiran.stat money label="Total Principal" :value="\App\Support\IndianNumber::format($totals['principal'], 2)" total="principal" />
            <x-dhiran.stat money label="Total Outstanding" :value="\App\Support\IndianNumber::format($totals['outstanding'], 2)" total="outstanding" />
        </div>

        {{-- Table --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Active loan records">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Loan #</th>
                            <th scope="col">Customer</th>
                            <th scope="col" data-low="2">Mobile</th>
                            <th scope="col" data-low="1">Loan Date</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col" class="text-right">Outstanding</th>
                            <th scope="col" class="text-right" data-low="1">Interest Rate</th>
                            <th scope="col">Maturity Date</th>
                            <th scope="col" class="text-center">Days to Maturity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans as $loan)
                            <tr>
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Loan #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-[var(--kh-ink-2)]">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="font-medium text-slate-700">{{ $loan->customer?->name ?? '-' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Loan Date" data-low="1" class="whitespace-nowrap">{{ $loan->loan_date?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Principal" class="whitespace-nowrap text-right">{{ \App\Support\IndianNumber::rupee((float) $loan->principal_amount, 2) }}</td>
                                <td data-label="Outstanding" class="whitespace-nowrap text-right font-semibold {{ $loan->totalOutstanding() > 0 ? 'text-red-600' : 'text-green-600' }}">{{ \App\Support\IndianNumber::rupee($loan->totalOutstanding(), 2) }}</td>
                                <td data-label="Interest Rate" data-low="1" class="whitespace-nowrap text-right">{{ number_format((float) $loan->interest_rate_monthly, 2) }}%/mo</td>
                                <td data-label="Maturity Date" class="whitespace-nowrap">{{ $loan->maturity_date?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Days to Maturity" data-cell="status" class="whitespace-nowrap text-center">
                                    @if($loan->isOverdue())
                                        <span class="dh-status dh-status--danger">Overdue</span>
                                    @elseif($loan->daysTillMaturity() <= 30)
                                        <span class="dh-status dh-status--warning">{{ $loan->daysTillMaturity() }}d</span>
                                    @else
                                        <span class="dh-status dh-status--muted">{{ $loan->daysTillMaturity() }}d</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
                                    <x-dhiran.empty icon="search" title="No active loans found" text="Nothing fits the search or filters you chose. Widen the date range or clear the filters.">
                                        <a class="btn btn-secondary btn-sm" href="{{ url()->current() }}">Clear filters</a>
                                    </x-dhiran.empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="loans" />
        </div>
        </turbo-frame>
    </div>
</x-dhiran-layout>
