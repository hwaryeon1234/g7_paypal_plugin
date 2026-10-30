<?php

use App\Support\SiteUrl;
use Illuminate\Support\Facades\Route;
use Plugins\G7plus\Paypal\Controllers\CheckoutController;
use Plugins\G7plus\Paypal\Controllers\PaymentCloseReportController;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;
use Plugins\G7plus\Paypal\Support\PaypalCurrency;

/*
|--------------------------------------------------------------------------
| PayPal Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/g7plus-paypal (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

// PayPal 결제 시작 — 주문 소유자(회원 Sanctum / 비회원 X-Guest-Order-Token)만 PayPal 주문을 만들 수 있다.
Route::post('/payment/checkout', [CheckoutController::class, 'store'])
    ->middleware('optional.sanctum')
    ->name('payment.checkout');

// 결제 취소·실패 보고 — 주문 소유자 확인 후 기록. 브라우저 복귀(cancel)는 상태를 바꾸지 않으므로 이 경로가 유일하다.
Route::post('/payment/close-report', [PaymentCloseReportController::class, 'store'])
    ->middleware('optional.sanctum')
    ->name('payment.close-report');

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 설정 화면 안내 정보 — PayPal 개발자 대시보드에 등록할 웹훅 URL(사이트 주소 기준 전체 URL)과 지원 통화 목록.
    Route::get('/meta', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'webhook_url' => SiteUrl::absolute('/plugins/g7plus-paypal/webhook'),
                'webhook_events' => [
                    'CHECKOUT.ORDER.APPROVED',
                    'PAYMENT.CAPTURE.COMPLETED',
                    'PAYMENT.CAPTURE.DENIED',
                    'PAYMENT.CAPTURE.REFUNDED',
                    'PAYMENT.CAPTURE.REVERSED',
                ],
                'currencies' => array_map(
                    static fn (string $code, int $decimals) => [
                        'code' => $code,
                        'decimals' => $decimals,
                        'in_country_only' => in_array($code, PaypalCurrency::IN_COUNTRY_ONLY, true),
                    ],
                    array_keys(PaypalCurrency::SUPPORTED),
                    array_values(PaypalCurrency::SUPPORTED),
                ),
                'allowed_currencies' => app(PaypalCheckoutService::class)->allowedCurrencies(),
            ],
        ]);
    })->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        // 이름에 `.webhook.` 마디를 두지 않는다 — 코어 IP 제한 검사가 그 이름을 외부 인바운드 콜백으로 분류한다.
        ->name('meta');
});
