# Read-only Dhiran revision audit

Initial requested baseline: `2b9dfc2c860d2ff570c54ecd278be19cda147577`. Original repositories and worktrees were inspected read-only; no checkout, index refresh, fetch or service command was run there.

## 018b3d810e37d534f498033ab582ee41f3197c27
- /home/himanshu/Desktop/JewelFlow

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php          |  554 ++++-------
 app/Services/DhiranService.php                     |  426 +++-----
 resources/css/dhiran-documents.css                 |  258 +++--
 resources/css/dhiran-tokens.css                    |   92 --
 resources/css/dhiran.css                           | 1020 ++++++--------------
 resources/views/components/dhiran-layout.blade.php |   64 +-
 resources/views/dhiran/activate.blade.php          |   18 +-
 resources/views/dhiran/borrowers/create.blade.php  |   47 -
 resources/views/dhiran/borrowers/index.blade.php   |  111 +--
 resources/views/dhiran/borrowers/show.blade.php    |  206 ++--
 resources/views/dhiran/create.blade.php            |  167 +---
 resources/views/dhiran/customer-loans.blade.php    |   59 +-
 resources/views/dhiran/dashboard.blade.php         |  164 ++--
 resources/views/dhiran/forfeiture-notice.blade.php |    4 +-
 resources/views/dhiran/items/show.blade.php        |   48 +-
 resources/views/dhiran/loans.blade.php             |  162 +++-
 resources/views/dhiran/old-loans/create.blade.php  |  162 ----
 resources/views/dhiran/old-loans/index.blade.php   |   75 --
 resources/views/dhiran/old-loans/preview.blade.php |   72 --
 resources/views/dhiran/old-loans/upload.blade.php  |   46 -
 resources/views/dhiran/onboarding/plans.blade.php  |   14 +-
 resources/views/dhiran/payment-receipt.blade.php   |    2 +-
 resources/views/dhiran/receipt.blade.php           |    2 +-
 resources/views/dhiran/reports/active.blade.php    |   54 +-
 resources/views/dhiran/reports/cashbook.blade.php  |  122 ++-
 .../views/dhiran/reports/forfeiture.blade.php      |   47 +-
 resources/views/dhiran/reports/index.blade.php     |   30 +-
 resources/views/dhiran/reports/interest.blade.php  |   90 +-
 resources/views/dhiran/reports/overdue.blade.php   |   48 +-
 .../views/dhiran/reports/profitability.blade.php   |   64 +-
 resources/views/dhiran/settings.blade.php          |  299 ++++--
 resources/views/dhiran/show.blade.php              |  255 ++---
 32 files changed, 1784 insertions(+), 2998 deletions(-)

```

New Loan changes:
```diff
diff --git a/resources/views/dhiran/create.blade.php b/resources/views/dhiran/create.blade.php
index 0a4c7767..00e6feab 100755
--- a/resources/views/dhiran/create.blade.php
+++ b/resources/views/dhiran/create.blade.php
@@ -7,5 +7,5 @@
         <div class="page-actions">
             <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                <x-icon name="arrow-left" size="14" class="mr-1" />
+                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                 Back to Loans
             </a>
@@ -19,10 +19,10 @@
 
         <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
-            @csrf <x-dhiran.once />
+            @csrf
 
             {{-- Customer Section --}}
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="user" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                     Customer Details
                 </div>
@@ -32,11 +32,11 @@
                         <div class="dh-customer-search-box">
                             <label class="dh-label">
-                                <x-icon name="user" />
+                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                 Customer *
                             </label>
-                            <input type="hidden" name="customer_id" :value="customer_id">
+                            <input type="hidden" name="customer_id" x-model="customer_id">
                             <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                 <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
-                                    <span class="dh-customer-search-icon"><x-icon name="search" size="15" /></span>
+                                    <span class="dh-customer-search-icon"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                     <input type="search"
                                            class="dh-customer-search-input"
@@ -66,5 +66,5 @@ class="dh-customer-search-input"
 
                         <button type="button" class="btn btn-dark btn-sm dh-customer-add-btn" @click="openNewBorrower()" aria-label="Add new borrower" title="Add new borrower">
-                            <x-icon name="plus" size="15" />
+                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             <span>Add New Borrower</span>
                         </button>
@@ -125,7 +125,7 @@ class="dh-customer-search-input"
                  x-cloak
                  x-transition.opacity
-                 @keydown.escape.window="closeNewBorrower()"
+                 @keydown.escape.window="newBorrowerOpen = false"
                  @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
-                 @click.self="closeNewBorrower()"
+                 @click.self="newBorrowerOpen = false"
                  @wheel.self.prevent
                  @touchmove.self.prevent
@@ -139,6 +139,6 @@ class="dh-customer-search-input"
                             <p>Create a borrower profile and select it for this pledge loan.</p>
                         </div>
-                        <button type="button" class="dh-borrower-modal-close" @click="closeNewBorrower()" aria-label="Close borrower form">
-                            <x-icon name="x" />
+                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
+                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                         </button>
                     </div>
@@ -179,5 +179,5 @@ class="dh-customer-search-input"
 
                     <div class="dh-borrower-modal-foot">
-                        <button type="button" class="btn btn-secondary btn-sm" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
+                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                         <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                             <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
@@ -190,5 +190,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="package" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                     Pledged Items
                 </div>
@@ -197,5 +197,5 @@ class="dh-customer-search-input"
                     <div class="dh-item-row">
                         <button type="button" class="dh-item-remove" @click="removeItem(index)" x-show="items.length > 1">
-                            <x-icon name="x" />
+                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                         </button>
 
@@ -244,6 +244,5 @@ class="dh-customer-search-input"
                                         <option value="">Select</option>
                                         <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
-                                            {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
-                                            <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
+                                            <option :value="opt.value" x-text="opt.label"></option>
                                         </template>
                                     </select>
@@ -291,10 +290,10 @@ class="dh-customer-search-input"
                                 </div>
                                 <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.market_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                             </div>
                             <div>
                                 <label class="dh-label">Loan Value</label>
                                 <input type="text" readonly class="dh-input dh-calc-field"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.loan_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                 <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                             </div>
@@ -310,5 +309,5 @@ class="dh-customer-search-input"
 
                 <button type="button" class="dh-add-item-btn" @click="addItem()">
-                    <x-icon name="plus" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                     Add Item
                 </button>
@@ -318,5 +317,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="calculator" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                     Loan Parameters
                 </div>
@@ -330,8 +329,6 @@ class="dh-customer-search-input"
                                    x-model.number="principal_amount"
                                    value="{{ old('principal_amount') }}"
-                                   placeholder="0.00" class="dh-input" required
-                                   :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit">
+                                   placeholder="0.00" class="dh-input" required>
                         </div>
-                        <p id="principal-limit" class="dh-principal-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                         @error('principal_amount')
                             <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
@@ -398,5 +395,5 @@ class="dh-customer-search-input"
                         <label class="dh-label">Processing Fee</label>
                         <input type="text" readonly class="dh-input dh-calc-field"
-                               :value="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)">
+                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                         <input type="hidden" name="processing_fee" :value="processingFee">
                     </div>
@@ -407,26 +404,26 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="badge-check" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                     Loan Summary
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Market Value</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalMarketValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Loan Value (from items)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalLoanValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row border-t border-slate-200 pt-2">
                     <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
-                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + money((principal_amount || 0))"></span>
+                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Monthly Interest (approx.)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(monthlyInterest)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Processing Fee</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                 </div>
             </div>
@@ -435,5 +432,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <label for="notes" class="dh-label">
-                    <x-icon name="file-text" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                     Notes (optional)
                 </label>
@@ -444,9 +441,9 @@ class="dh-customer-search-input"
             <div class="flex justify-end gap-3 pb-6">
                 <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                    <x-icon name="x" size="14" class="mr-1" />
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                     Cancel
                 </a>
-                <button type="submit" class="btn btn-primary btn-sm">
-                    <x-icon name="save" size="14" class="mr-1" />
+                <button type="submit" class="btn btn-dark btn-sm">
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                     Create Loan
                 </button>
@@ -457,13 +454,7 @@ class="dh-customer-search-input"
     @push('scripts')
     <script>
-        (() => {
-        const registerDhiranCreateForm = () => {
+        document.addEventListener('alpine:init', () => {
             Alpine.data('dhiranCreateForm', () => ({
-                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
-                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
-                initialCustomerId: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
-                get customer_id() {
-                    return this.selectedCustomer ? this.selectedCustomer.id : '';
-                },
+                customer_id: '{{ old('custome
```

## d7dcfb0367fa6ffd3571502d25c44a7bdb36aedf
- /home/himanshu/Desktop/JewelFlow/.claude/worktrees/eager-boyd-c1ed92
- /home/himanshu/Desktop/JewelFlow/.claude/worktrees/goofy-curran-78541f
- /home/himanshu/Desktop/jewelflow-worktrees/icons-baseline
- /home/himanshu/Desktop/jewelflow-worktrees/rehearsal-d7dcfb0

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php          |  553 ++++-------
 app/Services/DhiranService.php                     |  426 +++-----
 resources/css/dhiran-documents.css                 |  258 +++--
 resources/css/dhiran-tokens.css                    |   92 --
 resources/css/dhiran.css                           | 1020 ++++++--------------
 resources/views/components/dhiran-layout.blade.php |   61 +-
 resources/views/dhiran/activate.blade.php          |   18 +-
 resources/views/dhiran/borrowers/create.blade.php  |   47 -
 resources/views/dhiran/borrowers/index.blade.php   |  118 +--
 resources/views/dhiran/borrowers/show.blade.php    |  205 ++--
 resources/views/dhiran/create.blade.php            |  167 +---
 resources/views/dhiran/customer-loans.blade.php    |   59 +-
 resources/views/dhiran/dashboard.blade.php         |  148 +--
 resources/views/dhiran/forfeiture-notice.blade.php |    4 +-
 resources/views/dhiran/items/show.blade.php        |   48 +-
 resources/views/dhiran/loans.blade.php             |  162 +++-
 resources/views/dhiran/old-loans/create.blade.php  |  162 ----
 resources/views/dhiran/old-loans/index.blade.php   |   75 --
 resources/views/dhiran/old-loans/preview.blade.php |   72 --
 resources/views/dhiran/old-loans/upload.blade.php  |   46 -
 resources/views/dhiran/onboarding/plans.blade.php  |   14 +-
 resources/views/dhiran/payment-receipt.blade.php   |    2 +-
 resources/views/dhiran/receipt.blade.php           |    2 +-
 resources/views/dhiran/reports/active.blade.php    |   54 +-
 resources/views/dhiran/reports/cashbook.blade.php  |  122 ++-
 .../views/dhiran/reports/forfeiture.blade.php      |   47 +-
 resources/views/dhiran/reports/index.blade.php     |   30 +-
 resources/views/dhiran/reports/interest.blade.php  |   90 +-
 resources/views/dhiran/reports/overdue.blade.php   |   48 +-
 .../views/dhiran/reports/profitability.blade.php   |   64 +-
 resources/views/dhiran/settings.blade.php          |  296 ++++--
 resources/views/dhiran/show.blade.php              |  255 ++---
 32 files changed, 1778 insertions(+), 2987 deletions(-)

```

New Loan changes:
```diff
diff --git a/resources/views/dhiran/create.blade.php b/resources/views/dhiran/create.blade.php
index 0a4c7767..00e6feab 100755
--- a/resources/views/dhiran/create.blade.php
+++ b/resources/views/dhiran/create.blade.php
@@ -7,5 +7,5 @@
         <div class="page-actions">
             <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                <x-icon name="arrow-left" size="14" class="mr-1" />
+                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                 Back to Loans
             </a>
@@ -19,10 +19,10 @@
 
         <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
-            @csrf <x-dhiran.once />
+            @csrf
 
             {{-- Customer Section --}}
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="user" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                     Customer Details
                 </div>
@@ -32,11 +32,11 @@
                         <div class="dh-customer-search-box">
                             <label class="dh-label">
-                                <x-icon name="user" />
+                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                 Customer *
                             </label>
-                            <input type="hidden" name="customer_id" :value="customer_id">
+                            <input type="hidden" name="customer_id" x-model="customer_id">
                             <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                 <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
-                                    <span class="dh-customer-search-icon"><x-icon name="search" size="15" /></span>
+                                    <span class="dh-customer-search-icon"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                     <input type="search"
                                            class="dh-customer-search-input"
@@ -66,5 +66,5 @@ class="dh-customer-search-input"
 
                         <button type="button" class="btn btn-dark btn-sm dh-customer-add-btn" @click="openNewBorrower()" aria-label="Add new borrower" title="Add new borrower">
-                            <x-icon name="plus" size="15" />
+                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             <span>Add New Borrower</span>
                         </button>
@@ -125,7 +125,7 @@ class="dh-customer-search-input"
                  x-cloak
                  x-transition.opacity
-                 @keydown.escape.window="closeNewBorrower()"
+                 @keydown.escape.window="newBorrowerOpen = false"
                  @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
-                 @click.self="closeNewBorrower()"
+                 @click.self="newBorrowerOpen = false"
                  @wheel.self.prevent
                  @touchmove.self.prevent
@@ -139,6 +139,6 @@ class="dh-customer-search-input"
                             <p>Create a borrower profile and select it for this pledge loan.</p>
                         </div>
-                        <button type="button" class="dh-borrower-modal-close" @click="closeNewBorrower()" aria-label="Close borrower form">
-                            <x-icon name="x" />
+                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
+                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                         </button>
                     </div>
@@ -179,5 +179,5 @@ class="dh-customer-search-input"
 
                     <div class="dh-borrower-modal-foot">
-                        <button type="button" class="btn btn-secondary btn-sm" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
+                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                         <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                             <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
@@ -190,5 +190,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="package" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                     Pledged Items
                 </div>
@@ -197,5 +197,5 @@ class="dh-customer-search-input"
                     <div class="dh-item-row">
                         <button type="button" class="dh-item-remove" @click="removeItem(index)" x-show="items.length > 1">
-                            <x-icon name="x" />
+                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                         </button>
 
@@ -244,6 +244,5 @@ class="dh-customer-search-input"
                                         <option value="">Select</option>
                                         <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
-                                            {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
-                                            <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
+                                            <option :value="opt.value" x-text="opt.label"></option>
                                         </template>
                                     </select>
@@ -291,10 +290,10 @@ class="dh-customer-search-input"
                                 </div>
                                 <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.market_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                             </div>
                             <div>
                                 <label class="dh-label">Loan Value</label>
                                 <input type="text" readonly class="dh-input dh-calc-field"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.loan_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                 <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                             </div>
@@ -310,5 +309,5 @@ class="dh-customer-search-input"
 
                 <button type="button" class="dh-add-item-btn" @click="addItem()">
-                    <x-icon name="plus" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                     Add Item
                 </button>
@@ -318,5 +317,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="calculator" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                     Loan Parameters
                 </div>
@@ -330,8 +329,6 @@ class="dh-customer-search-input"
                                    x-model.number="principal_amount"
                                    value="{{ old('principal_amount') }}"
-                                   placeholder="0.00" class="dh-input" required
-                                   :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit">
+                                   placeholder="0.00" class="dh-input" required>
                         </div>
-                        <p id="principal-limit" class="dh-principal-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                         @error('principal_amount')
                             <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
@@ -398,5 +395,5 @@ class="dh-customer-search-input"
                         <label class="dh-label">Processing Fee</label>
                         <input type="text" readonly class="dh-input dh-calc-field"
-                               :value="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)">
+                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                         <input type="hidden" name="processing_fee" :value="processingFee">
                     </div>
@@ -407,26 +404,26 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="badge-check" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                     Loan Summary
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Market Value</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalMarketValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Loan Value (from items)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalLoanValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row border-t border-slate-200 pt-2">
                     <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
-                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + money((principal_amount || 0))"></span>
+                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Monthly Interest (approx.)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(monthlyInterest)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Processing Fee</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                 </div>
             </div>
@@ -435,5 +432,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <label for="notes" class="dh-label">
-                    <x-icon name="file-text" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                     Notes (optional)
                 </label>
@@ -444,9 +441,9 @@ class="dh-customer-search-input"
             <div class="flex justify-end gap-3 pb-6">
                 <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                    <x-icon name="x" size="14" class="mr-1" />
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                     Cancel
                 </a>
-                <button type="submit" class="btn btn-primary btn-sm">
-                    <x-icon name="save" size="14" class="mr-1" />
+                <button type="submit" class="btn btn-dark btn-sm">
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                     Create Loan
                 </button>
@@ -457,13 +454,7 @@ class="dh-customer-search-input"
     @push('scripts')
     <script>
-        (() => {
-        const registerDhiranCreateForm = () => {
+        document.addEventListener('alpine:init', () => {
             Alpine.data('dhiranCreateForm', () => ({
-                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
-                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
-                initialCustomerId: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
-                get customer_id() {
-                    return this.selectedCustomer ? this.selectedCustomer.id : '';
-                },
+                customer_id: '{{ old('custome
```

## a8b92c39d37d2423a55913306cd99c094066bd9b
- /home/himanshu/Desktop/JewelFlow/.claude/worktrees/practical-albattani-8ebe11

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php          |  553 ++++-------
 app/Services/DhiranService.php                     |  426 +++-----
 resources/css/dhiran-documents.css                 |  258 +++--
 resources/css/dhiran-tokens.css                    |   92 --
 resources/css/dhiran.css                           | 1020 ++++++--------------
 resources/views/components/dhiran-layout.blade.php |   61 +-
 resources/views/dhiran/activate.blade.php          |   18 +-
 resources/views/dhiran/borrowers/create.blade.php  |   47 -
 resources/views/dhiran/borrowers/index.blade.php   |  118 +--
 resources/views/dhiran/borrowers/show.blade.php    |  205 ++--
 resources/views/dhiran/create.blade.php            |  167 +---
 resources/views/dhiran/customer-loans.blade.php    |   59 +-
 resources/views/dhiran/dashboard.blade.php         |  148 +--
 resources/views/dhiran/forfeiture-notice.blade.php |    4 +-
 resources/views/dhiran/items/show.blade.php        |   48 +-
 resources/views/dhiran/loans.blade.php             |  162 +++-
 resources/views/dhiran/old-loans/create.blade.php  |  162 ----
 resources/views/dhiran/old-loans/index.blade.php   |   75 --
 resources/views/dhiran/old-loans/preview.blade.php |   72 --
 resources/views/dhiran/old-loans/upload.blade.php  |   46 -
 resources/views/dhiran/onboarding/plans.blade.php  |   14 +-
 resources/views/dhiran/payment-receipt.blade.php   |    2 +-
 resources/views/dhiran/receipt.blade.php           |    2 +-
 resources/views/dhiran/reports/active.blade.php    |   54 +-
 resources/views/dhiran/reports/cashbook.blade.php  |  122 ++-
 .../views/dhiran/reports/forfeiture.blade.php      |   47 +-
 resources/views/dhiran/reports/index.blade.php     |   30 +-
 resources/views/dhiran/reports/interest.blade.php  |   90 +-
 resources/views/dhiran/reports/overdue.blade.php   |   48 +-
 .../views/dhiran/reports/profitability.blade.php   |   64 +-
 resources/views/dhiran/settings.blade.php          |  296 ++++--
 resources/views/dhiran/show.blade.php              |  255 ++---
 32 files changed, 1778 insertions(+), 2987 deletions(-)

```

New Loan changes:
```diff
diff --git a/resources/views/dhiran/create.blade.php b/resources/views/dhiran/create.blade.php
index 0a4c7767..00e6feab 100755
--- a/resources/views/dhiran/create.blade.php
+++ b/resources/views/dhiran/create.blade.php
@@ -7,5 +7,5 @@
         <div class="page-actions">
             <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                <x-icon name="arrow-left" size="14" class="mr-1" />
+                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                 Back to Loans
             </a>
@@ -19,10 +19,10 @@
 
         <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
-            @csrf <x-dhiran.once />
+            @csrf
 
             {{-- Customer Section --}}
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="user" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                     Customer Details
                 </div>
@@ -32,11 +32,11 @@
                         <div class="dh-customer-search-box">
                             <label class="dh-label">
-                                <x-icon name="user" />
+                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                 Customer *
                             </label>
-                            <input type="hidden" name="customer_id" :value="customer_id">
+                            <input type="hidden" name="customer_id" x-model="customer_id">
                             <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                 <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
-                                    <span class="dh-customer-search-icon"><x-icon name="search" size="15" /></span>
+                                    <span class="dh-customer-search-icon"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                     <input type="search"
                                            class="dh-customer-search-input"
@@ -66,5 +66,5 @@ class="dh-customer-search-input"
 
                         <button type="button" class="btn btn-dark btn-sm dh-customer-add-btn" @click="openNewBorrower()" aria-label="Add new borrower" title="Add new borrower">
-                            <x-icon name="plus" size="15" />
+                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             <span>Add New Borrower</span>
                         </button>
@@ -125,7 +125,7 @@ class="dh-customer-search-input"
                  x-cloak
                  x-transition.opacity
-                 @keydown.escape.window="closeNewBorrower()"
+                 @keydown.escape.window="newBorrowerOpen = false"
                  @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
-                 @click.self="closeNewBorrower()"
+                 @click.self="newBorrowerOpen = false"
                  @wheel.self.prevent
                  @touchmove.self.prevent
@@ -139,6 +139,6 @@ class="dh-customer-search-input"
                             <p>Create a borrower profile and select it for this pledge loan.</p>
                         </div>
-                        <button type="button" class="dh-borrower-modal-close" @click="closeNewBorrower()" aria-label="Close borrower form">
-                            <x-icon name="x" />
+                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
+                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                         </button>
                     </div>
@@ -179,5 +179,5 @@ class="dh-customer-search-input"
 
                     <div class="dh-borrower-modal-foot">
-                        <button type="button" class="btn btn-secondary btn-sm" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
+                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                         <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                             <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
@@ -190,5 +190,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="package" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                     Pledged Items
                 </div>
@@ -197,5 +197,5 @@ class="dh-customer-search-input"
                     <div class="dh-item-row">
                         <button type="button" class="dh-item-remove" @click="removeItem(index)" x-show="items.length > 1">
-                            <x-icon name="x" />
+                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                         </button>
 
@@ -244,6 +244,5 @@ class="dh-customer-search-input"
                                         <option value="">Select</option>
                                         <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
-                                            {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
-                                            <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
+                                            <option :value="opt.value" x-text="opt.label"></option>
                                         </template>
                                     </select>
@@ -291,10 +290,10 @@ class="dh-customer-search-input"
                                 </div>
                                 <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.market_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                             </div>
                             <div>
                                 <label class="dh-label">Loan Value</label>
                                 <input type="text" readonly class="dh-input dh-calc-field"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.loan_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                 <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                             </div>
@@ -310,5 +309,5 @@ class="dh-customer-search-input"
 
                 <button type="button" class="dh-add-item-btn" @click="addItem()">
-                    <x-icon name="plus" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                     Add Item
                 </button>
@@ -318,5 +317,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="calculator" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                     Loan Parameters
                 </div>
@@ -330,8 +329,6 @@ class="dh-customer-search-input"
                                    x-model.number="principal_amount"
                                    value="{{ old('principal_amount') }}"
-                                   placeholder="0.00" class="dh-input" required
-                                   :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit">
+                                   placeholder="0.00" class="dh-input" required>
                         </div>
-                        <p id="principal-limit" class="dh-principal-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                         @error('principal_amount')
                             <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
@@ -398,5 +395,5 @@ class="dh-customer-search-input"
                         <label class="dh-label">Processing Fee</label>
                         <input type="text" readonly class="dh-input dh-calc-field"
-                               :value="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)">
+                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                         <input type="hidden" name="processing_fee" :value="processingFee">
                     </div>
@@ -407,26 +404,26 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="badge-check" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                     Loan Summary
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Market Value</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalMarketValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Loan Value (from items)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalLoanValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row border-t border-slate-200 pt-2">
                     <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
-                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + money((principal_amount || 0))"></span>
+                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Monthly Interest (approx.)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(monthlyInterest)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Processing Fee</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                 </div>
             </div>
@@ -435,5 +432,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <label for="notes" class="dh-label">
-                    <x-icon name="file-text" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                     Notes (optional)
                 </label>
@@ -444,9 +441,9 @@ class="dh-customer-search-input"
             <div class="flex justify-end gap-3 pb-6">
                 <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                    <x-icon name="x" size="14" class="mr-1" />
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                     Cancel
                 </a>
-                <button type="submit" class="btn btn-primary btn-sm">
-                    <x-icon name="save" size="14" class="mr-1" />
+                <button type="submit" class="btn btn-dark btn-sm">
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                     Create Loan
                 </button>
@@ -457,13 +454,7 @@ class="dh-customer-search-input"
     @push('scripts')
     <script>
-        (() => {
-        const registerDhiranCreateForm = () => {
+        document.addEventListener('alpine:init', () => {
             Alpine.data('dhiranCreateForm', () => ({
-                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
-                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
-                initialCustomerId: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
-                get customer_id() {
-                    return this.selectedCustomer ? this.selectedCustomer.id : '';
-                },
+                customer_id: '{{ old('custome
```

## 42c5e007326f0f5242882b0c30fa070bd3fd5691
- /home/himanshu/Desktop/JewelFlow/.claude/worktrees/sleepy-lamarr-531611

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php          |  553 ++++-------
 app/Services/DhiranService.php                     |  426 +++-----
 resources/css/dhiran-documents.css                 |  258 +++--
 resources/css/dhiran-tokens.css                    |   92 --
 resources/css/dhiran.css                           | 1020 ++++++--------------
 resources/views/components/dhiran-layout.blade.php |   61 +-
 resources/views/dhiran/activate.blade.php          |   18 +-
 resources/views/dhiran/borrowers/create.blade.php  |   47 -
 resources/views/dhiran/borrowers/index.blade.php   |  118 +--
 resources/views/dhiran/borrowers/show.blade.php    |  205 ++--
 resources/views/dhiran/create.blade.php            |  167 +---
 resources/views/dhiran/customer-loans.blade.php    |   59 +-
 resources/views/dhiran/dashboard.blade.php         |  148 +--
 resources/views/dhiran/forfeiture-notice.blade.php |    4 +-
 resources/views/dhiran/items/show.blade.php        |   48 +-
 resources/views/dhiran/loans.blade.php             |  162 +++-
 resources/views/dhiran/old-loans/create.blade.php  |  162 ----
 resources/views/dhiran/old-loans/index.blade.php   |   75 --
 resources/views/dhiran/old-loans/preview.blade.php |   72 --
 resources/views/dhiran/old-loans/upload.blade.php  |   46 -
 resources/views/dhiran/onboarding/plans.blade.php  |   14 +-
 resources/views/dhiran/payment-receipt.blade.php   |    2 +-
 resources/views/dhiran/receipt.blade.php           |    2 +-
 resources/views/dhiran/reports/active.blade.php    |   54 +-
 resources/views/dhiran/reports/cashbook.blade.php  |  122 ++-
 .../views/dhiran/reports/forfeiture.blade.php      |   47 +-
 resources/views/dhiran/reports/index.blade.php     |   30 +-
 resources/views/dhiran/reports/interest.blade.php  |   90 +-
 resources/views/dhiran/reports/overdue.blade.php   |   48 +-
 .../views/dhiran/reports/profitability.blade.php   |   64 +-
 resources/views/dhiran/settings.blade.php          |  296 ++++--
 resources/views/dhiran/show.blade.php              |  255 ++---
 32 files changed, 1778 insertions(+), 2987 deletions(-)

```

New Loan changes:
```diff
diff --git a/resources/views/dhiran/create.blade.php b/resources/views/dhiran/create.blade.php
index 0a4c7767..00e6feab 100755
--- a/resources/views/dhiran/create.blade.php
+++ b/resources/views/dhiran/create.blade.php
@@ -7,5 +7,5 @@
         <div class="page-actions">
             <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                <x-icon name="arrow-left" size="14" class="mr-1" />
+                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                 Back to Loans
             </a>
@@ -19,10 +19,10 @@
 
         <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
-            @csrf <x-dhiran.once />
+            @csrf
 
             {{-- Customer Section --}}
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="user" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                     Customer Details
                 </div>
@@ -32,11 +32,11 @@
                         <div class="dh-customer-search-box">
                             <label class="dh-label">
-                                <x-icon name="user" />
+                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                 Customer *
                             </label>
-                            <input type="hidden" name="customer_id" :value="customer_id">
+                            <input type="hidden" name="customer_id" x-model="customer_id">
                             <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                 <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
-                                    <span class="dh-customer-search-icon"><x-icon name="search" size="15" /></span>
+                                    <span class="dh-customer-search-icon"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                     <input type="search"
                                            class="dh-customer-search-input"
@@ -66,5 +66,5 @@ class="dh-customer-search-input"
 
                         <button type="button" class="btn btn-dark btn-sm dh-customer-add-btn" @click="openNewBorrower()" aria-label="Add new borrower" title="Add new borrower">
-                            <x-icon name="plus" size="15" />
+                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             <span>Add New Borrower</span>
                         </button>
@@ -125,7 +125,7 @@ class="dh-customer-search-input"
                  x-cloak
                  x-transition.opacity
-                 @keydown.escape.window="closeNewBorrower()"
+                 @keydown.escape.window="newBorrowerOpen = false"
                  @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
-                 @click.self="closeNewBorrower()"
+                 @click.self="newBorrowerOpen = false"
                  @wheel.self.prevent
                  @touchmove.self.prevent
@@ -139,6 +139,6 @@ class="dh-customer-search-input"
                             <p>Create a borrower profile and select it for this pledge loan.</p>
                         </div>
-                        <button type="button" class="dh-borrower-modal-close" @click="closeNewBorrower()" aria-label="Close borrower form">
-                            <x-icon name="x" />
+                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
+                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                         </button>
                     </div>
@@ -179,5 +179,5 @@ class="dh-customer-search-input"
 
                     <div class="dh-borrower-modal-foot">
-                        <button type="button" class="btn btn-secondary btn-sm" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
+                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                         <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                             <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
@@ -190,5 +190,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="package" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                     Pledged Items
                 </div>
@@ -197,5 +197,5 @@ class="dh-customer-search-input"
                     <div class="dh-item-row">
                         <button type="button" class="dh-item-remove" @click="removeItem(index)" x-show="items.length > 1">
-                            <x-icon name="x" />
+                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                         </button>
 
@@ -244,6 +244,5 @@ class="dh-customer-search-input"
                                         <option value="">Select</option>
                                         <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
-                                            {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
-                                            <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
+                                            <option :value="opt.value" x-text="opt.label"></option>
                                         </template>
                                     </select>
@@ -291,10 +290,10 @@ class="dh-customer-search-input"
                                 </div>
                                 <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.market_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                             </div>
                             <div>
                                 <label class="dh-label">Loan Value</label>
                                 <input type="text" readonly class="dh-input dh-calc-field"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.loan_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                 <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                             </div>
@@ -310,5 +309,5 @@ class="dh-customer-search-input"
 
                 <button type="button" class="dh-add-item-btn" @click="addItem()">
-                    <x-icon name="plus" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                     Add Item
                 </button>
@@ -318,5 +317,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="calculator" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                     Loan Parameters
                 </div>
@@ -330,8 +329,6 @@ class="dh-customer-search-input"
                                    x-model.number="principal_amount"
                                    value="{{ old('principal_amount') }}"
-                                   placeholder="0.00" class="dh-input" required
-                                   :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit">
+                                   placeholder="0.00" class="dh-input" required>
                         </div>
-                        <p id="principal-limit" class="dh-principal-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                         @error('principal_amount')
                             <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
@@ -398,5 +395,5 @@ class="dh-customer-search-input"
                         <label class="dh-label">Processing Fee</label>
                         <input type="text" readonly class="dh-input dh-calc-field"
-                               :value="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)">
+                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                         <input type="hidden" name="processing_fee" :value="processingFee">
                     </div>
@@ -407,26 +404,26 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="badge-check" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                     Loan Summary
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Market Value</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalMarketValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Loan Value (from items)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalLoanValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row border-t border-slate-200 pt-2">
                     <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
-                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + money((principal_amount || 0))"></span>
+                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Monthly Interest (approx.)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(monthlyInterest)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Processing Fee</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                 </div>
             </div>
@@ -435,5 +432,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <label for="notes" class="dh-label">
-                    <x-icon name="file-text" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                     Notes (optional)
                 </label>
@@ -444,9 +441,9 @@ class="dh-customer-search-input"
             <div class="flex justify-end gap-3 pb-6">
                 <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                    <x-icon name="x" size="14" class="mr-1" />
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                     Cancel
                 </a>
-                <button type="submit" class="btn btn-primary btn-sm">
-                    <x-icon name="save" size="14" class="mr-1" />
+                <button type="submit" class="btn btn-dark btn-sm">
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                     Create Loan
                 </button>
@@ -457,13 +454,7 @@ class="dh-customer-search-input"
     @push('scripts')
     <script>
-        (() => {
-        const registerDhiranCreateForm = () => {
+        document.addEventListener('alpine:init', () => {
             Alpine.data('dhiranCreateForm', () => ({
-                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
-                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
-                initialCustomerId: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
-                get customer_id() {
-                    return this.selectedCustomer ? this.selectedCustomer.id : '';
-                },
+                customer_id: '{{ old('custome
```

## 5d8ea6a2b386a1a0894f460c100ece5904d2fbaa
- /home/himanshu/Desktop/JewelFlow/.claude/worktrees/trusting-jang-e6c482

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php | 10 +++++-----
 1 file changed, 5 insertions(+), 5 deletions(-)

```

New Loan changes:
```diff
(no New Loan differences)
```

## c5c46e452c6f23a4dad80caacf4223806a54e3fe
- /home/himanshu/Desktop/jewelflow-worktrees/acceptance-c5c46e45

Screens/engine: identical for audited paths
```
(no differences)
```

New Loan changes:
```diff
(no New Loan differences)
```

## 2b9dfc2c860d2ff570c54ecd278be19cda147577
- /home/himanshu/Desktop/jewelflow-worktrees/acceptance-fixes
- /home/himanshu/Desktop/jewelflow-worktrees/repairs-list-fix

Screens/engine: identical for audited paths
```
(no differences)
```

New Loan changes:
```diff
(no New Loan differences)
```

## 090436daa875dca7e9c5ee8ef3a6666972dfa99e
- /home/himanshu/Desktop/jewelflow-worktrees/icons-batch1

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php          |  553 ++++-------
 app/Services/DhiranService.php                     |  426 +++-----
 resources/css/dhiran-documents.css                 |  258 +++--
 resources/css/dhiran-tokens.css                    |   92 --
 resources/css/dhiran.css                           | 1020 ++++++--------------
 resources/views/components/dhiran-layout.blade.php |   45 +-
 resources/views/dhiran/activate.blade.php          |    4 +-
 resources/views/dhiran/borrowers/create.blade.php  |   47 -
 resources/views/dhiran/borrowers/index.blade.php   |  116 +--
 resources/views/dhiran/borrowers/show.blade.php    |  201 ++--
 resources/views/dhiran/create.blade.php            |  139 +--
 resources/views/dhiran/customer-loans.blade.php    |   42 +-
 resources/views/dhiran/dashboard.blade.php         |  140 +--
 resources/views/dhiran/forfeiture-notice.blade.php |    4 +-
 resources/views/dhiran/items/show.blade.php        |   46 +-
 resources/views/dhiran/loans.blade.php             |  149 ++-
 resources/views/dhiran/old-loans/create.blade.php  |  162 ----
 resources/views/dhiran/old-loans/index.blade.php   |   75 --
 resources/views/dhiran/old-loans/preview.blade.php |   72 --
 resources/views/dhiran/old-loans/upload.blade.php  |   46 -
 resources/views/dhiran/onboarding/plans.blade.php  |    6 -
 resources/views/dhiran/payment-receipt.blade.php   |    2 +-
 resources/views/dhiran/reports/active.blade.php    |   46 +-
 resources/views/dhiran/reports/cashbook.blade.php  |  120 ++-
 .../views/dhiran/reports/forfeiture.blade.php      |   45 +-
 resources/views/dhiran/reports/index.blade.php     |   12 +-
 resources/views/dhiran/reports/interest.blade.php  |   84 +-
 resources/views/dhiran/reports/overdue.blade.php   |   42 +-
 .../views/dhiran/reports/profitability.blade.php   |   62 +-
 resources/views/dhiran/settings.blade.php          |  294 ++++--
 resources/views/dhiran/show.blade.php              |  231 ++---
 31 files changed, 1679 insertions(+), 2902 deletions(-)

```

New Loan changes:
```diff
diff --git a/resources/views/dhiran/create.blade.php b/resources/views/dhiran/create.blade.php
index 0a4c7767..0b21b9a4 100755
--- a/resources/views/dhiran/create.blade.php
+++ b/resources/views/dhiran/create.blade.php
@@ -19,5 +19,5 @@
 
         <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
-            @csrf <x-dhiran.once />
+            @csrf
 
             {{-- Customer Section --}}
@@ -35,5 +35,5 @@
                                 Customer *
                             </label>
-                            <input type="hidden" name="customer_id" :value="customer_id">
+                            <input type="hidden" name="customer_id" x-model="customer_id">
                             <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                 <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
@@ -125,7 +125,7 @@ class="dh-customer-search-input"
                  x-cloak
                  x-transition.opacity
-                 @keydown.escape.window="closeNewBorrower()"
+                 @keydown.escape.window="newBorrowerOpen = false"
                  @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
-                 @click.self="closeNewBorrower()"
+                 @click.self="newBorrowerOpen = false"
                  @wheel.self.prevent
                  @touchmove.self.prevent
@@ -139,5 +139,5 @@ class="dh-customer-search-input"
                             <p>Create a borrower profile and select it for this pledge loan.</p>
                         </div>
-                        <button type="button" class="dh-borrower-modal-close" @click="closeNewBorrower()" aria-label="Close borrower form">
+                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
                             <x-icon name="x" />
                         </button>
@@ -179,5 +179,5 @@ class="dh-customer-search-input"
 
                     <div class="dh-borrower-modal-foot">
-                        <button type="button" class="btn btn-secondary btn-sm" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
+                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                         <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                             <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
@@ -244,6 +244,5 @@ class="dh-customer-search-input"
                                         <option value="">Select</option>
                                         <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
-                                            {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
-                                            <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
+                                            <option :value="opt.value" x-text="opt.label"></option>
                                         </template>
                                     </select>
@@ -291,10 +290,10 @@ class="dh-customer-search-input"
                                 </div>
                                 <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.market_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                             </div>
                             <div>
                                 <label class="dh-label">Loan Value</label>
                                 <input type="text" readonly class="dh-input dh-calc-field"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.loan_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                 <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                             </div>
@@ -330,8 +329,6 @@ class="dh-customer-search-input"
                                    x-model.number="principal_amount"
                                    value="{{ old('principal_amount') }}"
-                                   placeholder="0.00" class="dh-input" required
-                                   :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit">
+                                   placeholder="0.00" class="dh-input" required>
                         </div>
-                        <p id="principal-limit" class="dh-principal-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                         @error('principal_amount')
                             <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
@@ -398,5 +395,5 @@ class="dh-customer-search-input"
                         <label class="dh-label">Processing Fee</label>
                         <input type="text" readonly class="dh-input dh-calc-field"
-                               :value="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)">
+                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                         <input type="hidden" name="processing_fee" :value="processingFee">
                     </div>
@@ -412,21 +409,21 @@ class="dh-customer-search-input"
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Market Value</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalMarketValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Loan Value (from items)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalLoanValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row border-t border-slate-200 pt-2">
                     <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
-                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + money((principal_amount || 0))"></span>
+                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Monthly Interest (approx.)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(monthlyInterest)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Processing Fee</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                 </div>
             </div>
@@ -447,5 +444,5 @@ class="dh-customer-search-input"
                     Cancel
                 </a>
-                <button type="submit" class="btn btn-primary btn-sm">
+                <button type="submit" class="btn btn-dark btn-sm">
                     <x-icon name="save" size="14" class="mr-1" />
                     Create Loan
@@ -457,13 +454,7 @@ class="dh-customer-search-input"
     @push('scripts')
     <script>
-        (() => {
-        const registerDhiranCreateForm = () => {
+        document.addEventListener('alpine:init', () => {
             Alpine.data('dhiranCreateForm', () => ({
-                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
-                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
-                initialCustomerId: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
-                get customer_id() {
-                    return this.selectedCustomer ? this.selectedCustomer.id : '';
-                },
+                customer_id: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
                 customerDisplayName: '',
                 customerDropdownOpen: false,
@@ -479,13 +470,7 @@ class="dh-customer-search-input"
                 ])->values()) }},
                 selectedCustomer: null,
-                // Add Borrower dialog: one state, 'closed' -> 'open' -> 'saving' -> 'closed' (saved) or 'open' (refused).
-                nbState: 'closed',
+                newBorrowerOpen: false,
+                nbSaving: false,
                 nbError: '',
-                get newBorrowerOpen() {
-                    return this.nbState !== 'closed';
-                },
-                get nbSaving() {
-                    return this.nbState === 'saving';
-                },
                 nb: { first_name: '', last_name: '', mobile: '', pan: '', id_number: '', state_code: '', address: '' },
                 loanPan: {{ Illuminate\Support\Js::from(old('pan', '')) }},
@@ -498,24 +483,11 @@ class="dh-customer-search-input"
                 penalty_rate_monthly: {{ old('penalty_rate_monthly', $defaults['penalty_rate_monthly'] ?? 2) }},
                 processingFeePercent: {{ $defaults['processing_fee_percent'] ?? 0 }},
-                processingFeeFlat: {{ $defaults['processing_fee_flat'] ?? 0 }},
                 ltvPercent: {{ $defaults['ltv_percent'] ?? 75 }},
-                highValueLtv: {{ $defaults['high_value_ltv'] ?? 75 }},
-                highValueThreshold: {{ $defaults['high_value_threshold'] ?? 250000 }},
-                minLoan: {{ $defaults['min_loan'] ?? 0 }},
-                maxLoan: {{ $defaults['max_loan'] ?? 0 }},
-
-                // After a refused save, the items come back as typed (init() recalculates their values).
-                items: {{ Illuminate\Support\Js::from(collect(old('items') ?: [[]])->values()->map(fn ($i) => [
-                    'description' => (string) ($i['description'] ?? ''),
-                    'metal_type' => (string) ($i['metal_type'] ?? 'gold'),
-                    'value_mode' => (string) ($i['value_mode'] ?? 'metal'),
-                    'gross_weight' => (float) ($i['gross_weight'] ?? 0),
-                    'stone_weight' => (float) ($i['stone_weight'] ?? 0),
-                    'purity' => (float) ($i['purity'] ?? 22),
-                    'rate_per_gram_at_pledge' => (float) ($i['rate_per_gram_at_pledge'] ?? 0),
-                    'huid' => (string) ($i['huid'] ?? ''),
-                    'net_metal_weight' => 0, 'fine_weight' => 0,
-                    'market_value' => (float) ($i['market_value'] ?? 0), 'loan_value' => 0,
-                ])) }},
+
+                items: [{
+                    description: '', metal_type: 'gold', value_mode: 'metal', gross_weight: 0, stone_weight: 0, purity: 22,
+                    rate_per_gram_at_pledge: 0, huid: '',
+                    net_metal_weight: 0, fine_weight: 0, market_value: 0, loan_value: 0
+                }],
 
                 get totalMarketValue() {
@@ -525,45 +497,16 @@ class="dh-customer-search-input"
                     return this.items.reduce((sum, i) => sum + (i.loan_value || 0), 0);
                 },
-                // Largest principal the server will accept (mirrors DhiranService::createLoan): the items' market
-                // value at the LTV for the principal's tier (each item rounded to paise), never above the shop's
-                // maximum. A principal at or over the high-value threshold gets the (usually lower) high-value LTV.
-                itemsLoanValueAt(ltv) {
-                    return this.items.reduce((sum, i) => sum + Math.round((i.market_value || 0) * ltv) / 100, 0);
-                },
-                get maxPrincipal() {
-                    const T = this.highValueThreshold, low = this.itemsLoanValueAt(this.ltvPercent), high = this.itemsLoanValueAt(this.highValueLtv);
-                    const best = T <= 0 ? high : (high >= T ? Math.max(high, Math.min(low, T - 0.01)) : Math.min(low, T - 0.01));
-                    return Math.max(0, this.maxLoan > 0 ? Math.min(best, this.maxLoan) : best);
-                },
-                get principalProblem() {
-                    const p = Number(this.principal_amount) || 0;
-                    if (p <= 0 || this.totalMarketValue <= 0) return false;
-                    return p > this.maxPrincipal + 0.001 || p < this.minLoan;
-                },
-                get principalHint() {
-                    if (this.totalMarketValue <= 0) return 'Add the pledged items to see how much can be lent.';
-                    const p = Number(this.principal_amount) || 0, sym = '{{ $currencySymbol ?? '₹' }}';
-                    if (p > 0 && p < this.minLoan) return 'The smallest loan is ' + sym + this.money(this.minLoan) + '.';
-                    if (p > this.maxPrincipal + 0.001) return 'Too high: these items allow up to ' + sym + this.money(this.maxPrincipal) + '.';
-                    return 'Up to ' + sym + this.money(this.maxPrincipal) + ' against these items.';
-                },
                 get monthlyInterest() {
                     return (this.principal_amount || 0) * (this.interest_rate_monthly || 0) / 100;
                 },
                 get processingFee() {
-                    // The shop's fee is either a % of the loan or a fixed amount (Dhiran settings).
-                    return (Number(this.principal_amount) || 0) * (this.processingFeePercent || 0) / 100 + (this.processingFeeFlat || 0);
-                },
-                // Rupees as the rest of the app shows them (₹44,000.00); Number() also covers a value typed as text.
-                money(v) {
-                    return (Number(v) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
+                    return (this.principal_amount || 0) * (this.processingFeePercent || 0) / 100;
                 },
 
                 init() {
-                    this.items.forEach((_, i) => this.recalcItem(i));
                     // If a borrower was preselected (?customer_id= from the borrower
                     // profile/list), show their name in the picker on load.
-                    if (this.initialCustomerId) {
-                        const c = this.customers.find(x => String(x.id) === String(this.initialCustomerId));
+                    if (this.customer_id) {
+                        const c = this.customers.find(x => String(x.id) === String(this.customer_id));
                         if (c) this.selectCustomer(c, true);
                     }
@@ -587,4 +530,5 @@ class="dh-customer-search-input"
                     if (this.selectedCustomer && this.customerSearch.trim() !== (this.selectedCustomer.name || '')) {
                         this.selectedCustomer = null;
+                
```

## 48b0d5eb0a286055ece99e869e35a77d3f0880e3
- /home/himanshu/Desktop/jewelflow-worktrees/jewelflows-takeover

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php          |  554 ++++-------
 app/Services/DhiranService.php                     |  426 +++-----
 resources/css/dhiran-documents.css                 |  258 +++--
 resources/css/dhiran-tokens.css                    |   92 --
 resources/css/dhiran.css                           | 1020 ++++++--------------
 resources/views/components/dhiran-layout.blade.php |   61 +-
 resources/views/dhiran/activate.blade.php          |   18 +-
 resources/views/dhiran/borrowers/create.blade.php  |   47 -
 resources/views/dhiran/borrowers/index.blade.php   |  111 +--
 resources/views/dhiran/borrowers/show.blade.php    |  206 ++--
 resources/views/dhiran/create.blade.php            |  167 +---
 resources/views/dhiran/customer-loans.blade.php    |   59 +-
 resources/views/dhiran/dashboard.blade.php         |  148 +--
 resources/views/dhiran/forfeiture-notice.blade.php |    4 +-
 resources/views/dhiran/items/show.blade.php        |   48 +-
 resources/views/dhiran/loans.blade.php             |  162 +++-
 resources/views/dhiran/old-loans/create.blade.php  |  162 ----
 resources/views/dhiran/old-loans/index.blade.php   |   75 --
 resources/views/dhiran/old-loans/preview.blade.php |   72 --
 resources/views/dhiran/old-loans/upload.blade.php  |   46 -
 resources/views/dhiran/onboarding/plans.blade.php  |   24 +-
 resources/views/dhiran/payment-receipt.blade.php   |    2 +-
 resources/views/dhiran/receipt.blade.php           |    2 +-
 resources/views/dhiran/reports/active.blade.php    |   54 +-
 resources/views/dhiran/reports/cashbook.blade.php  |  122 ++-
 .../views/dhiran/reports/forfeiture.blade.php      |   47 +-
 resources/views/dhiran/reports/index.blade.php     |   30 +-
 resources/views/dhiran/reports/interest.blade.php  |   90 +-
 resources/views/dhiran/reports/overdue.blade.php   |   48 +-
 .../views/dhiran/reports/profitability.blade.php   |   64 +-
 resources/views/dhiran/settings.blade.php          |  296 ++++--
 resources/views/dhiran/show.blade.php              |  255 ++---
 32 files changed, 1783 insertions(+), 2987 deletions(-)

```

New Loan changes:
```diff
diff --git a/resources/views/dhiran/create.blade.php b/resources/views/dhiran/create.blade.php
index 0a4c7767..00e6feab 100755
--- a/resources/views/dhiran/create.blade.php
+++ b/resources/views/dhiran/create.blade.php
@@ -7,5 +7,5 @@
         <div class="page-actions">
             <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                <x-icon name="arrow-left" size="14" class="mr-1" />
+                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                 Back to Loans
             </a>
@@ -19,10 +19,10 @@
 
         <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
-            @csrf <x-dhiran.once />
+            @csrf
 
             {{-- Customer Section --}}
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="user" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                     Customer Details
                 </div>
@@ -32,11 +32,11 @@
                         <div class="dh-customer-search-box">
                             <label class="dh-label">
-                                <x-icon name="user" />
+                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                 Customer *
                             </label>
-                            <input type="hidden" name="customer_id" :value="customer_id">
+                            <input type="hidden" name="customer_id" x-model="customer_id">
                             <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                 <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
-                                    <span class="dh-customer-search-icon"><x-icon name="search" size="15" /></span>
+                                    <span class="dh-customer-search-icon"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                     <input type="search"
                                            class="dh-customer-search-input"
@@ -66,5 +66,5 @@ class="dh-customer-search-input"
 
                         <button type="button" class="btn btn-dark btn-sm dh-customer-add-btn" @click="openNewBorrower()" aria-label="Add new borrower" title="Add new borrower">
-                            <x-icon name="plus" size="15" />
+                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             <span>Add New Borrower</span>
                         </button>
@@ -125,7 +125,7 @@ class="dh-customer-search-input"
                  x-cloak
                  x-transition.opacity
-                 @keydown.escape.window="closeNewBorrower()"
+                 @keydown.escape.window="newBorrowerOpen = false"
                  @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
-                 @click.self="closeNewBorrower()"
+                 @click.self="newBorrowerOpen = false"
                  @wheel.self.prevent
                  @touchmove.self.prevent
@@ -139,6 +139,6 @@ class="dh-customer-search-input"
                             <p>Create a borrower profile and select it for this pledge loan.</p>
                         </div>
-                        <button type="button" class="dh-borrower-modal-close" @click="closeNewBorrower()" aria-label="Close borrower form">
-                            <x-icon name="x" />
+                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
+                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                         </button>
                     </div>
@@ -179,5 +179,5 @@ class="dh-customer-search-input"
 
                     <div class="dh-borrower-modal-foot">
-                        <button type="button" class="btn btn-secondary btn-sm" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
+                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                         <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                             <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
@@ -190,5 +190,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="package" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                     Pledged Items
                 </div>
@@ -197,5 +197,5 @@ class="dh-customer-search-input"
                     <div class="dh-item-row">
                         <button type="button" class="dh-item-remove" @click="removeItem(index)" x-show="items.length > 1">
-                            <x-icon name="x" />
+                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                         </button>
 
@@ -244,6 +244,5 @@ class="dh-customer-search-input"
                                         <option value="">Select</option>
                                         <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
-                                            {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
-                                            <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
+                                            <option :value="opt.value" x-text="opt.label"></option>
                                         </template>
                                     </select>
@@ -291,10 +290,10 @@ class="dh-customer-search-input"
                                 </div>
                                 <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.market_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                             </div>
                             <div>
                                 <label class="dh-label">Loan Value</label>
                                 <input type="text" readonly class="dh-input dh-calc-field"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.loan_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                 <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                             </div>
@@ -310,5 +309,5 @@ class="dh-customer-search-input"
 
                 <button type="button" class="dh-add-item-btn" @click="addItem()">
-                    <x-icon name="plus" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                     Add Item
                 </button>
@@ -318,5 +317,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="calculator" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                     Loan Parameters
                 </div>
@@ -330,8 +329,6 @@ class="dh-customer-search-input"
                                    x-model.number="principal_amount"
                                    value="{{ old('principal_amount') }}"
-                                   placeholder="0.00" class="dh-input" required
-                                   :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit">
+                                   placeholder="0.00" class="dh-input" required>
                         </div>
-                        <p id="principal-limit" class="dh-principal-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                         @error('principal_amount')
                             <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
@@ -398,5 +395,5 @@ class="dh-customer-search-input"
                         <label class="dh-label">Processing Fee</label>
                         <input type="text" readonly class="dh-input dh-calc-field"
-                               :value="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)">
+                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                         <input type="hidden" name="processing_fee" :value="processingFee">
                     </div>
@@ -407,26 +404,26 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="badge-check" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                     Loan Summary
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Market Value</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalMarketValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Loan Value (from items)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalLoanValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row border-t border-slate-200 pt-2">
                     <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
-                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + money((principal_amount || 0))"></span>
+                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Monthly Interest (approx.)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(monthlyInterest)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Processing Fee</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                 </div>
             </div>
@@ -435,5 +432,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <label for="notes" class="dh-label">
-                    <x-icon name="file-text" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                     Notes (optional)
                 </label>
@@ -444,9 +441,9 @@ class="dh-customer-search-input"
             <div class="flex justify-end gap-3 pb-6">
                 <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                    <x-icon name="x" size="14" class="mr-1" />
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                     Cancel
                 </a>
-                <button type="submit" class="btn btn-primary btn-sm">
-                    <x-icon name="save" size="14" class="mr-1" />
+                <button type="submit" class="btn btn-dark btn-sm">
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                     Create Loan
                 </button>
@@ -457,13 +454,7 @@ class="dh-customer-search-input"
     @push('scripts')
     <script>
-        (() => {
-        const registerDhiranCreateForm = () => {
+        document.addEventListener('alpine:init', () => {
             Alpine.data('dhiranCreateForm', () => ({
-                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
-                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
-                initialCustomerId: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
-                get customer_id() {
-                    return this.selectedCustomer ? this.selectedCustomer.id : '';
-                },
+                customer_id: '{{ old('custome
```

## b1d7f5c4b7cc2d57990c9a31f2b613c556e5b407
- /home/himanshu/Desktop/jewelflow-worktrees/mobile-companion-api

Screens/engine: different
```
 app/Http/Controllers/DhiranController.php          |  553 ++++-------
 app/Services/DhiranService.php                     |  426 +++-----
 resources/css/dhiran-documents.css                 |  258 +++--
 resources/css/dhiran-tokens.css                    |   92 --
 resources/css/dhiran.css                           | 1020 ++++++--------------
 resources/views/components/dhiran-layout.blade.php |   61 +-
 resources/views/dhiran/activate.blade.php          |   16 +-
 resources/views/dhiran/borrowers/create.blade.php  |   47 -
 resources/views/dhiran/borrowers/index.blade.php   |  118 +--
 resources/views/dhiran/borrowers/show.blade.php    |  205 ++--
 resources/views/dhiran/create.blade.php            |  167 +---
 resources/views/dhiran/customer-loans.blade.php    |   59 +-
 resources/views/dhiran/dashboard.blade.php         |  148 +--
 resources/views/dhiran/forfeiture-notice.blade.php |    4 +-
 resources/views/dhiran/items/show.blade.php        |   48 +-
 resources/views/dhiran/loans.blade.php             |  162 +++-
 resources/views/dhiran/old-loans/create.blade.php  |  162 ----
 resources/views/dhiran/old-loans/index.blade.php   |   75 --
 resources/views/dhiran/old-loans/preview.blade.php |   72 --
 resources/views/dhiran/old-loans/upload.blade.php  |   46 -
 resources/views/dhiran/onboarding/plans.blade.php  |   14 +-
 resources/views/dhiran/payment-receipt.blade.php   |    2 +-
 resources/views/dhiran/receipt.blade.php           |    2 +-
 resources/views/dhiran/reports/active.blade.php    |   54 +-
 resources/views/dhiran/reports/cashbook.blade.php  |  122 ++-
 .../views/dhiran/reports/forfeiture.blade.php      |   47 +-
 resources/views/dhiran/reports/index.blade.php     |   30 +-
 resources/views/dhiran/reports/interest.blade.php  |   90 +-
 resources/views/dhiran/reports/overdue.blade.php   |   48 +-
 .../views/dhiran/reports/profitability.blade.php   |   64 +-
 resources/views/dhiran/settings.blade.php          |  296 ++++--
 resources/views/dhiran/show.blade.php              |  255 ++---
 32 files changed, 1777 insertions(+), 2986 deletions(-)

```

New Loan changes:
```diff
diff --git a/resources/views/dhiran/create.blade.php b/resources/views/dhiran/create.blade.php
index 0a4c7767..00e6feab 100755
--- a/resources/views/dhiran/create.blade.php
+++ b/resources/views/dhiran/create.blade.php
@@ -7,5 +7,5 @@
         <div class="page-actions">
             <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                <x-icon name="arrow-left" size="14" class="mr-1" />
+                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                 Back to Loans
             </a>
@@ -19,10 +19,10 @@
 
         <form method="POST" action="{{ route('dhiran.store') }}" class="dhiran-create-form max-w-4xl mx-auto space-y-6" @submit="prepareSubmit" data-turbo-frame="_top">
-            @csrf <x-dhiran.once />
+            @csrf
 
             {{-- Customer Section --}}
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="user" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                     Customer Details
                 </div>
@@ -32,11 +32,11 @@
                         <div class="dh-customer-search-box">
                             <label class="dh-label">
-                                <x-icon name="user" />
+                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                 Customer *
                             </label>
-                            <input type="hidden" name="customer_id" :value="customer_id">
+                            <input type="hidden" name="customer_id" x-model="customer_id">
                             <div class="dh-dd-wrap" :class="{ 'dd-open': customerDropdownOpen }">
                                 <div class="dh-customer-search-control" :class="{ open: customerDropdownOpen }">
-                                    <span class="dh-customer-search-icon"><x-icon name="search" size="15" /></span>
+                                    <span class="dh-customer-search-icon"><svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                     <input type="search"
                                            class="dh-customer-search-input"
@@ -66,5 +66,5 @@ class="dh-customer-search-input"
 
                         <button type="button" class="btn btn-dark btn-sm dh-customer-add-btn" @click="openNewBorrower()" aria-label="Add new borrower" title="Add new borrower">
-                            <x-icon name="plus" size="15" />
+                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                             <span>Add New Borrower</span>
                         </button>
@@ -125,7 +125,7 @@ class="dh-customer-search-input"
                  x-cloak
                  x-transition.opacity
-                 @keydown.escape.window="closeNewBorrower()"
+                 @keydown.escape.window="newBorrowerOpen = false"
                  @keydown.enter.prevent.stop="if (!$event.target.matches('textarea')) saveBorrower()"
-                 @click.self="closeNewBorrower()"
+                 @click.self="newBorrowerOpen = false"
                  @wheel.self.prevent
                  @touchmove.self.prevent
@@ -139,6 +139,6 @@ class="dh-customer-search-input"
                             <p>Create a borrower profile and select it for this pledge loan.</p>
                         </div>
-                        <button type="button" class="dh-borrower-modal-close" @click="closeNewBorrower()" aria-label="Close borrower form">
-                            <x-icon name="x" />
+                        <button type="button" class="dh-borrower-modal-close" @click="newBorrowerOpen = false" aria-label="Close borrower form">
+                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                         </button>
                     </div>
@@ -179,5 +179,5 @@ class="dh-customer-search-input"
 
                     <div class="dh-borrower-modal-foot">
-                        <button type="button" class="btn btn-secondary btn-sm" @click="closeNewBorrower()" :disabled="nbSaving">Cancel</button>
+                        <button type="button" class="btn btn-secondary btn-sm" @click="newBorrowerOpen = false">Cancel</button>
                         <button type="button" class="dh-borrower-save" @click="saveBorrower()" :disabled="nbSaving">
                             <span x-text="nbSaving ? 'Saving…' : 'Save borrower'"></span>
@@ -190,5 +190,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="package" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                     Pledged Items
                 </div>
@@ -197,5 +197,5 @@ class="dh-customer-search-input"
                     <div class="dh-item-row">
                         <button type="button" class="dh-item-remove" @click="removeItem(index)" x-show="items.length > 1">
-                            <x-icon name="x" />
+                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                         </button>
 
@@ -244,6 +244,5 @@ class="dh-customer-search-input"
                                         <option value="">Select</option>
                                         <template x-for="opt in purityOptions(item.metal_type)" :key="opt.value">
-                                            {{-- :selected — the options draw after x-model sets the value, so it showed "Select". --}}
-                                            <option :value="opt.value" x-text="opt.label" :selected="Number(opt.value) === Number(item.purity)"></option>
+                                            <option :value="opt.value" x-text="opt.label"></option>
                                         </template>
                                     </select>
@@ -291,10 +290,10 @@ class="dh-customer-search-input"
                                 </div>
                                 <input type="text" readonly class="dh-input dh-calc-field" x-show="item.metal_type !== 'other' && item.value_mode !== 'appraised'"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.market_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.market_value.toFixed(2)">
                             </div>
                             <div>
                                 <label class="dh-label">Loan Value</label>
                                 <input type="text" readonly class="dh-input dh-calc-field"
-                                       :value="'{{ $currencySymbol ?? '₹' }}' + money(item.loan_value)">
+                                       :value="'{{ $currencySymbol ?? '₹' }}' + item.loan_value.toFixed(2)">
                                 <input type="hidden" :name="'items['+index+'][loan_value]'" :value="item.loan_value">
                             </div>
@@ -310,5 +309,5 @@ class="dh-customer-search-input"
 
                 <button type="button" class="dh-add-item-btn" @click="addItem()">
-                    <x-icon name="plus" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                     Add Item
                 </button>
@@ -318,5 +317,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="calculator" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                     Loan Parameters
                 </div>
@@ -330,8 +329,6 @@ class="dh-customer-search-input"
                                    x-model.number="principal_amount"
                                    value="{{ old('principal_amount') }}"
-                                   placeholder="0.00" class="dh-input" required
-                                   :aria-invalid="principalProblem ? 'true' : 'false'" aria-describedby="principal-limit">
+                                   placeholder="0.00" class="dh-input" required>
                         </div>
-                        <p id="principal-limit" class="dh-principal-limit" data-principal-limit :class="principalProblem && 'is-problem'" x-text="principalHint" aria-live="polite"></p>
                         @error('principal_amount')
                             <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
@@ -398,5 +395,5 @@ class="dh-customer-search-input"
                         <label class="dh-label">Processing Fee</label>
                         <input type="text" readonly class="dh-input dh-calc-field"
-                               :value="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)">
+                               :value="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)">
                         <input type="hidden" name="processing_fee" :value="processingFee">
                     </div>
@@ -407,26 +404,26 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <div class="dh-section-title">
-                    <x-icon name="badge-check" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                     Loan Summary
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Market Value</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalMarketValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalMarketValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Total Loan Value (from items)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(totalLoanValue)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + totalLoanValue.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row border-t border-slate-200 pt-2">
                     <span class="dh-summary-label font-semibold text-slate-900">Principal Amount</span>
-                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + money((principal_amount || 0))"></span>
+                    <span class="dh-summary-value text-lg" x-text="'{{ $currencySymbol ?? '₹' }}' + (principal_amount || 0).toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Monthly Interest (approx.)</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(monthlyInterest)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + monthlyInterest.toFixed(2)"></span>
                 </div>
                 <div class="dh-summary-row">
                     <span class="dh-summary-label">Processing Fee</span>
-                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + money(processingFee)"></span>
+                    <span class="dh-summary-value" x-text="'{{ $currencySymbol ?? '₹' }}' + processingFee.toFixed(2)"></span>
                 </div>
             </div>
@@ -435,5 +432,5 @@ class="dh-customer-search-input"
             <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                 <label for="notes" class="dh-label">
-                    <x-icon name="file-text" />
+                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                     Notes (optional)
                 </label>
@@ -444,9 +441,9 @@ class="dh-customer-search-input"
             <div class="flex justify-end gap-3 pb-6">
                 <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
-                    <x-icon name="x" size="14" class="mr-1" />
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                     Cancel
                 </a>
-                <button type="submit" class="btn btn-primary btn-sm">
-                    <x-icon name="save" size="14" class="mr-1" />
+                <button type="submit" class="btn btn-dark btn-sm">
+                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                     Create Loan
                 </button>
@@ -457,13 +454,7 @@ class="dh-customer-search-input"
     @push('scripts')
     <script>
-        (() => {
-        const registerDhiranCreateForm = () => {
+        document.addEventListener('alpine:init', () => {
             Alpine.data('dhiranCreateForm', () => ({
-                // The borrower is one value, selectedCustomer; the posted id is read from it, so the form can never
-                // send an id for a borrower the screen does not show (owner 2026-11-12, state-machine audit).
-                initialCustomerId: '{{ old('customer_id', $preselectedCustomerId ?? '') }}',
-                get customer_id() {
-                    return this.selectedCustomer ? this.selectedCustomer.id : '';
-                },
+                customer_id: '{{ old('custome
```

## f093ff351fc7a93e44b7596bb595df33ce822a7b
- /home/himanshu/Desktop/jewelflow-worktrees/ui-integration

Screens/engine: identical for audited paths
```
(no differences)
```

New Loan changes:
```diff
(no New Loan differences)
```
