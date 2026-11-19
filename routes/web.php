<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopAuthController as Auth;
use App\Http\Controllers\DhiranController as Loans;
Route::get('/login',[Auth::class,'loginForm'])->name('login');
Route::post('/login',[Auth::class,'login'])->middleware('throttle:5,1');
Route::get('/register',[Auth::class,'registerForm']);
Route::post('/register',[Auth::class,'register'])->middleware('throttle:5,1');
Route::post('/logout',[Auth::class,'logout']);
Route::middleware('shop')->group(function() {
    Route::get('/',[Loans::class,'index']);
    Route::get('/borrowers',[Loans::class,'borrowers']); Route::post('/borrowers',[Loans::class,'storeBorrower']);
    Route::get('/loans/create',[Loans::class,'create']); Route::post('/loans',[Loans::class,'store']);
    Route::get('/loans/{loan}',[Loans::class,'show'])->whereNumber('loan');
    Route::post('/loans/{loan}/evidence',[Loans::class,'evidence']); Route::post('/loans/{loan}/activate',[Loans::class,'activate']);
    Route::post('/loans/{loan}/payments',[Loans::class,'pay']); Route::get('/payments/{payment}/receipt',[Loans::class,'receipt']);
    Route::get('/attachments/{attachment}',[Loans::class,'attachment']);
    Route::get('/staff',[Loans::class,'staff']); Route::post('/staff',[Loans::class,'storeStaff']); Route::patch('/staff/{staff}',[Loans::class,'updateStaff']);
});
use App\Http\Controllers\Admin\AuthController as AdminAuth;
use App\Http\Controllers\Admin\PlatformController as Platform;
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
        Route::get('/',[Platform::class,'dashboard']); Route::patch('/shops/{shop}',[Platform::class,'updateShop']);
        Route::post('/shops/{shop}/subscription',[Platform::class,'subscription']); Route::get('/audit',[Platform::class,'audit']);
        Route::get('/accounts',[Platform::class,'admins']); Route::post('/accounts',[Platform::class,'storeAdmin']); Route::patch('/accounts/{admin}',[Platform::class,'updateAdmin']);
    });
});
