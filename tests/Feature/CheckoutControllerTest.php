<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * PayPal 결제 시작 API — 서버가 주문 청구액으로 PayPal 주문을 만든다.
 */
class CheckoutControllerTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    private const URL = '/api/plugins/g7plus-paypal/payment/checkout';

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
                && str_contains($request->data()['payment_source']['paypal']['experience_context']['return_url'], '/plugins/g7plus-paypal/payment/return?order=')
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

    /**
     * 주문에 배송지를 붙입니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @return void 반환값 없음
     */
    private function attachShippingAddress(int $orderId, array $overrides = []): void
    {
        OrderAddress::create(array_merge([
            'order_id' => $orderId,
            'address_type' => 'shipping',
            'orderer_name' => 'Jane Doe',
            'orderer_phone' => '+1 555 0100',
            'orderer_email' => 'jane@example.com',
            'recipient_name' => 'Jane Doe',
            'recipient_phone' => '+1 555 0100',
            'recipient_country_code' => 'US',
            'address_line_1' => '1 Main St',
            'intl_city' => 'San Jose',
            'intl_state' => 'CA',
            'intl_postal_code' => '95131',
            'zipcode' => '',
            'address' => '',
        ], $overrides));
    }

    public function test_address_without_a_city_is_not_sent_to_paypal(): void
    {
        // 국내식 주소 입력 — 도시 칸이 없다 (실제 운영 장애: PayPal 422 CITY_REQUIRED).
        $this->fakeCreate();
        $order = $this->createPaypalOrder(33.06);
        $this->attachShippingAddress($order->id, [
            'recipient_country_code' => 'KR',
            'address_line_1' => null,
            'address' => '서울특별시 강남구 테헤란로 1',
            'address_detail' => '101호',
            'intl_city' => null,
            'intl_state' => null,
            'intl_postal_code' => null,
            'recipient_city' => null,
            'zipcode' => '06234',
        ]);

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && ! isset($r->data()['purchase_units'][0]['shipping'])
            && $r->data()['payment_source']['paypal']['experience_context']['shipping_preference'] === 'NO_SHIPPING');
    }

    public function test_full_address_is_sent_for_seller_protection(): void
    {
        $this->fakeCreate();
        $order = $this->createPaypalOrder();
        $this->attachShippingAddress($order->id);

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && ($r->data()['purchase_units'][0]['shipping']['address']['admin_area_2'] ?? null) === 'San Jose'
            && $r->data()['payment_source']['paypal']['experience_context']['shipping_preference'] === 'SET_PROVIDED_ADDRESS');
    }

    public function test_shipping_field_rejection_retries_without_shipping(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::sequence()
                ->push([
                    'name' => 'UNPROCESSABLE_ENTITY',
                    'details' => [[
                        'field' => "/purchase_units/@reference_id=='X'/shipping/address/admin_area_2",
                        'issue' => 'CITY_REQUIRED',
                    ]],
                ], 422)
                ->push([
                    'id' => 'RETRY1',
                    'links' => [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=RETRY1', 'rel' => 'payer-action']],
                ], 200),
        ]);
        $order = $this->createPaypalOrder();
        $this->attachShippingAddress($order->id);

        $this->actingAs(User::find($order->user_id))
            ->postJson(self::URL, ['orderId' => $order->order_number])
            ->assertOk()
            ->assertJsonPath('data.paypal_order_id', 'RETRY1');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && ! isset($r->data()['purchase_units'][0]['shipping'])
            && str_ends_with($r->header('PayPal-Request-Id')[0] ?? '', '-noship'));
    }
}
