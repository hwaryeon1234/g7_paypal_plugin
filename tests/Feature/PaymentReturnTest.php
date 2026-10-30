<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * PayPal 승인 후 복귀 — 서버 캡처와 금액 대조.
 *
 * 결제완료의 금액 근거는 주문 스냅샷의 청구액과 PayPal 이 실제로 캡처한 금액뿐이다. 브라우저를 거친 token 은
 * 그 주문에 저장된 PayPal 주문 ID 와 같을 때만 쓰고, 금액이 어긋나면 캡처를 환불하고 결제완료로 가지 않는다.
 */
class PaymentReturnTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();
    }

    /**
     * 복귀 URL 을 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $token  PayPal 주문 ID
     * @return string URL
     */
    private function returnUrl(string $orderNumber, string $token): string
    {
        return '/plugins/g7plus-paypal/payment/return?'.http_build_query(['order' => $orderNumber, 'token' => $token, 'PayerID' => 'PAYER1']);
    }

    public function test_completed_capture_marks_the_order_paid(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-1')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-1/capture' => Http::response($this->captureResponse('PPO-1', $order->order_number), 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-1'))
            ->assertRedirectContains('/shop/orders/'.$order->order_number.'/complete');

        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
        $this->assertSame('CAP-PPO-1', $order->payment->transaction_id);
        $this->assertSame('PayPal', $order->payment->card_name);
        $this->assertSame('buyer@example.com', $order->payment->payment_meta['paypal_payer']['email']);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id', 'g7-capture-PPO-1'));
    }

    public function test_token_of_another_paypal_order_is_rejected_without_capture(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-2')]);
        Http::fake($this->tokenFake());

        $this->get($this->returnUrl($order->order_number, 'SOMEONE-ELSES'))
            ->assertRedirectContains('error=token_mismatch');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/capture'));
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->fresh()->order_status);
    }

    public function test_captured_amount_mismatch_refunds_and_does_not_mark_paid(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-3')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-3/capture' => Http::response($this->captureResponse('PPO-3', $order->order_number, '1.00'), 201),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-PPO-3/refund' => Http::response(['id' => 'RF-1', 'status' => 'COMPLETED'], 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-3'))
            ->assertRedirectContains('error=amount_mismatch');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/captures/CAP-PPO-3/refund'));
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    public function test_captured_currency_mismatch_refunds(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-4')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-4/capture' => Http::response($this->captureResponse('PPO-4', $order->order_number, '12.50', 'EUR'), 201),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-PPO-4/refund' => Http::response(['id' => 'RF-2', 'status' => 'COMPLETED'], 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-4'))
            ->assertRedirectContains('error=amount_mismatch');

        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    public function test_order_total_changed_after_paypal_order_creation_is_not_captured(): void
    {
        // PayPal 주문은 $10.00 로 만들어졌는데 주문 청구액은 $12.50 이다.
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-5', 1000)]);
        Http::fake($this->tokenFake());

        $this->get($this->returnUrl($order->order_number, 'PPO-5'))
            ->assertRedirectContains('error=amount_mismatch');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/capture'));
    }

    public function test_custom_id_of_another_order_refunds(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-6')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-6/capture' => Http::response($this->captureResponse('PPO-6', 'OTHER-ORDER'), 201),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-PPO-6/refund' => Http::response(['id' => 'RF-3', 'status' => 'COMPLETED'], 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-6'))
            ->assertRedirectContains('error=amount_mismatch');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund'));
    }

    public function test_declined_instrument_sends_the_buyer_back_to_paypal(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-7')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-7/capture' => Http::response([
                'name' => 'UNPROCESSABLE_ENTITY',
                'details' => [['issue' => 'INSTRUMENT_DECLINED']],
                'debug_id' => 'dbg1',
            ], 422),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-7'))
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=PPO-7');

        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->fresh()->order_status);
    }

    public function test_capture_after_re_approval_uses_a_new_idempotency_key(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-13')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-13/capture' => Http::sequence()
                ->push(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422)
                ->push($this->captureResponse('PPO-13', $order->order_number), 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-13'))->assertRedirectContains('checkoutnow?token=PPO-13');
        $this->get($this->returnUrl($order->order_number, 'PPO-13'))->assertRedirectContains('/complete');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id', 'g7-capture-PPO-13'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id', 'g7-capture-PPO-13-1'));
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    public function test_pending_capture_waits_for_the_webhook(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-8')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-8/capture' => Http::response($this->captureResponse('PPO-8', $order->order_number, '12.50', 'USD', 'PENDING'), 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-8'))
            ->assertRedirectContains('/complete');

        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertSame(PaymentStatusEnum::IN_PROGRESS, $order->payment->payment_status);
        $this->assertSame('CAP-PPO-8', $order->payment->transaction_id);
        $this->assertSame('PENDING', $order->payment->payment_meta['paypal_capture_status']);
    }

    public function test_reloading_the_return_url_does_not_capture_twice(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-9')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-9/capture' => Http::response($this->captureResponse('PPO-9', $order->order_number), 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-9'))->assertRedirectContains('/complete');
        $this->get($this->returnUrl($order->order_number, 'PPO-9'))->assertRedirectContains('/complete');

        Http::assertSentCount(2); // 토큰 1 + 캡처 1
    }

    public function test_already_captured_elsewhere_is_verified_by_lookup(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-10')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-10/capture' => Http::response([
                'name' => 'UNPROCESSABLE_ENTITY',
                'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']],
            ], 422),
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-10' => Http::response($this->captureResponse('PPO-10', $order->order_number), 200),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-10'))->assertRedirectContains('/complete');

        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    public function test_cancel_return_does_not_change_the_order(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => $this->createdMeta('PPO-11')]);
        Http::fake();

        $this->get('/plugins/g7plus-paypal/payment/cancel?'.http_build_query(['order' => $order->order_number, 'token' => 'PPO-11']))
            ->assertRedirectContains('error=USER_CANCEL');

        Http::assertNothingSent();
        $this->assertSame(OrderStatusEnum::PENDING_ORDER, $order->fresh()->order_status);
    }

    public function test_jpy_orders_are_captured_without_decimals(): void
    {
        $order = $this->createPaypalOrder(1200, 'JPY', ['payment_meta' => $this->createdMeta('PPO-12', 1200, 'JPY')]);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/PPO-12/capture' => Http::response($this->captureResponse('PPO-12', $order->order_number, '1200', 'JPY'), 201),
        ]);

        $this->get($this->returnUrl($order->order_number, 'PPO-12'))->assertRedirectContains('/complete');

        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }
}
