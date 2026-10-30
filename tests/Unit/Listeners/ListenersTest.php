<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Tests\Unit\Listeners;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Plugins\Hwaryeon\Paypal\Listeners\PaymentRefundListener;
use Plugins\Hwaryeon\Paypal\Listeners\RegisterPaypalPaymentMethodListener;
use Plugins\Hwaryeon\Paypal\Listeners\RegisterPgProviderListener;
use Plugins\Hwaryeon\Paypal\Listeners\RestrictPaymentMethodsForCurrencyListener;
use Plugins\Hwaryeon\Paypal\Listeners\ValidateSettingsListener;
use Plugins\Hwaryeon\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\Hwaryeon\Paypal\Tests\PluginTestCase;

/**
 * 이커머스 레지스트리·통화 제한·환불·설정 검증 리스너.
 */
class ListenersTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();
    }

    public function test_provider_is_registered_with_the_payment_handler_and_no_core_methods(): void
    {
        $providers = (new RegisterPgProviderListener)->registerProvider([['id' => 'tosspayments']]);

        $paypal = collect($providers)->firstWhere('id', 'paypal');
        $this->assertSame('hwaryeon-paypal.requestPayment', $paypal['payment_handler']);
        $this->assertSame([], $paypal['supported_methods']);
    }

    public function test_payment_method_is_injected_once_before_point_and_locked_to_paypal(): void
    {
        $listener = new RegisterPaypalPaymentMethodListener;
        $methods = $listener->injectPaypalMethod([['id' => 'card'], ['id' => 'point']]);

        $this->assertSame(['card', 'paypal', 'point'], array_column($methods, 'id'));
        $this->assertSame('paypal', $methods[1]['defaults']['pg_provider']);
        $this->assertTrue($methods[1]['defaults']['pg_locked']);
        $this->assertFalse($methods[1]['defaults']['is_active']);

        $this->assertCount(3, $listener->injectPaypalMethod($methods));
    }

    public function test_paypal_is_hidden_for_krw_and_operator_disabled_currencies(): void
    {
        $filter = new RestrictPaymentMethodsForCurrencyListener;
        $ids = ['card', 'paypal', 'dbank'];

        $this->assertSame(['card', 'dbank'], $filter->filter($ids, 'KRW'));
        $this->assertSame(['card', 'paypal', 'dbank'], $filter->filter($ids, 'USD'));
        $this->assertSame(['card', 'paypal', 'dbank'], $filter->filter($ids, 'jpy'));
        // 지원 통화지만 운영자가 고르지 않음
        $this->assertSame(['card', 'dbank'], $filter->filter($ids, 'GBP'));
    }

    public function test_every_supported_currency_is_allowed_when_the_setting_is_absent(): void
    {
        $this->usePaypalSettings(['allowed_currencies' => null]);

        $this->assertSame(['card', 'paypal'], (new RestrictPaymentMethodsForCurrencyListener)->filter(['card', 'paypal'], 'GBP'));
    }

    public function test_refund_calls_paypal_with_an_explicit_amount(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-R1/refund' => Http::response(['id' => 'RF-R1', 'status' => 'COMPLETED'], 201),
        ]);
        $order = $this->createPaypalOrder(12.50, 'USD', [
            'payment_status' => PaymentStatusEnum::PAID,
            'transaction_id' => 'CAP-R1',
            'paid_at' => now(),
        ], ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $refund = new OrderRefund;
        $refund->id = 77;

        $result = (new PaymentRefundListener)->processRefund(
            ['success' => false, 'handled' => false],
            $order,
            $order->payment,
            5.25,
            'Damaged item',
            $refund,
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['handled']);
        $this->assertSame('RF-R1', $result['transaction_id']);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund')
            && $r->data()['amount'] === ['value' => '5.25', 'currency_code' => 'USD']
            && $r->data()['note_to_payer'] === 'Damaged item'
            && $r->hasHeader('PayPal-Request-Id', 'g7-refund-77-USD-5.25'));
    }

    public function test_refund_failure_is_reported_with_a_readable_reason(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-R2/refund' => Http::response([
                'name' => 'UNPROCESSABLE_ENTITY',
                'details' => [['issue' => 'REFUND_AMOUNT_EXCEEDED']],
            ], 422),
        ]);
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_status' => PaymentStatusEnum::PAID, 'transaction_id' => 'CAP-R2']);

        $result = (new PaymentRefundListener)->processRefund(['success' => false, 'handled' => false], $order, $order->payment, 100.0);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['handled']);
        $this->assertSame('REFUND_AMOUNT_EXCEEDED', $result['error_code']);
    }

    public function test_refund_of_another_provider_is_left_untouched(): void
    {
        Http::fake();
        $order = $this->createPaypalOrder(12.50, 'USD', ['pg_provider' => 'tosspayments', 'transaction_id' => 'pk']);
        $initial = ['success' => false, 'handled' => false, 'error_code' => null];

        $this->assertSame($initial, (new PaymentRefundListener)->processRefund($initial, $order, $order->payment, 1.0));
        Http::assertNothingSent();
    }

    public function test_partial_refund_is_blocked_after_180_days_and_while_pending(): void
    {
        $listener = new PaymentRefundListener;

        $recent = $this->createPaypalOrder(12.50, 'USD', ['paid_at' => now()->subDays(10)])->payment;
        $old = $this->createPaypalOrder(12.50, 'USD', ['paid_at' => now()->subDays(181)])->payment;
        $pending = $this->createPaypalOrder(12.50, 'USD', ['payment_meta' => ['paypal_capture_status' => 'PENDING']])->payment;

        $this->assertTrue($listener->supportsPartialCancel(true, $recent));
        $this->assertFalse($listener->supportsPartialCancel(true, $old));
        $this->assertFalse($listener->supportsPartialCancel(true, $pending));
    }

    public function test_settings_rules_reject_krw_and_unsafe_redirects(): void
    {
        $rules = (new ValidateSettingsListener)->rules([], 'hwaryeon-paypal');

        $validator = validator([
            'allowed_currencies' => ['USD', 'KRW'],
            'redirect_fail_url' => '//evil.example/x',
            'landing_page' => 'BILLING',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('allowed_currencies.1', $validator->errors()->toArray());
        $this->assertArrayHasKey('redirect_fail_url', $validator->errors()->toArray());
        $this->assertArrayHasKey('landing_page', $validator->errors()->toArray());

        $this->assertTrue(validator([
            'allowed_currencies' => ['USD', 'EUR'],
            'redirect_fail_url' => '{shopBase}/checkout',
            'landing_page' => 'LOGIN',
        ], $rules)->passes());

        $this->assertSame([], (new ValidateSettingsListener)->rules([], 'other-plugin'));
    }
}
