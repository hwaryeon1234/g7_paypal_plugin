<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Tests\Concerns;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * PayPal 테스트용 주문·설정 픽스처.
 *
 * 통화 스냅샷에 decimal_places 를 명시해 상점 통화 설정 조회로 떨어지지 않게 한다.
 */
trait CreatesPaypalOrders
{
    /** 주문번호 충돌 방지용 시퀀스 */
    private static int $orderSequence = 0;

    /**
     * 플러그인 설정을 샌드박스 고정값으로 대체합니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 설정
     * @return void 반환값 없음
     */
    protected function usePaypalSettings(array $overrides = []): void
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn(array_merge([
            'is_sandbox' => true,
            'sandbox_client_id' => 'sb-client-id',
            'sandbox_client_secret' => 'sb-secret',
            'sandbox_webhook_id' => 'WH-TEST-1',
            'allowed_currencies' => ['USD', 'EUR', 'JPY'],
            'brand_name' => 'Test Store',
            'landing_page' => 'NO_PREFERENCE',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ], $overrides));

        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 결제 통화가 USD 인 PayPal 결제대기 주문을 만듭니다. (청구액 = $amount 달러)
     *
     * @param  float  $amount  청구액 (주 단위)
     * @param  string  $currency  결제 통화
     * @param  array<string, mixed>  $paymentOverrides  결제행 덮어쓸 속성
     * @param  array<string, mixed>  $orderOverrides  주문 덮어쓸 속성
     * @return Order 생성된 주문
     */
    protected function createPaypalOrder(float $amount = 12.50, string $currency = 'USD', array $paymentOverrides = [], array $orderOverrides = []): Order
    {
        $decimals = $currency === 'JPY' || $currency === 'KRW' ? 0 : 2;

        $order = OrderFactory::new()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-PP-'.(++self::$orderSequence).'-'.substr(md5((string) microtime(true)), 0, 6),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => $currency,
            'currency_snapshot' => [
                'base_currency' => $currency,
                'order_currency' => $currency,
                'exchange_rate' => 1.0,
                'exchange_rates' => [
                    $currency => ['rate' => 1.0, 'decimal_places' => $decimals, 'rounding_unit' => $decimals === 0 ? '1' : '0.01', 'rounding_method' => 'round'],
                ],
                'snapshot_at' => now()->toIso8601String(),
            ],
            'subtotal_amount' => $amount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $amount,
            'total_due_amount' => $amount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ], $orderOverrides));

        OrderPaymentFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => 'paypal',
            'pg_provider' => 'paypal',
            'currency' => $currency,
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'payment_meta' => null,
        ], $paymentOverrides));

        return $order->fresh(['payment']);
    }

    /**
     * PayPal 주문을 이미 만든 상태(승인 대기)의 결제행 메타를 만듭니다.
     *
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @param  int  $minor  청구액 (최소 화폐단위)
     * @param  string  $currency  통화
     * @return array<string, mixed> payment_meta
     */
    protected function createdMeta(string $paypalOrderId, int $minor = 1250, string $currency = 'USD'): array
    {
        $decimals = $currency === 'JPY' ? 0 : 2;

        return [
            'paypal_order_id' => $paypalOrderId,
            'paypal_approve_url' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$paypalOrderId,
            'paypal_charge' => [
                'currency' => $currency,
                'minor' => $minor,
                'decimals' => $decimals,
                'value' => $decimals === 0 ? (string) $minor : number_format($minor / 100, 2, '.', ''),
            ],
            'paypal_mode' => 'sandbox',
        ];
    }

    /**
     * PayPal 캡처 응답(주문 객체)을 만듭니다.
     *
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @param  string  $orderNumber  주문번호 (custom_id)
     * @param  string  $value  캡처 금액 문자열
     * @param  string  $currency  통화
     * @param  string  $status  캡처 상태
     * @return array<string, mixed> 응답
     */
    protected function captureResponse(string $paypalOrderId, string $orderNumber, string $value = '12.50', string $currency = 'USD', string $status = 'COMPLETED'): array
    {
        return [
            'id' => $paypalOrderId,
            'status' => $status === 'COMPLETED' ? 'COMPLETED' : 'APPROVED',
            'payment_source' => ['paypal' => ['email_address' => 'buyer@example.com', 'account_id' => 'PAYER1']],
            'payer' => ['payer_id' => 'PAYER1', 'email_address' => 'buyer@example.com', 'address' => ['country_code' => 'US']],
            'purchase_units' => [[
                'reference_id' => $orderNumber,
                'custom_id' => $orderNumber,
                'payments' => [
                    'captures' => [[
                        'id' => 'CAP-'.$paypalOrderId,
                        'status' => $status,
                        'amount' => ['currency_code' => $currency, 'value' => $value],
                        'custom_id' => $orderNumber,
                        'seller_protection' => ['status' => 'ELIGIBLE'],
                        'seller_receivable_breakdown' => [
                            'gross_amount' => ['currency_code' => $currency, 'value' => $value],
                            'paypal_fee' => ['currency_code' => $currency, 'value' => '0.80'],
                        ],
                    ]],
                ],
            ]],
        ];
    }

    /**
     * OAuth 토큰 응답 페이크 항목.
     *
     * @return array<string, mixed> Http::fake 항목
     */
    protected function tokenFake(): array
    {
        return ['api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21-token', 'expires_in' => 32400])];
    }
}
