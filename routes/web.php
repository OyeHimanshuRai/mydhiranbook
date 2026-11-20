<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopAuthController as Auth;
use App\Http\Controllers\CheckpointDhiranController as Loans;
Route::get('/login',[Auth::class,'loginForm'])->name('login');
Route::post('/login',[Auth::class,'login'])->middleware('throttle:5,1');
Route::get('/register',[Auth::class,'registerForm']);
Route::post('/register',[Auth::class,'register'])->middleware('throttle:5,1');
Route::post('/logout',[Auth::class,'logout'])->name('logout');
Route::get('/forgot-password',[\App\Http\Controllers\PasswordResetController::class,'forgotForm'])->name('password.request');
Route::post('/forgot-password',[\App\Http\Controllers\PasswordResetController::class,'forgot'])->middleware('throttle:3,1')->name('password.email');
Route::get('/reset-password/{token}',[\App\Http\Controllers\PasswordResetController::class,'resetForm'])->name('password.reset');
Route::post('/reset-password',[\App\Http\Controllers\PasswordResetController::class,'reset'])->middleware('throttle:5,1')->name('password.store');

// Email verification (shopless owners still onboarding) and the pay-first onboarding chain.
Route::middleware('auth')->group(function() {
    $v=\App\Http\Controllers\EmailVerificationOtpController::class;
    Route::get('/verify-email',[$v,'show'])->name('dhiran.verify-email');
    Route::post('/verify-email/send',[$v,'send'])->middleware('throttle:10,1')->name('dhiran.verify-email.send');
    Route::post('/verify-email/verify',[$v,'verify'])->middleware('throttle:10,1')->name('dhiran.verify-email.verify');
    Route::post('/verify-email/resend',[$v,'resend'])->middleware('throttle:10,1')->name('dhiran.verify-email.resend');
});
Route::middleware(['auth','dhiran.verified'])->prefix('onboarding')->group(function() {
    $o=\App\Http\Controllers\DhiranOnboardingController::class;
    Route::get('/plans',[$o,'plans'])->name('dhiran.plans');
    Route::get('/business',[$o,'create'])->name('dhiran.onboarding');
    Route::post('/business',[$o,'store'])->middleware('throttle:10,1')->name('dhiran.onboarding.store');
});
Route::middleware(['auth','dhiran.verified','shop.exists','shop'])->get('/',[\App\Http\Controllers\DhiranController::class,'dashboard']);

Route::middleware('shop')->group(function() {
    Route::get('/borrowers',[Loans::class,'borrowers']); Route::post('/borrowers',[Loans::class,'storeBorrower']);
    Route::get('/loans/create',[Loans::class,'create']); Route::post('/loans',[Loans::class,'store']);
    Route::get('/loans/{loan}',[Loans::class,'show'])->whereNumber('loan');
    Route::post('/loans/{loan}/evidence',[Loans::class,'evidence']); Route::post('/loans/{loan}/activate',[Loans::class,'activate']);
    Route::post('/loans/{loan}/payments',[Loans::class,'pay']); Route::get('/payments/{payment}/receipt',[Loans::class,'receipt']);
    Route::get('/attachments/{attachment}',[Loans::class,'attachment']);
    Route::get('/checkpoint/staff',[Loans::class,'staff']); Route::post('/checkpoint/staff',[Loans::class,'storeStaff']); Route::patch('/staff/{staff}',[Loans::class,'updateStaff']);
});
use App\Http\Controllers\Admin\AuthController as AdminAuth;
use App\Http\Controllers\Admin\PlatformController as Platform;
use App\Http\Controllers\Admin\{ShopManagementController as AdminShops,PlanController as AdminPlans,BillingManagementController as AdminBilling,UnresolvedPaymentController as AdminUnresolved};
Route::prefix('admin')->group(function() {
    Route::get('/login',[AdminAuth::class,'form'])->name('admin.login');
    Route::post('/login',[AdminAuth::class,'login'])->middleware('throttle:5,1');
    Route::get('/forgot-password',[AdminAuth::class,'forgotForm']);
    Route::post('/forgot-password',[AdminAuth::class,'forgot'])->middleware('throttle:3,1');
    Route::get('/reset-password/{token}',[AdminAuth::class,'resetForm'])->name('admin.password.reset');
    Route::post('/reset-password',[AdminAuth::class,'reset'])->middleware('throttle:5,1');
    Route::middleware('admin.session:challenge')->group(function() {
        Route::get('/mfa',[AdminAuth::class,'mfaForm']); Route::post('/mfa',[AdminAuth::class,'mfa'])->middleware('throttle:5,1');
        Route::post('/logout',[AdminAuth::class,'logout']);
    });
    Route::middleware('admin.session')->group(function() {
        Route::get('/',[Platform::class,'dashboard']); Route::get('/audit',[Platform::class,'audit']);
        Route::get('/shops',[AdminShops::class,'index']); Route::get('/shops/{shop}',[AdminShops::class,'show'])->whereNumber('shop'); Route::patch('/shops/{shop}',[AdminShops::class,'update']);
        Route::post('/shops/{shop}/subscription',[AdminBilling::class,'updateShopSubscription']); Route::post('/shops/{shop}/subscription/terminate',[AdminBilling::class,'terminateShopSubscription']);
        Route::get('/receipts/{invoice}',[AdminBilling::class,'receipt']);
        Route::get('/plans',[AdminPlans::class,'index']); Route::patch('/plans/{plan}',[AdminPlans::class,'update']);
        Route::get('/unresolved-payments',[AdminUnresolved::class,'index']); Route::post('/unresolved-payments/{event}/resolve',[AdminUnresolved::class,'resolve']);
        Route::get('/accounts',[Platform::class,'admins']); Route::post('/accounts',[Platform::class,'storeAdmin']); Route::patch('/accounts/{admin}',[Platform::class,'updateAdmin']);
    });
});


Route::post('/subscription/payment/webhook',[\App\Http\Controllers\SubscriptionController::class,'webhook'])->name('subscription.webhook');
Route::middleware(['auth','dhiran.verified'])->prefix('subscription')->name('subscription.')->group(function() {
    $c=\App\Http\Controllers\SubscriptionController::class;
    Route::get('/status',[$c,'status'])->name('status');
    Route::post('/choose',[$c,'choosePlan'])->name('choose');
    Route::get('/receipts/{invoice}',[$c,'receipt'])->name('receipt');
    Route::get('/payment',[$c,'payment'])->name('payment');
    Route::post('/payment/initiate',[$c,'initiatePayment'])->name('payment.initiate');
    Route::post('/payment/callback',[$c,'paymentCallback'])->name('payment.callback');
});

require __DIR__.'/dhiran.php';

Route::middleware('shop')->get('/comparison/standalone/new-loan', [Loans::class,'create']);
Route::middleware('shop')->get('/comparison/original/new-loan', [\App\Http\Controllers\ReferenceDhiranController::class,'create']);

Route::middleware('shop')->group(function () {
    Route::get('/settings',[\App\Http\Controllers\StaffSettingsController::class,'index'])->middleware('can:staff.view')->name('settings.edit');
    Route::patch('/settings/roles/{role}',[\App\Http\Controllers\StaffSettingsController::class,'updateRolePermissions'])->middleware('can:settings.edit')->name('settings.update.role');
    Route::get('/staff',[\App\Http\Controllers\StaffController::class,'index'])->middleware('can:staff.view')->name('staff.index');
    Route::get('/staff/create',[\App\Http\Controllers\StaffController::class,'create'])->middleware('can:staff.manage')->name('staff.create');
    Route::post('/staff',[\App\Http\Controllers\StaffController::class,'store'])->middleware('can:staff.manage')->name('staff.store');
    Route::get('/staff/{staff}/edit',[\App\Http\Controllers\StaffController::class,'edit'])->middleware('can:staff.manage')->name('staff.edit');
    Route::put('/staff/{staff}',[\App\Http\Controllers\StaffController::class,'update'])->middleware('can:staff.manage')->name('staff.update');
    Route::delete('/staff/{staff}',[\App\Http\Controllers\StaffController::class,'destroy'])->middleware('can:staff.manage')->name('staff.destroy');
    Route::patch('/staff/{staff}/reactivate',[\App\Http\Controllers\StaffController::class,'reactivate'])->middleware('can:staff.manage')->name('staff.reactivate');
});
