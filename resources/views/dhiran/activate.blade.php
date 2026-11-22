<x-dhiran-layout title="Activate Dhiran">
    @vite('resources/css/dhiran-detail.css')
    <x-dhiran.page-header>
        <div class="min-w-0">
            <h1 class="page-title">Dhiran (Pledge Loans)</h1>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dhiran-activate-root dd-activate">
        <section class="dd-card dd-activate-card" aria-labelledby="dd-act-t">
            <div class="dd-activate-body">
                <div>
                    <h2 id="dd-act-t" class="dd-activate-title">Start your pledge-loan book</h2>
                    <p class="dd-lead">Keep every gold and silver pledge, interest payment, repayment and release for your shop in one place, with a printed receipt for each customer.</p>
                </div>

                <div>
                    <h3 class="dd-field-label">What happens next</h3>
                    <ol class="dd-steps">
                        <li><span><strong>Dhiran turns on for your shop</strong>Nothing is charged.</span></li>
                        <li><span><strong>You land on the Loans list</strong>It is empty until you add your first customer and loan.</span></li>
                        <li><span><strong>Set your rates and receipt in Settings</strong>Interest rate, lock period and what prints on receipts can be changed at any time.</span></li>
                    </ol>
                </div>

                @can('dhiran.settings')
                <form method="POST" action="{{ route('dhiran.activate') }}" data-turbo-frame="_top">
                    @csrf
                    <button type="submit" class="btn btn-primary" data-turbo-submits-with="Turning on…">Activate Dhiran Module</button>
                </form>
                @else
                <p class="dd-activate-note">{{ \App\Support\NotAvailable::MESSAGE }}</p>
                @endcan
            </div>
        </section>
    </div>
</x-dhiran-layout>
