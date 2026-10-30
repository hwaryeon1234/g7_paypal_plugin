<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;
use Plugins\G7plus\Paypal\Services\PaypalApiService;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;
use Plugins\G7plus\Paypal\Support\PaypalCurrency;

/**
 * PayPal 환불 리스너.
 *
 * 관리자 주문 취소·반품 환불이 집행될 때 이커머스가 `sirsoft-ecommerce.payment.refund` 필터를 발화한다. 이 주문이
 * PayPal 결제면 캡처 환불 API 를 호출한다. 전액·부분 모두 금액을 명시해 보낸다 — PayPal 은 금액을 빼면 「남은 전액」
 * 을 환불하므로, 부분환불 이력이 있는 결제에서 의도와 다른 금액이 나가지 않게 한다.
 */
class PaymentRefundListener implements HookListenerInterface
{
    /** PayPal 이 환불을 받는 기간(일) — 캡처 후 180일 */
    private const REFUND_WINDOW_DAYS = 180;

    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.payment.supports_partial_cancel' => [
                'method' => 'supportsPartialCancel',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * PayPal 결제의 부분환불 가능 여부를 판정합니다.
     *
     * PayPal 은 캡처 후 180일까지 여러 번 나눠 환불할 수 있다. 그 기간이 지났거나 캡처가 아직 보류 중이면 막는다.
     *
     * @param  bool  $supported  현재까지의 판정
     * @param  OrderPayment|null  $payment  결제행
     * @return bool 부분환불 지원 여부
     */
    public function supportsPartialCancel(bool $supported, $payment = null): bool
    {
        if (! $supported || ! $payment instanceof OrderPayment || $payment->pg_provider !== PaypalCheckoutService::PROVIDER_ID) {
            return $supported;
        }

        if (($payment->payment_meta['paypal_capture_status'] ?? null) === 'PENDING') {
            return false;
        }

        return $payment->paid_at === null || $payment->paid_at->greaterThan(now()->subDays(self::REFUND_WINDOW_DAYS));
    }

    /**
     * PayPal 환불을 처리합니다.
     *
     * @param  array<string, mixed>  $result  환불 결과 (기본값)
     * @param  Order  $order  주문
     * @param  OrderPayment  $payment  결제 정보
     * @param  float  $refundAmount  환불 금액 (결제 통화 order_currency 주 단위)
     * @param  string|null  $reason  환불 사유
     * @param  OrderRefund|null  $refund  이번 환불 레코드 (멱등키 조립용)
     * @return array<string, mixed> 환불 결과 {success, handled, error_code, error_message, transaction_id}
     */
    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
        ?OrderRefund $refund = null,
    ): array {
        // 다른 PG 결제는 결과를 건드리지 않는다 — `handled` 로 「이 주문의 결제 플러그인이 응답했는가」를 가린다.
        if ($payment->pg_provider !== PaypalCheckoutService::PROVIDER_ID) {
            return $result;
        }

        $captureId = (string) ($payment->transaction_id ?? '');
        if ($captureId === '') {
            return $this->failure('MISSING_CAPTURE_ID', __('g7plus-paypal::messages.refund.missing_capture_id'));
        }

        $currency = strtoupper((string) ($payment->currency ?: ($order->currency_snapshot['order_currency'] ?? '')));

        try {
            $value = PaypalCurrency::majorToValue($refundAmount, $currency);
        } catch (InvalidArgumentException $e) {
            Log::error('PayPal: refund amount cannot be expressed', [
                'order_id' => $order->id,
                'currency' => $currency,
                'refund_amount' => $refundAmount,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('INVALID_REFUND_AMOUNT', __('g7plus-paypal::messages.refund.invalid_amount'));
        }

        if ((float) $value <= 0) {
            return $this->failure('INVALID_REFUND_AMOUNT', __('g7plus-paypal::messages.refund.invalid_amount'));
        }

        // 같은 환불 레코드·같은 금액의 재시도는 PayPal 이 첫 응답을 돌려준다. 운영자가 금액을 고쳐 다시 실행하면 다른 요청이다.
        $requestId = $refund?->id !== null
            ? 'g7-refund-'.$refund->id.'-'.$currency.'-'.$value
            : 'g7-refund-'.Str::uuid()->toString();

        HookManager::doAction('g7plus-paypal.payment.before_refund', $order, $payment, $value, $currency);

        try {
            $response = $this->api()->refundCapture(
                $captureId,
                $value,
                $currency,
                $reason ?: __('g7plus-paypal::messages.refund.default_note'),
                $requestId,
            );
        } catch (PaypalApiException $e) {
            Log::error('PayPal: refund failed', [
                'order_id' => $order->id,
                'capture_id' => $captureId,
                'value' => $value,
                'currency' => $currency,
                'code' => $e->publicCode(),
                'debug_id' => $e->getDebugId(),
            ]);

            return $this->failure($e->publicCode(), $this->refundErrorMessage($e));
        }

        $status = (string) ($response['status'] ?? '');
        if (! in_array($status, ['COMPLETED', 'PENDING'], true)) {
            Log::error('PayPal: refund not accepted', ['order_id' => $order->id, 'status' => $status]);

            return $this->failure('REFUND_'.($status ?: 'UNKNOWN'), __('g7plus-paypal::messages.refund.not_accepted'));
        }

        HookManager::doAction('g7plus-paypal.payment.after_refund', $order, $payment, $response);

        Log::info('PayPal: refund succeeded', [
            'order_id' => $order->id,
            'capture_id' => $captureId,
            'refund_id' => $response['id'] ?? null,
            'value' => $value,
            'currency' => $currency,
            'status' => $status,
        ]);

        return [
            'success' => true,
            'handled' => true,
            'error_code' => null,
            'error_message' => null,
            'transaction_id' => $response['id'] ?? $captureId,
        ];
    }

    /**
     * 실패 결과를 만듭니다.
     *
     * @param  string  $code  오류 코드
     * @param  string  $message  오류 메시지
     * @return array<string, mixed> 환불 결과
     */
    private function failure(string $code, string $message): array
    {
        return [
            'success' => false,
            'handled' => true,
            'error_code' => $code,
            'error_message' => $message,
            'transaction_id' => null,
        ];
    }

    /**
     * PayPal 환불 오류를 운영자가 읽을 메시지로 바꿉니다.
     *
     * @param  PaypalApiException  $e  PayPal 오류
     * @return string 메시지
     */
    private function refundErrorMessage(PaypalApiException $e): string
    {
        // PayPal 에 닿기 전에 실패한 경우 — 운영자가 바로 조치할 수 있게 원인을 그대로 알린다.
        $setupErrors = [
            'NOT_CONFIGURED' => 'not_configured',
            'AUTHENTICATION_FAILURE' => 'auth_failed',
            'CONNECTION_ERROR' => 'connection_failed',
        ];
        if (isset($setupErrors[$e->getErrorName()])) {
            return __('g7plus-paypal::messages.errors.'.$setupErrors[$e->getErrorName()]);
        }

        foreach (['REFUND_AMOUNT_EXCEEDED', 'CAPTURE_FULLY_REFUNDED', 'REFUND_TIME_LIMIT_EXCEEDED', 'TRANSACTION_REFUSED', 'INSUFFICIENT_FUNDS'] as $issue) {
            if ($e->hasIssue($issue)) {
                return __('g7plus-paypal::messages.refund.issues.'.$issue);
            }
        }

        return __('g7plus-paypal::messages.refund.api_error', ['code' => $e->publicCode()]);
    }

    /**
     * API 서비스를 가져옵니다.
     *
     * @return PaypalApiService PayPal API
     */
    protected function api(): PaypalApiService
    {
        return app(PaypalApiService::class);
    }
}
