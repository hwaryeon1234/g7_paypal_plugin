<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Controllers;

use App\Support\SiteUrl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentCallback;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Plugins\G7plus\Paypal\Concerns\SerializesPaymentCallbacks;
use Plugins\G7plus\Paypal\Http\Requests\ReturnCallbackRequest;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;
use Plugins\G7plus\Paypal\Support\CaptureOutcome;
use Plugins\G7plus\Paypal\Support\ShopRedirectUrl;

/**
 * PayPal 승인·취소 후 브라우저 복귀 컨트롤러.
 */
class PaymentCallbackController
{
    use SerializesPaymentCallbacks;

    /**
     * @param  OrderProcessingService  $orderService  주문 조회
     * @param  PaypalCheckoutService  $checkoutService  PayPal 결제 흐름
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 건 조회
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PaypalCheckoutService $checkoutService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
    ) {}

    /**
     * 구매자가 PayPal 에서 승인하고 돌아왔을 때 캡처합니다.
     *
     * GET /plugins/g7plus-paypal/payment/return?order={주문번호}&token={PayPal 주문 ID}&PayerID=...
     *
     * @param  ReturnCallbackRequest  $request  검증된 요청
     * @return RedirectResponse 결과 화면으로 리다이렉트
     */
    public function return(ReturnCallbackRequest $request): RedirectResponse
    {
        $orderNumber = (string) $request->validated('order');
        $token = (string) $request->validated('token');

        if (ClaimPayment::isClaimPaymentNumber($orderNumber)) {
            return $this->returnClaimPayment($orderNumber, $token);
        }

        $order = $this->orderService->findByOrderNumber($orderNumber);

        if (! $order) {
            Log::warning('PayPal: return for unknown order', ['order_number' => $orderNumber]);

            return redirect(ShopRedirectUrl::fail(['error' => 'order_not_found']));
        }

        $outcome = $this->checkoutService->captureAndRecord($order, $token, $request);

        if ($outcome->isSuccessful()) {
            return redirect(ShopRedirectUrl::success($orderNumber));
        }

        if ($outcome->status === CaptureOutcome::RETRY_APPROVAL && $outcome->redirectUrl !== null) {
            return redirect()->away($outcome->redirectUrl);
        }

        $code = $outcome->errorCode ?? 'capture_failed';

        return redirect(ShopRedirectUrl::fail([
            'error' => $code,
            'message' => $this->errorMessage($code),
            'orderId' => $orderNumber,
        ]));
    }

    /**
     * 실패 코드에 맞는 안내 문구를 찾습니다. PayPal issue 코드처럼 번역이 없으면 일반 실패 문구를 씁니다.
     *
     * @param  string  $code  실패 코드
     * @return string 안내 문구
     */
    private function errorMessage(string $code): string
    {
        $key = 'g7plus-paypal::messages.errors.'.$code;
        $message = __($key);

        return $message !== $key ? $message : __('g7plus-paypal::messages.errors.capture_failed');
    }

    /**
     * 구매자가 PayPal 화면에서 취소하고 돌아왔을 때 실패 화면으로 보냅니다.
     *
     * 이 요청은 인증도 서명도 없다 — 주문 상태를 바꾸지 않는다. 실패 기록은 프론트가 주문 소유자 증명과 함께
     * 보내는 close-report 가 한다.
     *
     * GET /plugins/g7plus-paypal/payment/cancel?order={주문번호}&token={PayPal 주문 ID}
     *
     * @param  ReturnCallbackRequest  $request  검증된 요청
     * @return RedirectResponse 실패 화면으로 리다이렉트
     */
    public function cancel(ReturnCallbackRequest $request): RedirectResponse
    {
        $orderNumber = (string) $request->validated('order');

        Log::info('PayPal: buyer cancelled on PayPal', ['order_number' => $orderNumber]);

        // 클레임 추가결제 — 의도된 중단은 실패가 아니다. 결제 건은 결제 대기로 남아 신청서 상세에서 다시 낸다.
        if (ClaimPayment::isClaimPaymentNumber($orderNumber)) {
            $payment = $this->claimPaymentRepository->findByPaymentNumber($orderNumber);

            if ($payment) {
                return redirect(SiteUrl::absolute(ClaimPaymentRedirect::cancelled($payment)));
            }
        }

        return redirect(ShopRedirectUrl::fail([
            'error' => 'USER_CANCEL',
            'message' => __('g7plus-paypal::messages.errors.USER_CANCEL'),
            'orderId' => $orderNumber,
        ]));
    }

    /**
     * 클레임 추가결제 건의 PayPal 승인 복귀를 처리합니다.
     *
     * 승인은 이커머스 `ClaimPaymentCallback::approveAndRedirect()` 에 맡긴다 — 그 안에서 `claim_payment.approve`
     * 필터(ClaimPaymentListener)가 PayPal 대조·캡처를 하고, 모듈이 금액을 다시 대조한다. 승인 뒤 거절의 보상은
     * `claim_payment.void_approval` 필터가 맡는다. 같은 결제번호의 복귀가 겹치면 이중 캡처 시도를 막기 위해 직렬화한다.
     *
     * @param  string  $paymentNumber  클레임 결제번호 (CP…)
     * @param  string  $token  PayPal 주문 ID
     * @return RedirectResponse 신청서 상세로 리다이렉트
     */
    private function returnClaimPayment(string $paymentNumber, string $token): RedirectResponse
    {
        $payment = $this->claimPaymentRepository->findByPaymentNumber($paymentNumber);

        if (! $payment) {
            return redirect(ShopRedirectUrl::fail(['error' => 'claim_payment_not_found']));
        }

        $lock = null;

        try {
            $lock = $this->acquireOrderCallbackLock($paymentNumber);

            return ClaimPaymentCallback::approveAndRedirect($payment, ['token' => $token]);
        } catch (LockTimeoutException) {
            $latest = $this->claimPaymentRepository->findByPaymentNumber($paymentNumber) ?? $payment;

            return redirect(SiteUrl::absolute($latest->isPaid()
                ? ClaimPaymentRedirect::success($latest)
                : ClaimPaymentRedirect::fail($latest, 'callback_locked')));
        } finally {
            $this->releaseOrderCallbackLock($lock);
        }
    }
}
