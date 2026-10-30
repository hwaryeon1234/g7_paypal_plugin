<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\G7plus\Paypal\Controllers\PaymentCallbackController;
use Plugins\G7plus\Paypal\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| PayPal Plugin Web Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /plugins/g7plus-paypal (PluginRouteServiceProvider 자동 적용)
| 미들웨어: web (PluginRouteServiceProvider 자동 적용)
|
*/

// PayPal 승인 후 브라우저 복귀 (PayPal → 브라우저 리다이렉트) — 서버가 캡처한다.
Route::get('/payment/return', [PaymentCallbackController::class, 'return'])
    ->name('payment.return');

// PayPal 화면에서 취소 후 브라우저 복귀 — 상태를 바꾸지 않고 실패 화면으로만 보낸다.
Route::get('/payment/cancel', [PaymentCallbackController::class, 'cancel'])
    ->name('payment.cancel');

// 웹훅 (PayPal 서버 → POST). PayPal 은 CSRF 토큰을 보내지 않으므로 면제하고, 서명 검증으로 위조를 막는다.
Route::withoutMiddleware([ValidateCsrfToken::class])
    ->group(function () {
        Route::post('/webhook', [WebhookController::class, 'handle'])
            ->name('webhook.receive');
    });
