<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\G7plus\Paypal\Concerns\RecordsPaymentFailure;
use Plugins\G7plus\Paypal\Concerns\VerifiesOrderOwnership;
use Plugins\G7plus\Paypal\Http\Requests\PaymentCloseReportRequest;

/**
 * PayPal 결제 취소·실패 보고.
 *
 * 브라우저 복귀(/payment/cancel)는 인증이 없어 주문 상태를 바꾸지 않는다. 주문을 실패로 전이시키는 브라우저 경로는
 * 주문 소유자(회원 로그인 / 비회원 조회 토큰)를 확인하는 이 엔드포인트 하나뿐이다 — 번들 PG 플러그인들과 같은 계약.
 */
class PaymentCloseReportController
{
    use RecordsPaymentFailure;
    use VerifiesOrderOwnership;

    private const DEFAULT_CODE = 'USER_CANCEL';

    /**
     * @param  OrderProcessingService  $orderService  주문 처리
     * @param  GuestOrderAuthService  $guestOrderAuthService  비회원 조회 토큰 검증
     * @param  CurrencyConversionService  $currencyService  청구액 계산
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly GuestOrderAuthService $guestOrderAuthService,
        private readonly CurrencyConversionService $currencyService,
    ) {}

    /**
     * 결제 취소·실패를 기록합니다.
     *
     * POST /api/plugins/g7plus-paypal/payment/close-report
     *
     * @param  PaymentCloseReportRequest  $request  검증된 요청
     * @return JsonResponse 처리 결과
     */
    public function store(PaymentCloseReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $orderId = (string) $validated['orderId'];

        $rateLimitKey = 'g7plus-paypal:close-report:'.sha1(($request->ip() ?? '').'|'.$orderId);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.too_many_attempts', 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->orderService->findByOrderNumber($orderId);
        if (! $order) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.order_not_found', 404);
        }

        if (! $order->order_status->isBeforePayment()) {
            return ResponseHelper::success('common.success', ['status' => 'ignored', 'reason' => 'order_not_payable']);
        }

        // 복귀·웹훅 캡처와 경쟁할 수 있다 — 결제가 이미 성립했거나 보류 중이면 실패로 덮지 않는다.
        $meta = $order->payment?->payment_meta ?? [];
        if ($order->payment?->isPaid() || ! empty($meta['paypal_capture_id'])) {
            return ResponseHelper::success('common.success', ['status' => 'ignored', 'reason' => 'payment_captured']);
        }

        if (! $this->requestOwnsOrder($request, $order, $this->guestOrderAuthService)) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.ownership_failed', 403);
        }

        try {
            $expected = $this->currencyService->resolveOrderPaymentChargeAmount($order);
        } catch (InvalidArgumentException) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.currency_not_allowed', 422);
        }

        if ((int) $validated['amount'] !== $expected) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.amount_mismatch', 422);
        }

        $code = trim((string) ($validated['code'] ?? ''));
        $reason = trim((string) ($validated['reason'] ?? ''));

        $this->recordPaymentFailure(
            $this->orderService,
            $order,
            $code !== '' ? $code : self::DEFAULT_CODE,
            $reason !== '' ? $reason : __('g7plus-paypal::messages.errors.USER_CANCEL'),
            $code === '' || $code === self::DEFAULT_CODE ? 'window_closed' : 'payment_failed',
        );

        return ResponseHelper::success('common.success', ['status' => 'recorded']);
    }
}
