<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Services;

use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\G7plus\Paypal\Concerns\RecordsPaymentFailure;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;
use Plugins\G7plus\Paypal\Support\CaptureOutcome;

/**
 * 보류(PENDING) 캡처 정리 — 웹훅을 놓친 결제의 안전망.
 *
 * 보류로 기록된 지 일정 시간이 지난 결제행을 골라 PayPal 에서 캡처 상태를 다시 조회한다.
 * 완료됐으면 웹훅과 같은 경로(`completeCaptureFromWebhook`)로 대조·완료하고, 거절됐으면 실패로 기록한다.
 */
class PaypalPendingCaptureService
{
    use RecordsPaymentFailure;

    /** 보류 기록 후 이 시간(분)이 지난 건만 본다 — 막 보류된 건은 웹훅이 곧 처리한다. */
    public const MIN_AGE_MINUTES = 30;

    /**
     * @param  PaypalApiService  $api  PayPal API
     * @param  PaypalCheckoutService  $checkoutService  PayPal 결제 흐름
     * @param  OrderProcessingService  $orderService  주문 처리
     */
    public function __construct(
        private readonly PaypalApiService $api,
        private readonly PaypalCheckoutService $checkoutService,
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * 보류 캡처를 정리합니다.
     *
     * @param  int  $limit  최대 건수
     * @return array{checked:int, completed:int, failed:int, pending:int, errors:int} 집계
     */
    public function reconcile(int $limit = 50): array
    {
        $summary = ['checked' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];

        if (! $this->api->isConfigured()) {
            return $summary;
        }

        $payments = OrderPayment::query()
            ->with('order')
            ->where('pg_provider', PaypalCheckoutService::PROVIDER_ID)
            ->where('payment_status', PaymentStatusEnum::IN_PROGRESS->value)
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->where('updated_at', '<=', now()->subMinutes(self::MIN_AGE_MINUTES))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($payments as $payment) {
            if (($payment->payment_meta['paypal_capture_status'] ?? null) !== 'PENDING' || ! $payment->order) {
                continue;
            }

            $summary['checked']++;
            $summary[$this->reconcileOne($payment)]++;
        }

        if ($summary['checked'] > 0) {
            Log::info('PayPal: pending captures reconciled', $summary);
        }

        return $summary;
    }

    /**
     * 결제행 하나를 정리합니다.
     *
     * @param  OrderPayment  $payment  보류 결제행
     * @return string completed | failed | pending | errors
     */
    private function reconcileOne(OrderPayment $payment): string
    {
        $order = $payment->order;
        $captureId = (string) $payment->transaction_id;

        try {
            $status = (string) ($this->api->getCapture($captureId)['status'] ?? '');
        } catch (PaypalApiException $e) {
            Log::warning('PayPal: pending capture lookup failed', [
                'order_number' => $order->order_number,
                'capture_id' => $captureId,
                'code' => $e->publicCode(),
            ]);

            return 'errors';
        }

        if ($status === 'COMPLETED') {
            $outcome = $this->checkoutService->completeCaptureFromWebhook($order, $captureId);

            return match ($outcome->status) {
                CaptureOutcome::PAID, CaptureOutcome::ALREADY_RECORDED => 'completed',
                CaptureOutcome::FAILED => $outcome->retryable ? 'errors' : 'failed',
                default => 'pending',
            };
        }

        if (in_array($status, ['DECLINED', 'FAILED'], true)) {
            $this->recordPaymentFailure(
                $this->orderService,
                $order,
                'CAPTURE_DENIED',
                __('g7plus-paypal::messages.errors.capture_declined'),
                'payment_failed',
            );

            return 'failed';
        }

        return 'pending';
    }
}
