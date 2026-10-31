<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Plugins\G7plus\Paypal\Listeners\ClaimPaymentListener;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * 클레임(교환·반품) 추가결제 — PayPal 주문 생성, 복귀 승인, 환급.
 *
 * 승인은 이커머스의 실제 `ClaimPaymentCallback::approveAndRedirect()` → `claim_payment.approve` 필터를 거친다.
 */
class ClaimPaymentTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    /** 결제번호 충돌 방지용 시퀀스 */
    private static int $claimSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();

        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ClaimPaymentListener::class, 'g7plus-paypal');
    }

    /**
     * USD 주문의 반품 신청서와 PayPal 결제 대기 건($5.00)을 만듭니다.
     *
     * @param  array<string, mixed>  $paymentOverrides  결제 건 덮어쓸 속성
     * @return array{return: OrderReturn, payment: ClaimPayment, user: User} 신청서·결제 건·주문자
     */
    private function makeClaimPayment(array $paymentOverrides = []): array
    {
        $seq = ++self::$claimSequence;
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-PPCLM-'.$seq.'-'.substr(md5((string) microtime(true)), 0, 6),
            'currency' => 'USD',
            'currency_snapshot' => [
                'base_currency' => 'USD',
                'order_currency' => 'USD',
                'exchange_rates' => [
                    'USD' => ['rate' => 1.0, 'decimal_places' => 2, 'rounding_unit' => '0.01', 'rounding_method' => 'round'],
                ],
            ],
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-PPCLM-'.$seq.'-'.substr(md5((string) microtime(true)), 0, 6),
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 5,
        ]);

        $payment = ClaimPaymentFactory::new()->create(array_merge([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-PPCLM-'.$seq.'-'.substr(md5((string) microtime(true)), 0, 6),
            'payment_method' => 'paypal',
            'pg_provider' => 'paypal',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 5.00,
            'currency_code' => 'USD',
        ], $paymentOverrides));

        return ['return' => $return, 'payment' => $payment, 'user' => $user];
    }

    /**
     * PayPal 주문 조회 응답(승인 상태)을 만듭니다.
     *
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @param  string  $customId  custom_id
     * @param  string  $value  금액
     * @return array<string, mixed> 응답
     */
    private function approvedOrder(string $paypalOrderId, string $customId, string $value = '5.00'): array
    {
        return [
            'id' => $paypalOrderId,
            'status' => 'APPROVED',
            'purchase_units' => [['custom_id' => $customId, 'amount' => ['currency_code' => 'USD', 'value' => $value]]],
        ];
    }

    public function test_owner_starts_a_paypal_claim_payment_for_the_server_charge(): void
    {
        ['payment' => $payment, 'user' => $user] = $this->makeClaimPayment();
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'CLM-1',
                'links' => [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=CLM-1', 'rel' => 'payer-action']],
            ], 200),
        ]);

        $this->actingAs($user)
            ->postJson('/api/plugins/g7plus-paypal/payment/checkout', ['orderId' => $payment->payment_number])
            ->assertOk()
            ->assertJsonPath('data.approve_url', 'https://www.sandbox.paypal.com/checkoutnow?token=CLM-1');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && $r->data()['purchase_units'][0]['custom_id'] === $payment->payment_number
            && $r->data()['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '5.00']
            && str_contains($r->data()['payment_source']['paypal']['experience_context']['return_url'], 'order='.rawurlencode($payment->payment_number)));
    }

    public function test_stranger_cannot_start_a_claim_payment(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/plugins/g7plus-paypal/payment/checkout', ['orderId' => $payment->payment_number])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_return_captures_and_marks_the_claim_payment_paid(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makeClaimPayment();
        $captured = $this->approvedOrder('CLM-2', $payment->payment_number);
        $captured['status'] = 'COMPLETED';
        $captured['purchase_units'][0]['payments']['captures'][0] = [
            'id' => 'CAP-CLM-2', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '5.00'],
        ];
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/CLM-2/capture' => Http::response($captured, 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/CLM-2' => Http::response($this->approvedOrder('CLM-2', $payment->payment_number), 200),
        ]);

        $this->get('/plugins/g7plus-paypal/payment/return?'.http_build_query(['order' => $payment->payment_number, 'token' => 'CLM-2']))
            ->assertRedirectContains('claim_payment=success');

        $fresh = $payment->fresh();
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $fresh->payment_status);
        $this->assertSame('CAP-CLM-2', $fresh->transaction_id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id', 'g7-claimcap-CLM-2'));
    }

    public function test_token_of_another_payment_is_not_captured(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/OTHER' => Http::response($this->approvedOrder('OTHER', 'CP-SOMEONE-ELSE'), 200),
        ]);

        $this->get('/plugins/g7plus-paypal/payment/return?'.http_build_query(['order' => $payment->payment_number, 'token' => 'OTHER']))
            ->assertRedirectContains('claim_payment=failed');

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/capture'));
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    public function test_wrong_captured_amount_is_refunded_and_not_marked_paid(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $captured = $this->approvedOrder('CLM-3', $payment->payment_number);
        $captured['status'] = 'COMPLETED';
        $captured['purchase_units'][0]['payments']['captures'][0] = [
            'id' => 'CAP-CLM-3', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '0.50'],
        ];
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/checkout/orders/CLM-3/capture' => Http::response($captured, 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/CLM-3' => Http::response($this->approvedOrder('CLM-3', $payment->payment_number), 200),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-CLM-3/refund' => Http::response(['id' => 'RF-CLM-3', 'status' => 'COMPLETED'], 201),
        ]);

        $this->get('/plugins/g7plus-paypal/payment/return?'.http_build_query(['order' => $payment->payment_number, 'token' => 'CLM-3']))
            ->assertRedirectContains('claim_payment=failed');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/captures/CAP-CLM-3/refund'));
        $this->assertNotSame(ClaimPaymentStatusEnum::PAID, $payment->fresh()->payment_status);
    }

    public function test_cancel_returns_to_the_claim_page_without_changing_the_payment(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        Http::fake();

        $this->get('/plugins/g7plus-paypal/payment/cancel?'.http_build_query(['order' => $payment->payment_number, 'token' => 'CLM-4']))
            ->assertRedirectContains('claim_payment=cancelled');

        Http::assertNothingSent();
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    public function test_refund_filter_refunds_the_capture_in_the_payment_currency(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment(['payment_status' => ClaimPaymentStatusEnum::PAID, 'transaction_id' => 'CAP-CLM-5']);
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP-CLM-5/refund' => Http::response(['id' => 'RF-CLM-5', 'status' => 'COMPLETED'], 201),
        ]);

        $result = (new ClaimPaymentListener)->refund(['handled' => false, 'success' => false], $payment, 2.5, 'Partial refund', 'g7-claimpay-1-0-250');

        $this->assertTrue($result['success']);
        $this->assertSame('RF-CLM-5', $result['transaction_id']);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund')
            && $r->data()['amount'] === ['value' => '2.50', 'currency_code' => 'USD']
            && $r->hasHeader('PayPal-Request-Id', 'g7-claimpay-1-0-250'));
    }

    public function test_other_provider_claim_payments_are_left_untouched(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment(['pg_provider' => 'tosspayments']);
        Http::fake();
        $initial = ['handled' => false, 'success' => false];

        $listener = new ClaimPaymentListener;
        $this->assertSame($initial, $listener->approve($initial, $payment, ['token' => 'X']));
        $this->assertSame($initial, $listener->refund($initial, $payment, 1.0, null, 'k'));
        Http::assertNothingSent();
    }

    public function test_charge_matches_the_module_charge(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();

        $this->assertSame(['minor_unit_amount' => 500, 'currency' => 'USD'], app(ClaimPaymentService::class)->resolvePaymentCharge($payment));
    }
}
