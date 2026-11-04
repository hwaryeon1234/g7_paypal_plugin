<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use App\Models\User;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * 관리자 주문 상세 PayPal 패널 API.
 */
class AdminOrderPaymentTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();
    }

    public function test_admin_sees_fee_protection_and_reversals(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', [
            'transaction_id' => 'CAP-AD-1',
            'payment_meta' => $this->createdMeta('PPO-AD-1') + [
                'paypal_capture_id' => 'CAP-AD-1',
                'paypal_capture_status' => 'COMPLETED',
                'paypal_seller_protection' => 'ELIGIBLE',
                'paypal_seller_breakdown' => [
                    'gross_amount' => ['currency_code' => 'USD', 'value' => '12.50'],
                    'paypal_fee' => ['currency_code' => 'USD', 'value' => '0.80'],
                    'net_amount' => ['currency_code' => 'USD', 'value' => '11.70'],
                ],
                'paypal_payer' => ['email' => 'buyer@example.com', 'country' => 'US'],
                'paypal_events' => [['event_id' => 'WH-1', 'type' => 'PAYMENT.CAPTURE.REVERSED', 'resource_id' => 'CAP-AD-1', 'amount' => ['currency_code' => 'USD', 'value' => '12.50'], 'status' => 'REVERSED', 'received_at' => '2026-11-04T10:00:00+09:00']],
            ],
        ]);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($admin)->getJson('/api/plugins/g7plus-paypal/admin/orders/'.$order->order_number.'/payment')
            ->assertOk()
            ->assertJsonPath('data.is_paypal', true)
            ->assertJsonPath('data.fee', '0.80 USD')
            ->assertJsonPath('data.net', '11.70 USD')
            ->assertJsonPath('data.seller_protection', 'ELIGIBLE')
            ->assertJsonPath('data.events.0.type', 'PAYMENT.CAPTURE.REVERSED')
            ->assertJsonPath('data.dashboard_url', 'https://www.sandbox.paypal.com/activity/payment/CAP-AD-1');
    }

    public function test_other_pg_orders_report_not_paypal(): void
    {
        $order = $this->createPaypalOrder(12.50, 'USD', ['pg_provider' => 'tosspayments']);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($admin)->getJson('/api/plugins/g7plus-paypal/admin/orders/'.$order->order_number.'/payment')
            ->assertOk()
            ->assertJsonPath('data.is_paypal', false);
    }

    public function test_requires_order_permission(): void
    {
        $order = $this->createPaypalOrder();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/plugins/g7plus-paypal/admin/orders/'.$order->order_number.'/payment')
            ->assertForbidden();
    }
}
