<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\G7plus\Paypal\Concerns\RecordsPaymentFailure;
use Plugins\G7plus\Paypal\Services\PaypalApiService;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;

/**
 * PayPal 웹훅 수신.
 *
 * 모든 이벤트는 PayPal 서명 검증 API(verify-webhook-signature)를 통과해야 처리된다. 검증에는 운영자가 PayPal
 * 개발자 대시보드에서 웹훅을 만들 때 받은 Webhook ID 가 필요하다 — 설정되지 않았으면 모든 웹훅을 거절한다.
 *
 * 처리하는 이벤트:
 * - CHECKOUT.ORDER.APPROVED   구매자가 승인 후 창을 닫아 복귀하지 않은 경우 서버가 캡처를 마무리한다.
 * - PAYMENT.CAPTURE.COMPLETED 보류(PENDING)였던 캡처가 완료됨 → 결제완료.
 * - PAYMENT.CAPTURE.DENIED    보류 캡처가 거절됨 → 결제 실패 기록.
 * - PAYMENT.CAPTURE.REFUNDED / REVERSED  PayPal 대시보드 환불·지급 거절(차지백) — 주문을 자동으로 바꾸지 않고
 *   결제행에 기록하고 경고 로그를 남긴다(운영자가 대사한다).
 *
 * PayPal 은 2xx 가 아니면 최대 25회 재전송하므로, 처리 불가·무관 이벤트도 200 으로 답한다(서명 실패만 400).
 */
class WebhookController
{
    use RecordsPaymentFailure;

    /** PayPal 이 보내는 서명 헤더 → 검증 API 필드 */
    private const SIGNATURE_HEADERS = [
        'auth_algo' => 'PAYPAL-AUTH-ALGO',
        'cert_url' => 'PAYPAL-CERT-URL',
        'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
        'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG',
        'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
    ];

    /**
     * @param  OrderProcessingService  $orderService  주문 처리
     * @param  PaypalApiService  $api  PayPal API (서명 검증)
     * @param  PaypalCheckoutService  $checkoutService  PayPal 결제 흐름
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PaypalApiService $api,
        private readonly PaypalCheckoutService $checkoutService,
    ) {}

    /**
     * 웹훅을 받습니다.
     *
     * POST /plugins/g7plus-paypal/webhook
     *
     * @param  Request  $request  웹훅 요청 (원문 본문 그대로)
     * @return JsonResponse 처리 결과
     */
    public function handle(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $headers = [];
        foreach (self::SIGNATURE_HEADERS as $field => $header) {
            $headers[$field] = (string) $request->header($header, '');
        }

        if (! $this->api->verifyWebhookSignature($headers, $rawBody)) {
            Log::warning('PayPal: webhook signature verification failed', [
                'transmission_id' => $headers['transmission_id'],
                'webhook_id_configured' => $this->api->hasWebhookId(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'invalid_signature'], 400);
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event)) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $type = (string) ($event['event_type'] ?? '');
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];

        Log::info('PayPal: webhook received', [
            'event_id' => $event['id'] ?? null,
            'event_type' => $type,
            'resource_id' => $resource['id'] ?? null,
        ]);

        $status = match ($type) {
            'CHECKOUT.ORDER.APPROVED' => $this->onOrderApproved($resource),
            'PAYMENT.CAPTURE.COMPLETED' => $this->onCaptureCompleted($resource),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => $this->onCaptureDenied($resource, $type),
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => $this->onCaptureReversed($resource, $type, (string) ($event['id'] ?? '')),
            default => 'ignored',
        };

        return response()->json(['status' => $status], 200);
    }

    /**
     * 구매자 승인 이벤트 — 아직 캡처되지 않았으면 캡처합니다.
     *
     * @param  array<string, mixed>  $resource  PayPal 주문 객체
     * @return string 처리 결과
     */
    private function onOrderApproved(array $resource): string
    {
        $order = $this->findOrder((string) ($resource['purchase_units'][0]['custom_id'] ?? ''));
        $paypalOrderId = (string) ($resource['id'] ?? '');

        if (! $order || $paypalOrderId === '') {
            return 'order_not_found';
        }

        return $this->checkoutService->captureAndRecord($order, $paypalOrderId)->status;
    }

    /**
     * 캡처 완료 이벤트 — 보류였던 결제를 완료합니다.
     *
     * @param  array<string, mixed>  $resource  캡처 객체
     * @return string 처리 결과
     */
    private function onCaptureCompleted(array $resource): string
    {
        $order = $this->findOrder((string) ($resource['custom_id'] ?? ''));
        $captureId = (string) ($resource['id'] ?? '');

        if (! $order || $captureId === '') {
            return 'order_not_found';
        }

        $outcome = $this->checkoutService->completeCaptureFromWebhook($order, $captureId);

        return $outcome->status === 'failed' ? 'failed:'.$outcome->errorCode : $outcome->status;
    }

    /**
     * 캡처 거절 이벤트 — 보류 중이던 결제를 실패로 기록합니다.
     *
     * @param  array<string, mixed>  $resource  캡처 객체
     * @param  string  $type  이벤트 종류
     * @return string 처리 결과
     */
    private function onCaptureDenied(array $resource, string $type): string
    {
        $order = $this->findOrder((string) ($resource['custom_id'] ?? ''));

        if (! $order) {
            return 'order_not_found';
        }

        $knownCapture = (string) ($order->payment?->payment_meta['paypal_capture_id'] ?? '');
        if ($knownCapture === '' || ! hash_equals($knownCapture, (string) ($resource['id'] ?? ''))) {
            return 'capture_mismatch';
        }

        $this->recordPaymentFailure(
            $this->orderService,
            $order,
            'CAPTURE_DENIED',
            __('g7plus-paypal::messages.errors.capture_declined'),
            'payment_failed',
        );

        Log::warning('PayPal: pending capture denied', ['order_number' => $order->order_number, 'event' => $type]);

        return 'recorded';
    }

    /**
     * 환불·지급 거절 이벤트 — 결제행에 기록만 합니다.
     *
     * 이 플러그인이 낸 환불도 이 이벤트로 돌아온다. 쇼핑몰 장부는 환불 흐름이 이미 갱신했으므로 여기서 다시
     * 바꾸지 않는다. PayPal 대시보드에서 직접 낸 환불·차지백은 쇼핑몰이 모르는 돈의 이동이라 경고로 남긴다.
     *
     * @param  array<string, mixed>  $resource  환불 또는 캡처 객체
     * @param  string  $type  이벤트 종류
     * @param  string  $eventId  이벤트 ID
     * @return string 처리 결과
     */
    private function onCaptureReversed(array $resource, string $type, string $eventId): string
    {
        $order = $this->findOrder((string) ($resource['custom_id'] ?? $resource['invoice_id'] ?? ''));

        if (! $order || ! $order->payment) {
            return 'order_not_found';
        }

        $payment = $order->payment;
        $meta = $payment->payment_meta ?? [];
        $events = is_array($meta['paypal_events'] ?? null) ? $meta['paypal_events'] : [];

        foreach ($events as $recorded) {
            if (($recorded['event_id'] ?? null) === $eventId) {
                return 'duplicate';
            }
        }

        $events[] = [
            'event_id' => $eventId,
            'type' => $type,
            'resource_id' => $resource['id'] ?? null,
            'amount' => $resource['amount'] ?? null,
            'status' => $resource['status'] ?? null,
            'received_at' => now()->toIso8601String(),
        ];
        $meta['paypal_events'] = array_slice($events, -20);
        $payment->update(['payment_meta' => $meta]);

        $log = $type === 'PAYMENT.CAPTURE.REVERSED' ? 'critical' : 'info';
        Log::{$log}('PayPal: capture '.($type === 'PAYMENT.CAPTURE.REVERSED' ? 'reversed (chargeback)' : 'refunded'), [
            'order_number' => $order->order_number,
            'resource_id' => $resource['id'] ?? null,
            'amount' => $resource['amount'] ?? null,
        ]);

        return 'recorded';
    }

    /**
     * 주문번호로 주문을 찾습니다.
     *
     * @param  string  $orderNumber  주문번호 (PayPal custom_id)
     * @return Order|null 주문
     */
    private function findOrder(string $orderNumber): ?Order
    {
        return $orderNumber !== '' ? $this->orderService->findByOrderNumber($orderNumber) : null;
    }
}
