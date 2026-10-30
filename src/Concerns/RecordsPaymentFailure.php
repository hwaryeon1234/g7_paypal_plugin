<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Concerns;

use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 결제 실패·결제창 닫힘을 주문에 반영하는 공통 절차.
 *
 * 이 절차를 부르는 경로는 두 가지뿐이다 — 주문 소유자를 확인한 close-report, 그리고 PayPal 서명을
 * 검증한 웹훅(PAYMENT.CAPTURE.DENIED). 인증도 서명도 없는 브라우저 복귀(cancel)는 주문 상태를 바꾸지 않는다.
 */
trait RecordsPaymentFailure
{
    /**
     * 결제 실패를 주문과 결제행에 기록합니다.
     *
     * @param  OrderProcessingService  $orderService  주문 처리 서비스
     * @param  Order  $order  대상 주문
     * @param  string  $failureCode  실패 코드
     * @param  string  $failureMessage  실패 메시지
     * @param  string  $failureStage  실패 단계 (window_closed | payment_failed)
     * @return Order 반영된 주문
     */
    protected function recordPaymentFailure(
        OrderProcessingService $orderService,
        Order $order,
        string $failureCode,
        string $failureMessage,
        string $failureStage,
    ): Order {
        if (! $order->order_status?->isBeforePayment() || ($order->payment?->isPaid() ?? false)) {
            return $order;
        }

        $failed = $orderService->failPayment($order, $failureCode, $failureMessage);
        $cancelled = $orderService->recordPaymentCancellation($failed, $failureCode, $failureMessage);

        /** @var OrderPayment|null $payment */
        $payment = $cancelled->payment;
        if (! $payment || ! $payment->exists) {
            return $cancelled;
        }

        $now = now()->toIso8601String();
        $meta = $payment->payment_meta ?? [];
        $history = is_array($meta['failure_history'] ?? null) ? $meta['failure_history'] : [];
        $history[] = ['code' => $failureCode, 'message' => $failureMessage, 'stage' => $failureStage, 'failed_at' => $now];

        $meta['failure_history'] = array_slice($history, -5);
        $meta['failure_source'] = 'paypal';
        $meta['failure_code'] = $failureCode;
        $meta['failure_message'] = $failureMessage;
        $meta['failure_stage'] = $failureStage;
        $meta['failed_at'] = $now;

        $payment->update([
            'pg_provider' => 'paypal',
            'payment_status' => PaymentStatusEnum::CANCELLED->value,
            'payment_meta' => $meta,
        ]);

        return $cancelled->fresh('payment') ?? $cancelled;
    }
}
