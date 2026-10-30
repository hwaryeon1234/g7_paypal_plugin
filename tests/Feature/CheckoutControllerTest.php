<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Hwaryeon\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\Hwaryeon\Paypal\Tests\PluginTestCase;

/**
 * PayPal 결제 시작 API — 서버가 주문 청구액으로 PayPal 주문을 만든다.
 */
class CheckoutControllerTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    private const URL = '/api/plugins/hwaryeon-paypal/payment/checkout';

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();
    }

    /**
     * PayPal 주문 생성 성공 응답 페이크.
     *
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @return void 반환값 없음
     */
    private function fakeCreate(string $paypalOrderId = '5O190127TN364715T'): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => $paypalOrderId,
                'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [
                    ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$paypalOrderId, 'rel' => 'self'],
                    ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$paypalOrderId, 'rel' => 'payer-action'],
                ],
            ], 200),
        ]);
    }

    public function test_owner_gets_an_approval_url_for_the_server_charge(): void
    {
        $this->fakeCreate();
        $order = $this->createPaypalOrder(12.50);

        $response = $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number]);

        $response->assertOk()
            ->assertJsonPath('data.paypal_order_id', '5O190127TN364715T')
            ->assertJsonPath('data.approve_url', 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T');

        Http::assertSent(function (HttpRequest $request) use ($order) {
            if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $unit = $request->data()['purchase_units'][0];

            return $unit['amount'] === ['currency_code' => 'USD', 'value' => '12.50']
                && $unit['custom_id'] === $order->order_number
                && $request->data()['intent'] === 'CAPTURE'
                && str_contains($request->data()['payment_source']['paypal']['experience_context']['return_url'], '/plugins/hwaryeon-paypal/payment/return?order=')
                && $request->hasHeader('PayPal-Request-Id', 'g7-order-'.$order->order_number.'-USD-1250');
        });

        $payment = $order->payment->fresh();
        $this->assertSame('5O190127TN364715T', $payment->payment_meta['paypal_order_id']);
        $this->assertSame(1250, $payment->payment_meta['paypal_charge']['minor']);
        $this->assertSame(PaymentStatusEnum::IN_PROGRESS, $payment->payment_status);
    }

    public function test_another_user_cannot_start_checkout_for_the_order(): void
    {
        $this->fakeCreate();
        $order = $this->createPaypalOrder();

        $this->actingAs(User::factory()->create())
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_krw_order_is_rejected_without_calling_paypal(): void
    {
        $this->fakeCreate();
        $order = $this->createPaypalOrder(15000, 'KRW');

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'currency_not_allowed');

        Http::assertNothingSent();
    }

    public function test_currency_disabled_by_the_operator_is_rejected(): void
    {
        $this->usePaypalSettings(['allowed_currencies' => ['USD']]);
        $this->fakeCreate();
        $order = $this->createPaypalOrder(20.00, 'EUR');

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'currency_not_allowed');
    }

    public function test_paid_order_is_not_payable(): void
    {
        $this->fakeCreate();
        $order = $this->createPaypalOrder(12.50, 'USD', [], ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'order_not_payable');
    }

    public function test_non_paypal_order_is_rejected(): void
    {
        $this->fakeCreate();
        $order = $this->createPaypalOrder(12.50, 'USD', ['payment_method' => 'card']);

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'payment_method_mismatch');
    }

    public function test_untrusted_approval_link_is_not_returned(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'X1',
                'links' => [['href' => 'https://evil.example/approve', 'rel' => 'payer-action']],
            ], 200),
        ]);
        $order = $this->createPaypalOrder();

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertStatus(502);
    }
}
