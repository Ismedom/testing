<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PayPalController;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/about', function () {
    return view('about');
});




Route::get('/subscribe-form', [PayPalController::class, 'showSubscribeForm'])->name('paypal.subscribe.form');
Route::get('/create-plan', [PayPalController::class, 'createSubscriptionPlan'])->name('paypal.plan.create');
Route::post('/subscribe', [PayPalController::class, 'subscribe'])->name('paypal.subscribe');
Route::get('/subscription/success', [PayPalController::class, 'subscriptionSuccess'])->name('paypal.subscription.success');
Route::get('/subscription/cancel', [PayPalController::class, 'subscriptionCancel'])->name('paypal.subscription.cancel');