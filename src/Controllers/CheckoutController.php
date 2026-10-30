<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\G7plus\Paypal\Concerns\VerifiesOrderOwnership;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;
use Plugins\G7plus\Paypal\Exceptions\PaypalCheckoutException;
use Plugins\G7plus\Paypal\Http\Requests\CreateCheckoutRequest;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;

/**
 * PayPal 결제 시작 API.
 *
 * 체크아웃이 주문을 만든 직후 프론트 핸들러가 부른다. 서버가 주문 스냅샷의 청구액으로 PayPal 주문을 만들고
 * 승인 주소를 돌려주면, 브라우저는 그 주소로 이동한다.
 */
class CheckoutController
{
    use VerifiesOrderOwnership;

    /**
     * @param  OrderProcessingService  $orderService  주문 조회
     * @param  GuestOrderAuthService  $guestOrderAuthService  비회원 조회 토큰 검증
     * @param  PaypalCheckoutService  $checkoutService  PayPal 결제 흐름
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly GuestOrderAuthService $guestOrderAuthService,
        private readonly PaypalCheckoutService $checkoutService,
    ) {}

    /**
     * PayPal 주문을 만들고 승인 주소를 반환합니다.
     *
     * POST /api/plugins/g7plus-paypal/payment/checkout
     *
     * @param  CreateCheckoutRequest  $request  검증된 요청
     * @return JsonResponse {approve_url, paypal_order_id}
     */
    public function store(CreateCheckoutRequest $request): JsonResponse
    {
        $orderId = (string) $request->validated('orderId');

        $rateLimitKey = 'g7plus-paypal:checkout:'.sha1(($request->ip() ?? '').'|'.$orderId);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.too_many_attempts', 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->orderService->findByOrderNumber($orderId);

        // 주문이 없는 것과 남의 주문인 것을 구분하지 않는다 — 주문번호 존재 여부를 알려 주지 않기 위해서다.
        if (! $order || ! $this->requestOwnsOrder($request, $order, $this->guestOrderAuthService)) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.order_not_found', 404);
        }

        try {
            $result = $this->checkoutService->createCheckout($order);
        } catch (PaypalCheckoutException $e) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.'.$e->errorCode, $e->httpStatus, [
                'code' => $e->errorCode,
            ]);
        } catch (PaypalApiException $e) {
            Log::error('PayPal: checkout creation failed', [
                'order_number' => $orderId,
                'code' => $e->publicCode(),
                'debug_id' => $e->getDebugId(),
            ]);

            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.create_failed', 502, [
                'code' => $e->publicCode(),
            ]);
        }

        return ResponseHelper::pluginSuccess('g7plus-paypal', 'messages.checkout_created', $result);
    }
}
