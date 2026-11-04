<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;

/**
 * 관리자 주문 상세의 PayPal 거래 정보.
 *
 * 이커머스 주문 API 는 결제행의 payment_meta 를 내보내지 않는다. PayPal 수수료·판매자 보호·환불/차지백 기록은
 * 이 플러그인이 payment_meta 에 남긴 값이라, 주문 상세 패널이 이 API 로 읽는다.
 */
class AdminOrderPaymentController
{
    /**
     * @param  OrderProcessingService  $orderService  주문 조회
     */
    public function __construct(private readonly OrderProcessingService $orderService) {}

    /**
     * 주문의 PayPal 거래 정보를 반환합니다.
     *
     * GET /api/plugins/g7plus-paypal/admin/orders/{orderNumber}/payment
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 거래 정보 (PayPal 결제가 아니면 data.is_paypal=false)
     */
    public function show(string $orderNumber): JsonResponse
    {
        $order = $this->orderService->findByOrderNumber($orderNumber);
        if (! $order) {
            return ResponseHelper::pluginError('g7plus-paypal', 'messages.errors.order_not_found', 404);
        }

        $payment = $order->payment;
        if (! $payment || $payment->pg_provider !== PaypalCheckoutService::PROVIDER_ID) {
            return ResponseHelper::success('common.success', ['is_paypal' => false]);
        }

        $meta = $payment->payment_meta ?? [];
        $breakdown = is_array($meta['paypal_seller_breakdown'] ?? null) ? $meta['paypal_seller_breakdown'] : [];
        $events = is_array($meta['paypal_events'] ?? null) ? $meta['paypal_events'] : [];
        $mode = (string) ($meta['paypal_mode'] ?? '');
        $captureId = (string) ($meta['paypal_capture_id'] ?? $payment->transaction_id ?? '');

        return ResponseHelper::success('common.success', [
            'is_paypal' => true,
            'mode' => $mode,
            'paypal_order_id' => $meta['paypal_order_id'] ?? null,
            'capture_id' => $captureId !== '' ? $captureId : null,
            'capture_status' => $meta['paypal_capture_status'] ?? null,
            'pending_reason' => $meta['paypal_pending_reason'] ?? null,
            'charge' => $meta['paypal_charge'] ?? null,
            'gross' => $this->money($breakdown['gross_amount'] ?? null),
            'fee' => $this->money($breakdown['paypal_fee'] ?? null),
            'net' => $this->money($breakdown['net_amount'] ?? null),
            'seller_protection' => $meta['paypal_seller_protection'] ?? null,
            'payer_email' => $meta['paypal_payer']['email'] ?? null,
            'payer_country' => $meta['paypal_payer']['country'] ?? null,
            'events' => array_values(array_map(fn (array $e) => [
                'type' => $e['type'] ?? null,
                'resource_id' => $e['resource_id'] ?? null,
                'amount' => $this->money($e['amount'] ?? null),
                'status' => $e['status'] ?? null,
                'received_at' => $e['received_at'] ?? null,
            ], array_filter($events, 'is_array'))),
            'dashboard_url' => $captureId !== ''
                ? ($mode === 'sandbox' ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com').'/activity/payment/'.rawurlencode($captureId)
                : null,
        ]);
    }

    /**
     * PayPal 금액 객체를 「12.50 USD」 문자열로 바꿉니다.
     *
     * @param  mixed  $amount  {currency_code, value}
     * @return string|null 표시 문자열
     */
    private function money(mixed $amount): ?string
    {
        if (! is_array($amount) || ! isset($amount['value'], $amount['currency_code'])) {
            return null;
        }

        return $amount['value'].' '.$amount['currency_code'];
    }
}
