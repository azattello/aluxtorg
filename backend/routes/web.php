<?php

use App\Http\Controllers\OrderNotificationPreferenceController;
use App\Http\Controllers\PublicSeoController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'e-commerce-api']));
Route::get('/robots.txt', [PublicSeoController::class, 'robots'])->name('seo.robots');
Route::get('/sitemap.xml', [PublicSeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get(
    '/orders/{order}/notifications/unsubscribe',
    [OrderNotificationPreferenceController::class, 'show'],
)->middleware('signed')->name('orders.notifications.unsubscribe');
Route::post(
    '/orders/{order}/notifications/unsubscribe',
    [OrderNotificationPreferenceController::class, 'unsubscribe'],
)->middleware('signed')->name('orders.notifications.unsubscribe.submit');
