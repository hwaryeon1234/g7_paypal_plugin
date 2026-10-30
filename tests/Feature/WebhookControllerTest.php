<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * PayPal 웹훅 — 서명 검증을 통과한 이벤트만 처리한다.
 */
class WebhookControllerTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    private const URL = '/plugins/g7plus-paypal/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();
    }

    /**
     * 서명 헤더를 실어 웹훅을 보냅니다.
     *
     * @param  array<string, mixed>  $event  이벤트 본문
     * @return TestResponse 응답
     */
    private function sendWebhook(array $event): TestResponse
    {
        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-30T00:00:00Z',
        ], json_encode($event, JSON_UNESCAPED_SLASHES));
    }

    /**
     * 서명 검증 결과 페이크.
     *
     * @param  string  $status  SUCCESS | FAILURE
     * @param  array<string, mixed>  $extra  추가 페이크
     * @return void 반환값 없음
     */
    private function fakeVerification(string $status, array $extra = []): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $status]),
        ] + $extra);
    }

    public function test_forged_webhook_is_rejected(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-W1')]);
        $this->fakeVerification('FAILURE');

        $this->sendWebhook([
            'id' => 'WH-1',
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => 'PPO-W1', 'purchase_units' => [['custom_id' => $order->order_number]]],
        ])->assertStatus(400);

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/capture'));
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->fresh()->order_status);
    }

    public function test_signature_check_sends_the_raw_event_and_webhook_id(): void
    {
        $this->fakeVerification('SUCCESS');

        $this->sendWebhook(['id' => 'WH-2', 'event_type' => 'BILLING.PLAN.CREATED', 'resource' => ['href' => 'https://x/y']])
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/verify-webhook-signature')) {
                return false;
            }

            $body = $r->body();

            return str_contains($body, '"webhook_id":"WH-TEST-1"')
                && str_contains($body, '"webhook_event":{"id":"WH-2","event_type":"BILLING.PLAN.CREATED","resource":{"href":"https://x/y"}}')
                && $r->data()['transmission_id'] === 'tx-1';
        });
    }

    public function test_webhook_without_configured_webhook_id_is_rejected(): void
    {
        $this->usePaypalSettings(['sandbox_webhook_id' => '']);
        Http::fake();

        $this->sendWebhook(['id' => 'WH-3', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => []])
            ->assertStatus(400);

        Http::assertNothingSent();
    }

    public function test_approved_order_is_captured_when_the_buyer_never_returned(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-W4')]);
        $this->fakeVerification('SUCCESS', [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-W4/capture' => Http::response($this->captureResponse('PPO-W4', $order->order_number), 201),
        ]);

        $this->sendWebhook([
            'id' => 'WH-4',
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => 'PPO-W4', 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => $order->order_number]]],
        ])->assertOk()->assertJsonPath('status', 'paid');

        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    public function test_pending_capture_is_completed_by_capture_completed_event(): void
    {
        $meta = $this->createdMeta('PPO-W5') + [
            'paypal_capture_id' => 'CAP-PPO-W5',
            'paypal_capture_status' => 'PENDING',
            'paypal_source' => ['funding' => 'paypal', 'label' => 'PayPal', 'masked' => null],
        ];
        $order = $this->createPaypalOrder(12.50, 'USD', [
            'payment_status' => PaymentStatusEnum::IN_PROGRESS,
            'transaction_id' => 'CAP-PPO-W5',
            'payment_meta' => $meta,
        ]);
        $this->fakeVerification('SUCCESS', [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-PPO-W5' => Http::response([
                'id' => 'CAP-PPO-W5',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => 'USD', 'value' => '12.50'],
                'custom_id' => $order->order_number,
                'supplementary_data' => ['related_ids' => ['order_id' => 'PPO-W5']],
            ]),
        ]);

        $this->sendWebhook([
            'id' => 'WH-5',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            // 본문 금액이 아니라 PayPal 에서 다시 조회한 캡처로 대조한다 — 본문의 금액은 일부러 틀리게 둔다.
            'resource' => ['id' => 'CAP-PPO-W5', 'custom_id' => $order->order_number, 'amount' => ['currency_code' => 'USD', 'value' => '0.01']],
        ])->assertOk()->assertJsonPath('status', 'paid');

        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
    }

    public function test_capture_completed_for_an_already_paid_order_is_idempotent(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-W6')]);
        $this->fakeVerification('SUCCESS', [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-W6/capture' => Http::response($this->captureResponse('PPO-W6', $order->order_number), 201),
        ]);

        $this->get('/plugins/g7plus-paypal/payment/return?'.http_build_query(['order' => $order->order_number, 'token' => 'PPO-W6']))
            ->assertRedirectContains('/complete');

        $this->sendWebhook([
            'id' => 'WH-6',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP-PPO-W6', 'custom_id' => $order->order_number],
        ])->assertOk()->assertJsonPath('status', 'already_recorded');
    }

    public function test_denied_pending_capture_records_a_failure(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', [
            'payment_status' => PaymentStatusEnum::IN_PROGRESS,
            'transaction_id' => 'CAP-PPO-W7',
            'payment_meta' => $this->createdMeta('PPO-W7') + ['paypal_capture_id' => 'CAP-PPO-W7', 'paypal_capture_status' => 'PENDING'],
        ]);
        $this->fakeVerification('SUCCESS');

        $this->sendWebhook([
            'id' => 'WH-7',
            'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => ['id' => 'CAP-PPO-W7', 'custom_id' => $order->order_number, 'status' => 'DECLINED'],
        ])->assertOk()->assertJsonPath('status', 'recorded');

        $order = $order->fresh(['payment']);
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame('CAPTURE_DENIED', $order->payment->payment_meta['failure_code']);
    }

    public function test_reversal_is_recorded_once(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-W8')]);
        $this->fakeVerification('SUCCESS');

        $event = [
            'id' => 'WH-8',
            'event_type' => 'PAYMENT.CAPTURE.REVERSED',
            'resource' => ['id' => 'CAP-PPO-W8', 'custom_id' => $order->order_number, 'amount' => ['currency_code' => 'USD', 'value' => '12.50']],
        ];

        $this->sendWebhook($event)->assertOk()->assertJsonPath('status', 'recorded');
        $this->sendWebhook($event)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertCount(1, $order->fresh(['payment'])->payment->payment_meta['paypal_events']);
    }
}
