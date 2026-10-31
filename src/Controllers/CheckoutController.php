<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\G7plus\Paypal\Concerns\VerifiesOrderOwnership;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;
use Plugins\G7plus\Paypal\Exceptions\PaypalCheckoutException;
use Plugins\G7plus\Paypal\Http\Requests\CreateCheckoutRequest;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;
use Plugins\G7plus\Paypal\Services\PaypalClaimPaymentService;

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
     * @param  PaypalClaimPaymentService  $claimService  클레임 추가결제 PayPal 흐름
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 건 조회
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly GuestOrderAuthService $guestOrderAuthService,
        private readonly PaypalCheckoutService $checkoutService,
        private readonly PaypalClaimPaymentService $claimService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
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

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 결제 건의 원 주문으로 소유자를 확인하고 클레임 흐름을 탄다.
        if (ClaimPayment::isClaimPaymentNumber($orderId)) {
            return $this->storeClaimPayment($request, $orderId);
        }

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

    /**
     * 클레임 추가결제 건의 PayPal 주문을 만듭니다.
     *
     * @param  CreateCheckoutRequest  $request  검증된 요청
     * @param  string  $paymentNumber  클레임 결제번호 (CP…)
     * @return JsonResponse {approve_url, paypal_order_id}
     */
    private function storeClaimPayment(CreateCheckoutRequest $request, string $paymentNumber): JsonResponse
    {
        $payment = $this->claimPaymentRepository->findByPaymentNumber($paymentNumber);
        $order = $payment?->order;

        if (! $payment || ! $order || ! $this->requestOwnsOrder($request, $order, $this->guestOrderAuthService)) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.order_not_found', 404);
        }

        try {
            $result = $this->claimService->createCheckout(
                $payment,
                __('g7plus-paypal::messages.claim.description', ['number' => $paymentNumber]),
            );
        } catch (PaypalCheckoutException $e) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.'.$e->errorCode, $e->httpStatus, [
                'code' => $e->errorCode,
            ]);
        } catch (PaypalApiException $e) {
            Log::error('PayPal: claim checkout creation failed', [
                'payment_number' => $paymentNumber,
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
