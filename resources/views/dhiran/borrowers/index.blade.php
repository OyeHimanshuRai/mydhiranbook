@php($rupee = fn ($n) => '₹' . \App\Support\IndianNumber::format($n, 2))
<x-dhiran-layout title="Customers" list>
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Customers</h1>
            <p class="text-sm text-gray-500 mt-1">Everyone your shop has saved, with or without a loan</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            @can('dhiran.create')
            <x-dhiran.btn-collapse icon="user-plus" label="Add Customer" :href="route('dhiran.borrowers.create')" />
            @endcan
            <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" size="14" class="mr-1" />
                New Loan
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        {{-- Search: live, outside the results frame so typing never leaves the box --}}
        <div class="dh-fbar">
            <form method="GET" action="{{ route('dhiran.borrowers.index') }}">
                @if($show ?? null)<input type="hidden" name="show" value="{{ $show }}">@endif
                <div class="dh-fbar-row">
                    <x-dhiran.search :value="request('search', '')" placeholder="Search name, mobile, or code" label="Search customers" />
                </div>
            </form>
        </div>

        <turbo-frame id="dh-results" target="_top">
        {{-- Where their loans stand --}}
        <nav class="dh-tabs" aria-label="Filter customers">
            @foreach(['' => 'All', 'active' => 'Active loan', 'paid' => 'Fully paid', 'none' => 'No loan yet'] as $key => $tabLabel)
                <a href="{{ route('dhiran.borrowers.index', array_filter(['show' => $key, 'search' => request('search')])) }}"
                   @if(($show ?? '') === $key) aria-current="page" @endif class="dh-tab">
                    {{ $tabLabel }} <span class="dh-tab-count">{{ $tabCounts[$key === '' ? 'all' : $key] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>

        @forelse($borrowers as $borrower)
            @if($loop->first)
                <div class="dh-table-card" data-list-frame>
                    <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Customer records">
                        <table class="jf-table dh-stack">
                            <thead>
                                <tr>
                                    <th scope="col" class="dh-sno-th" data-low="1">#</th>
                                    <th scope="col">Customer</th>
                                    <th scope="col" data-low="1">Code</th>
                                    <th scope="col">Loan state</th>
                                    <th scope="col" data-low="2">Mobile</th>
                                    <th scope="col" class="text-right">Active</th>
                                    <th scope="col" class="text-right" data-low="2">Closed</th>
                                    <th scope="col" class="text-right" data-low="1">Forfeited</th>
                                    <th scope="col" class="text-right">Outstanding</th>
                                    <th scope="col" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
            @endif
                                <tr>
                                    <x-dhiran.sno :rows="$borrowers" :n="$loop->iteration" />
                                    {{-- One value per column (owner 2026-11-11): name, code and loan state are not stacked. --}}
                                    <td data-label="Customer" data-cell="title">
                                        <span class="inline-flex items-center gap-3">
                                            <x-customer-avatar :customer="$borrower" />
                                            <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="font-medium text-slate-800 hover:text-[var(--kh-ink-2)]">{{ $borrower->name }}</a>
                                        </span>
                                    </td>
                                    <td data-label="Code" data-low="1" class="font-mono">{{ $borrower->customer_code ?: '—' }}</td>
                                    <td data-label="Loan state">
                                        @if(($borrower->pending_evidence_count ?? 0) > 0)
                                            <span class="dh-status dh-status--warning">{{ $borrower->pending_evidence_count }} awaiting evidence</span>
                                        @elseif(($borrower->active_loans_count ?? 0) > 0)
                                            <span class="dh-status dh-status--success">Active loan</span>
                                        @elseif(($borrower->loans_count ?? 0) === 0)
                                            <span class="dh-status dh-status--muted">No loan yet</span>
                                        @else
                                            <span class="dh-status dh-status--muted">No open loan</span>
                                        @endif
                                    </td>
                                    <x-dhiran.mobile-cell :number="$borrower->mobile" />
                                    <td data-label="Active" class="text-right font-medium">{{ $borrower->active_loans_count }}</td>
                                    <td data-label="Closed" data-low="2" class="text-right text-slate-500">{{ $borrower->closed_loans_count }}</td>
                                    <td data-label="Forfeited" data-low="1" class="text-right">{{ $borrower->forfeited_loans_count }}</td>
                                    <td data-label="Outstanding" class="whitespace-nowrap text-right font-semibold text-slate-800">{{ $rupee($outstanding[$borrower->id] ?? 0) }}</td>
                                    <td data-label="Actions" data-cell="actions" class="whitespace-nowrap text-center">
                                        <a href="{{ route('dhiran.borrowers.show', $borrower) }}" class="jf-row-action jf-row-action--view dh-act-extra" title="Profile"><x-icon name="eye" size="16" /><span class="dh-act-label">Profile</span></a>
                                        <a href="{{ route('dhiran.create', ['customer_id' => $borrower->id]) }}" class="btn btn-secondary btn-sm ml-2 align-middle">New Loan</a>
                                    </td>
                                </tr>
            @if($loop->last)
                            </tbody>
                        </table>
                    </div>

                    <x-dhiran.pager :paginator="$borrowers" noun="customers" />
                </div>
            @endif
        @empty
            <div class="dh-table-card">
                @if(request('search'))
                    <x-dhiran.empty icon="search" title="No customers match your search" text="Try a different name, mobile, or code.">
                        <a href="{{ route('dhiran.borrowers.index', array_filter(['show' => $show ?? null])) }}" class="btn btn-secondary btn-sm">Clear search</a>
                    </x-dhiran.empty>
                @elseif($show ?? null)
                    <x-dhiran.empty icon="users" title="No customers here" text="Try another tab.">
                        <a href="{{ route('dhiran.borrowers.index') }}" class="btn btn-secondary btn-sm">Show all customers</a>
                    </x-dhiran.empty>
                @else
                    <x-dhiran.empty icon="users" title="No customers yet" text="Add a customer, or create their first pledge loan.">
                        <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm">Create your first pledge loan</a>
                    </x-dhiran.empty>
                @endif
            </div>
        @endforelse
        </turbo-frame>
    </div>
</x-dhiran-layout>
