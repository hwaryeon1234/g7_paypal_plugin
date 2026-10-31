<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;
use Plugins\G7plus\Paypal\Services\PaypalApiService;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;
use Plugins\G7plus\Paypal\Services\PaypalClaimPaymentService;
use Plugins\G7plus\Paypal\Support\PaypalCurrency;

/**
 * 클레임(교환·반품) 추가결제 리스너 — 승인·환급·보상.
 *
 * 이커머스 `claim_payment.*` 필터에 답한다. 계약은 이커머스 `docs/api/claim-payments.md` 「결제 플러그인 연동 계약」
 * 절이 단일 출처다. 이 플러그인 건(`pg_provider = paypal`)이 아니면 결과를 건드리지 않는다(`handled` 계약).
 */
class ClaimPaymentListener implements HookListenerInterface
{
    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.claim_payment.approve' => [
                'method' => 'approve',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.claim_payment.refund' => [
                'method' => 'refund',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.claim_payment.void_approval' => [
                'method' => 'voidApproval',
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
     * 클레임 결제 건을 PayPal 에서 확정합니다.
     *
     * 콜백 파라미터는 PayPal 복귀 쿼리의 `token`(PayPal 주문 ID)이다.
     *
     * @param  array<string, mixed>  $result  기본 결과
     * @param  ClaimPayment  $payment  결제 건
     * @param  array<string, mixed>  $callback  콜백 파라미터
     * @return array<string, mixed> 승인 결과
     */
    public function approve(array $result, ClaimPayment $payment, array $callback): array
    {
        if ($payment->pg_provider !== PaypalCheckoutService::PROVIDER_ID) {
            return $result;
        }

        return app(PaypalClaimPaymentService::class)->approve($payment, (string) ($callback['token'] ?? ''));
    }

    /**
     * 클레임 결제 건을 PayPal 에서 환급합니다.
     *
     * @param  array<string, mixed>  $result  기본 결과
     * @param  ClaimPayment  $payment  결제 건
     * @param  float  $amount  환급액 (결제 통화 주 단위)
     * @param  string|null  $reason  환급 사유
     * @param  string  $idempotencyKey  멱등키
     * @return array<string, mixed> 환급 결과 {handled, success, transaction_id, error_code, error_message}
     */
    public function refund(array $result, ClaimPayment $payment, float $amount = 0.0, ?string $reason = null, string $idempotencyKey = ''): array
    {
        if ($payment->pg_provider !== PaypalCheckoutService::PROVIDER_ID) {
            return $result;
        }

        $captureId = (string) ($payment->transaction_id ?? '');
        if ($captureId === '') {
            return $this->refundFailure('MISSING_CAPTURE_ID', __('g7plus-paypal::messages.refund.missing_capture_id'));
        }

        // 환급액의 통화는 결제 통화다 — 결제 건의 currency_code(기준 통화)로 판단하지 말라는 것이 계약이다.
        $currency = strtoupper((string) (app(ClaimPaymentService::class)->resolvePaymentCharge($payment)['currency'] ?? ''));

        try {
            $value = PaypalCurrency::majorToValue($amount, $currency);
        } catch (InvalidArgumentException) {
            return $this->refundFailure('INVALID_REFUND_AMOUNT', __('g7plus-paypal::messages.refund.invalid_amount'));
        }

        if ((float) $value <= 0) {
            return $this->refundFailure('INVALID_REFUND_AMOUNT', __('g7plus-paypal::messages.refund.invalid_amount'));
        }

        try {
            $response = app(PaypalApiService::class)->refundCapture(
                $captureId,
                $value,
                $currency,
                $reason ?: __('g7plus-paypal::messages.refund.default_note'),
                $idempotencyKey !== '' ? $idempotencyKey : 'g7-claimrefund-'.$payment->id.'-'.$currency.'-'.$value,
            );
        } catch (PaypalApiException $e) {
            Log::error('PayPal: claim refund failed', [
                'payment_number' => $payment->payment_number,
                'capture_id' => $captureId,
                'value' => $value,
                'currency' => $currency,
                'code' => $e->publicCode(),
                'debug_id' => $e->getDebugId(),
            ]);

            return $this->refundFailure($e->publicCode(), $e->getMessage());
        }

        $status = (string) ($response['status'] ?? '');
        if (! in_array($status, ['COMPLETED', 'PENDING'], true)) {
            return $this->refundFailure('REFUND_'.($status ?: 'UNKNOWN'), __('g7plus-paypal::messages.refund.not_accepted'));
        }

        Log::info('PayPal: claim refund succeeded', [
            'payment_number' => $payment->payment_number,
            'refund_id' => $response['id'] ?? null,
            'value' => $value,
            'currency' => $currency,
        ]);

        return [
            'handled' => true,
            'success' => true,
            'transaction_id' => $response['id'] ?? null,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * 모듈이 승인 뒤 거절한 건의 캡처를 되돌립니다.
     *
     * @param  array<string, mixed>  $result  기본 결과
     * @param  ClaimPayment  $payment  거절된 결제 건
     * @param  array<string, mixed>  $callback  콜백 파라미터
     * @param  array<string, mixed>  $approveResult  승인 필터 결과
     * @return array<string, mixed> 보상 결과
     */
    public function voidApproval(array $result, ClaimPayment $payment, array $callback = [], array $approveResult = []): array
    {
        if ($payment->pg_provider !== PaypalCheckoutService::PROVIDER_ID) {
            return $result;
        }

        $captureId = (string) ($approveResult['transaction_id'] ?? '');
        if ($captureId === '') {
            return ['handled' => true, 'success' => false, 'error_code' => 'MISSING_CAPTURE_ID', 'error_message' => null];
        }

        $currency = (string) ($approveResult['raw_response']['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] ?? 'USD');
        $voided = app(PaypalClaimPaymentService::class)->voidCapture($captureId, $currency, $payment);

        return ['handled' => true, 'success' => $voided, 'error_code' => $voided ? null : 'PG_API_ERROR', 'error_message' => null];
    }

    /**
     * 환급 실패 결과를 만듭니다.
     *
     * @param  string  $code  오류 코드
     * @param  string  $message  오류 메시지
     * @return array<string, mixed> 환급 결과
     */
    private function refundFailure(string $code, string $message): array
    {
        return ['handled' => true, 'success' => false, 'transaction_id' => null, 'error_code' => $code, 'error_message' => $message];
    }
}
