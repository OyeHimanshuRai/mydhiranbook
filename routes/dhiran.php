<?php
use Illuminate\Support\Facades\Route;
    Route::middleware(['auth','dhiran.verified','shop.exists','shop','can:dhiran.view'])->get('/dhiran',[\App\Http\Controllers\DhiranController::class,'dashboard'])->name('dhiran.dashboard');
    Route::prefix('dhiran')->name('dhiran.')->middleware(['shop'])->group(function () {
        // Dashboard & Activation — no dhiran.enabled check (dashboard shows activation page)
        Route::post('/activate', [\App\Http\Controllers\DhiranController::class, 'activate'])->middleware('can:dhiran.settings')->name('activate');

        // All remaining routes require dhiran to be enabled
        Route::middleware(['dhiran.enabled'])->group(function () {
            // Loan CRUD — create permission
            Route::get('/create', [\App\Http\Controllers\DhiranController::class, 'create'])->middleware('can:dhiran.create')->name('create');
            Route::post('/', [\App\Http\Controllers\DhiranController::class, 'store'])->middleware('can:dhiran.create')->name('store');

            // Inline borrower creation from the New Loan page (Dhiran-scoped).
            Route::post('/customers', [\App\Http\Controllers\DhiranController::class, 'storeCustomer'])->middleware('can:dhiran.create')->name('customers.store');
            // New Loan borrower picker: at most 8 active matches for what was typed (declared before /borrowers/{customer}).
            Route::get('/borrowers/search', [\App\Http\Controllers\DhiranController::class, 'searchBorrowers'])->middleware(['can:dhiran.create', 'throttle:60,1'])->name('borrowers.search');
            // Save a customer's details without a loan (declared before /borrowers/{customer}).
            Route::get('/borrowers/create', [\App\Http\Controllers\DhiranController::class, 'createCustomer'])->middleware('can:dhiran.create')->name('borrowers.create');

            // Private evidence attachments (item photos, ID proof, loan documents).
            Route::post('/attachments', [\App\Http\Controllers\DhiranController::class, 'storeAttachment'])->middleware('can:dhiran.create')->name('attachments.store');
            Route::get('/attachments/{attachment}', [\App\Http\Controllers\DhiranController::class, 'showAttachment'])->middleware('can:dhiran.view')->name('attachments.show');

            // Loan listing & detail — view permission
            Route::get('/loans', [\App\Http\Controllers\DhiranController::class, 'loans'])->middleware(['can:dhiran.view', 'report.password:export'])->name('loans');
            Route::get('/loans/{loan}', [\App\Http\Controllers\DhiranController::class, 'show'])->middleware('can:dhiran.view')->name('show');
            // Activate a pending-evidence loan (evidence-gated in the service).
            Route::post('/loans/{loan}/activate', [\App\Http\Controllers\DhiranController::class, 'activateLoan'])->middleware('can:dhiran.create')->name('activate-loan');

            // Payments — pay permission
            Route::post('/loans/{loan}/pay-interest', [\App\Http\Controllers\DhiranController::class, 'payInterest'])->middleware('can:dhiran.pay')->name('pay-interest');
            Route::post('/loans/{loan}/repay', [\App\Http\Controllers\DhiranController::class, 'repay'])->middleware('can:dhiran.pay')->name('repay');
            Route::post('/loans/{loan}/pre-close', [\App\Http\Controllers\DhiranController::class, 'preClose'])->middleware('can:dhiran.pay')->name('pre-close');
            Route::post('/loans/{loan}/close', [\App\Http\Controllers\DhiranController::class, 'close'])->middleware('can:dhiran.pay')->name('close');
            Route::post('/loans/{loan}/cancel', [\App\Http\Controllers\DhiranController::class, 'cancel'])->middleware('can:dhiran.create')->name('cancel');

            // Old Loans: bring a shop's running paper loans in, borrower and loan together (owner 2026-11-12)
            Route::get('/old-loans', [\App\Http\Controllers\DhiranOldLoanController::class, 'index'])->middleware('can:dhiran.create')->name('old-loans.index');
            Route::get('/old-loans/create', [\App\Http\Controllers\DhiranOldLoanController::class, 'create'])->middleware('can:dhiran.create')->name('old-loans.create');
            Route::post('/old-loans', [\App\Http\Controllers\DhiranOldLoanController::class, 'store'])->middleware('can:dhiran.create')->name('old-loans.store');
            Route::get('/old-loans/template', [\App\Http\Controllers\DhiranOldLoanController::class, 'template'])->middleware('can:dhiran.create')->name('old-loans.template');
            Route::get('/old-loans/upload', [\App\Http\Controllers\DhiranOldLoanController::class, 'uploadForm'])->middleware('can:dhiran.create')->name('old-loans.upload');
            Route::post('/old-loans/upload', [\App\Http\Controllers\DhiranOldLoanController::class, 'upload'])->middleware('can:dhiran.create')->name('old-loans.upload.store');
            Route::get('/old-loans/upload/{token}', [\App\Http\Controllers\DhiranOldLoanController::class, 'preview'])->middleware('can:dhiran.create')->name('old-loans.upload.preview');
            Route::post('/old-loans/upload/{token}', [\App\Http\Controllers\DhiranOldLoanController::class, 'confirmUpload'])->middleware('can:dhiran.create')->name('old-loans.upload.confirm');

            // Release item — release permission
            Route::post('/loans/{loan}/release-item', [\App\Http\Controllers\DhiranController::class, 'releaseItem'])->middleware('can:dhiran.release')->name('release-item');

            // Renew — renew permission
            Route::post('/loans/{loan}/renew', [\App\Http\Controllers\DhiranController::class, 'renew'])->middleware('can:dhiran.renew')->name('renew');

            // Forfeiture — forfeit permission
            Route::post('/loans/{loan}/send-notice', [\App\Http\Controllers\DhiranController::class, 'sendNotice'])->middleware('can:dhiran.forfeit')->name('send-notice');
            Route::post('/loans/{loan}/forfeit', [\App\Http\Controllers\DhiranController::class, 'forfeit'])->middleware('can:dhiran.forfeit')->name('forfeit');

            // Printable documents — view permission
            Route::get('/loans/{loan}/receipt', [\App\Http\Controllers\DhiranController::class, 'receipt'])->middleware('can:dhiran.view')->name('receipt');
            Route::get('/loans/{loan}/closure-certificate', [\App\Http\Controllers\DhiranController::class, 'closureCertificate'])->middleware('can:dhiran.view')->name('closure-certificate');
            Route::get('/loans/{loan}/forfeiture-notice', [\App\Http\Controllers\DhiranController::class, 'forfeitureNotice'])->middleware('can:dhiran.view')->name('forfeiture-notice');
            Route::get('/loans/{loan}/payments/{payment}/receipt', [\App\Http\Controllers\DhiranController::class, 'paymentReceipt'])->middleware('can:dhiran.view')->name('payment-receipt');

            // Borrowers (Dhiran customer profiles) — view permission. Shop-scoped.
            Route::get('/borrowers/{customer}/photo', [\App\Http\Controllers\CustomerProfilePhotoController::class, 'show'])->middleware('can:dhiran.view')->name('borrowers.photo.show');
            Route::post('/borrowers/{customer}/photo', [\App\Http\Controllers\CustomerProfilePhotoController::class, 'store'])->middleware('can:dhiran.create')->name('borrowers.photo.store');
            Route::delete('/borrowers/{customer}/photo', [\App\Http\Controllers\CustomerProfilePhotoController::class, 'destroy'])->middleware('can:dhiran.create')->name('borrowers.photo.destroy');
            Route::get('/borrowers', [\App\Http\Controllers\DhiranController::class, 'borrowers'])->middleware(['can:dhiran.view', 'report.password:export'])->name('borrowers.index');
            Route::get('/borrowers/{customer}', [\App\Http\Controllers\DhiranController::class, 'borrowerProfile'])->middleware(['can:dhiran.view', 'report.password:export'])->name('borrowers.show');

            // Pledged item detail — view permission. Shop-scoped.
            Route::get('/items/{item}', [\App\Http\Controllers\DhiranController::class, 'itemDetail'])->middleware('can:dhiran.view')->name('items.show');

            // Legacy customer-loans route → redirect into the borrower profile (no duplicate page).
            Route::get('/customers/{customer}', function (\App\Models\Customer $customer) {
                return redirect()->route('dhiran.borrowers.show', $customer);
            })->middleware('can:dhiran.view')->name('customer-loans');

            // Settings — settings permission
            Route::get('/settings', [\App\Http\Controllers\DhiranController::class, 'settings'])->middleware('can:dhiran.settings')->name('settings');
            Route::patch('/settings', [\App\Http\Controllers\DhiranController::class, 'updateSettings'])->middleware('can:dhiran.settings')->name('settings.update');
            Route::get('/settings/receipt', [\App\Http\Controllers\DhiranReceiptSettingsController::class, 'edit'])->middleware('can:dhiran.settings')->name('settings.receipt.edit');
            Route::patch('/settings/receipt', [\App\Http\Controllers\DhiranReceiptSettingsController::class, 'update'])->middleware('can:dhiran.settings')->name('settings.receipt');

            // Reports — reports permission
            Route::get('/reports', [\App\Http\Controllers\DhiranController::class, 'reports'])->middleware(['can:dhiran.reports', 'report.password'])->name('reports.index');
            Route::get('/reports/active', [\App\Http\Controllers\DhiranController::class, 'reportActive'])->middleware(['can:dhiran.reports', 'report.password'])->name('reports.active');
            Route::get('/reports/overdue', [\App\Http\Controllers\DhiranController::class, 'reportOverdue'])->middleware(['can:dhiran.reports', 'report.password'])->name('reports.overdue');
            Route::get('/reports/interest', [\App\Http\Controllers\DhiranController::class, 'reportInterest'])->middleware(['can:dhiran.reports', 'report.password'])->name('reports.interest');
            Route::get('/reports/forfeiture', [\App\Http\Controllers\DhiranController::class, 'reportForfeiture'])->middleware(['can:dhiran.reports', 'report.password'])->name('reports.forfeiture');
            Route::get('/reports/cashbook', [\App\Http\Controllers\DhiranController::class, 'reportCashbook'])->middleware(['can:dhiran.reports', 'report.password'])->name('reports.cashbook');
            Route::get('/reports/profitability', [\App\Http\Controllers\DhiranController::class, 'reportProfitability'])->middleware(['can:dhiran.reports', 'report.password'])->name('reports.profitability');
        });
    });

