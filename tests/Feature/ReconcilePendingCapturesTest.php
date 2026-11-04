<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * 웹훅을 놓친 보류 캡처를 정기 명령이 정리한다.
 */
class ReconcilePendingCapturesTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();
    }

    /**
     * 보류 캡처 주문을 만듭니다.
     *
     * @param  string  $key  PayPal 주문 ID 접미사
     * @param  int  $minutesAgo  보류 기록 시각 (분 전)
     * @return Order 주문
     */
    private function pendingOrder(string $key, int $minutesAgo = 60): Order
    {
        $order = $this->createPaypalOrder(12.50, 'USD', [
            'payment_status' => PaymentStatusEnum::IN_PROGRESS,
            'transaction_id' => 'CAP-'.$key,
            'payment_meta' => $this->createdMeta($key) + [
                'paypal_capture_id' => 'CAP-'.$key,
                'paypal_capture_status' => 'PENDING',
                'paypal_source' => ['funding' => 'paypal', 'label' => 'PayPal', 'masked' => null],
            ],
        ]);
        $order->payment->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

        return $order;
    }

    /**
     * 캡처 조회 응답.
     *
     * @param  Order  $order  주문
     * @param  string  $key  PayPal 주문 ID
     * @param  string  $status  캡처 상태
     * @return array<string, mixed> 응답
     */
    private function capture(Order $order, string $key, string $status): array
    {
        return [
            'id' => 'CAP-'.$key,
            'status' => $status,
            'amount' => ['currency_code' => 'USD', 'value' => '12.50'],
            'custom_id' => $order->order_number,
            'supplementary_data' => ['related_ids' => ['order_id' => $key]],
        ];
    }

    public function test_completed_capture_marks_the_order_paid(): void
    {
        $order = $this->pendingOrder('PPO-R1');
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-PPO-R1' => Http::response($this->capture($order, 'PPO-R1', 'COMPLETED')),
        ]);

        $this->artisan('g7plus-paypal:reconcile-pending')->assertSuccessful();

        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
    }

    public function test_declined_capture_is_recorded_as_failure(): void
    {
        $order = $this->pendingOrder('PPO-R2');
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-PPO-R2' => Http::response($this->capture($order, 'PPO-R2', 'DECLINED')),
        ]);

        $this->artisan('g7plus-paypal:reconcile-pending')->assertSuccessful();

        $order = $order->fresh(['payment']);
        $this->assertNotSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame('CAPTURE_DENIED', $order->payment->payment_meta['failure_code']);
    }

    public function test_recent_and_still_pending_captures_are_left_alone(): void
    {
        $recent = $this->pendingOrder('PPO-R3', 5);
        $old = $this->pendingOrder('PPO-R4');
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-PPO-R4' => Http::response($this->capture($old, 'PPO-R4', 'PENDING')),
        ]);

        $this->artisan('g7plus-paypal:reconcile-pending')->assertSuccessful();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'CAP-PPO-R3'));
        $this->assertSame(PaymentStatusEnum::IN_PROGRESS, $old->fresh(['payment'])->payment->payment_status);
        $this->assertSame(PaymentStatusEnum::IN_PROGRESS, $recent->fresh(['payment'])->payment->payment_status);
    }
}
