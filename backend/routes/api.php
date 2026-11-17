<?php

use App\Http\Controllers\Api\V1\AdminBannerController;
use App\Http\Controllers\Api\V1\AdminCategoryController;
use App\Http\Controllers\Api\V1\AdminOrderController;
use App\Http\Controllers\Api\V1\AdminProductController;
use App\Http\Controllers\Api\V1\AdminReturnRequestController;
use App\Http\Controllers\Api\V1\AdminWarehouseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\AdminAnalyticsController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ReturnRequestController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\StoreSettingsController;
use App\Http\Controllers\Api\V1\StorefrontViewController;
use App\Http\Controllers\Api\V1\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'api',
]));

Route::get('/v1/categories', [CatalogController::class, 'categories']);
Route::get('/v1/products', [CatalogController::class, 'products']);
Route::get('/v1/products/{slug}', [CatalogController::class, 'product']);
Route::get('/v1/banners', [CatalogController::class, 'banners']);
Route::get('/v1/store-settings', [StoreSettingsController::class, 'show']);
Route::post('/v1/storefront/views', [StorefrontViewController::class, 'store'])->middleware('throttle:storefront-view');
Route::post('/v1/orders', [OrderController::class, 'store'])->middleware(['auth:sanctum', 'throttle:auth-write']);

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-write');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetLink'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('email.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'currentUser']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationNotification'])
            ->middleware('throttle:6,1');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/v1/orders', [OrderController::class, 'index']);
    Route::get('/v1/orders/{order}', [OrderController::class, 'show']);
    Route::post('/v1/orders/{order}/returns', [ReturnRequestController::class, 'store']);
    Route::get('/v1/returns', [ReturnRequestController::class, 'index']);
    Route::get('/v1/returns/{returnRequest}', [ReturnRequestController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'can:manage-user-roles'])->group(function () {
    Route::get('/v1/admin/users', [UserRoleController::class, 'index']);
    Route::patch('/v1/admin/users/{user}/role', [UserRoleController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'can:manage-store-settings'])->group(function () {
    Route::get('/v1/admin/store-settings', [StoreSettingsController::class, 'show']);
    Route::put('/v1/admin/store-settings', [StoreSettingsController::class, 'update']);
});

Route::get('/v1/admin/analytics', [AdminAnalyticsController::class, 'index'])
    ->middleware(['auth:sanctum', 'can:view-store-analytics']);

Route::prefix('v1/admin')->middleware(['auth:sanctum', 'can:manage-catalog'])->group(function () {
    Route::apiResource('categories', AdminCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('products', AdminProductController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->middleware('throttle:20,1');
    Route::patch('/products/{product}/images/{image}', [ProductImageController::class, 'update']);
    Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
    Route::apiResource('banners', AdminBannerController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor(['store', 'update'], 'throttle:20,1');
});

Route::prefix('v1/admin/orders')->middleware(['auth:sanctum', 'can:manage-crm'])->group(function () {
    Route::get('/', [AdminOrderController::class, 'index']);
    Route::get('/assignees', [AdminOrderController::class, 'assignees']);
    Route::get('/{order}', [AdminOrderController::class, 'show']);
    Route::patch('/{order}/assignment', [AdminOrderController::class, 'assign']);
    Route::patch('/{order}/status', [AdminOrderController::class, 'updateStatus']);
    Route::patch('/{order}/payment-status', [AdminOrderController::class, 'updatePaymentStatus']);
    Route::patch('/{order}/delivery-fee', [AdminOrderController::class, 'updateDeliveryFee']);
    Route::post('/{order}/notes', [AdminOrderController::class, 'addNote']);
});

Route::prefix('v1/admin/returns')->middleware(['auth:sanctum', 'can:manage-crm'])->group(function () {
    Route::get('/', [AdminReturnRequestController::class, 'index']);
    Route::get('/{returnRequest}', [AdminReturnRequestController::class, 'show']);
    Route::patch('/{returnRequest}/status', [AdminReturnRequestController::class, 'updateStatus']);
});

Route::prefix('v1/admin/warehouse')->middleware(['auth:sanctum', 'can:manage-warehouse'])->group(function () {
    Route::get('/balances', [AdminWarehouseController::class, 'balances']);
    Route::get('/variants', [AdminWarehouseController::class, 'variants']);
    Route::get('/movements', [AdminWarehouseController::class, 'movements']);
    Route::post('/receipts', [AdminWarehouseController::class, 'receive']);
    Route::post('/reconciliations', [AdminWarehouseController::class, 'reconcile']);
});
