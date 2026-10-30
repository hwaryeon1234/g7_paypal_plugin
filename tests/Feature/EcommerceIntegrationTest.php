<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use Mockery;
use Modules\Sirsoft\Ecommerce\Services\CheckoutPaymentMethodFilter;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Plugins\G7plus\Paypal\Plugin;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * 코어 리스너 등록기로 이 플러그인의 리스너를 등록한 뒤, 이커머스 서비스가 PayPal 을 실제로 인식하는지 확인합니다.
 *
 * 리스너를 직접 호출하는 단위 테스트와 달리, 훅 이름·인자 순서·sync 선언이 이커머스의 발화 지점과 맞는지를 잠근다.
 */
class EcommerceIntegrationTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();

        foreach ((new Plugin)->getHookListeners() as $listener) {
            HookListenerRegistrar::register($listener, 'g7plus-paypal');
        }
    }

    public function test_pg_registry_lists_paypal_with_its_payment_handler(): void
    {
        $providers = app(EcommerceSettingsService::class)->getRegisteredPgProviders();
        $paypal = collect($providers)->firstWhere('id', 'paypal');

        $this->assertNotNull($paypal);
        $this->assertSame('g7plus-paypal.requestPayment', $paypal['payment_handler']);
    }

    public function test_payment_method_catalog_contains_paypal_locked_to_the_paypal_pg(): void
    {
        $methods = app(EcommerceSettingsService::class)->getMergedPaymentMethods([]);
        $paypal = collect($methods)->firstWhere('id', 'paypal');

        $this->assertNotNull($paypal);
        $this->assertSame('paypal', $paypal['pg_provider']);
        $this->assertTrue((bool) $paypal['pg_locked']);
        $this->assertFalse((bool) $paypal['is_active']);
    }

    public function test_saved_paypal_method_routes_orders_to_the_paypal_pg(): void
    {
        // 운영자가 주문 설정 화면에서 PayPal 을 켜고 저장하면, 화면이 받은 병합 항목이 그대로 저장된다.
        // 그 저장값을 런타임 조회가 읽는 것처럼 대체한다(설정 파일을 실제로 쓰지 않는다).
        $real = app(EcommerceSettingsService::class);
        $saved = array_map(
            fn (array $m) => $m['id'] === 'paypal' ? array_merge($m, ['is_active' => true]) : $m,
            $real->getMergedPaymentMethods([]),
        );

        $settings = Mockery::mock(EcommerceSettingsService::class)->makePartial();
        $settings->shouldReceive('getSetting')->andReturnUsing(
            fn (string $key, $default = null) => $key === 'order_settings.payment_methods' ? $saved : $real->getSetting($key, $default),
        );
        $this->app->instance(EcommerceSettingsService::class, $settings);
        $this->app->forgetInstance(OrderProcessingService::class);

        $this->assertSame('paypal', app(OrderProcessingService::class)->determinePgProvider('paypal'));
    }

    public function test_checkout_filter_hides_paypal_for_krw_and_shows_it_for_allowed_currencies(): void
    {
        $filter = app(CheckoutPaymentMethodFilter::class);

        $this->assertSame(['card'], $filter->allowedFor(['card', 'paypal'], 'KRW'));
        $this->assertSame(['card', 'paypal'], $filter->allowedFor(['card', 'paypal'], 'USD'));
        $this->assertSame(['card', 'paypal'], $filter->allowedFor(['card', 'paypal'], 'JPY'));
        $this->assertSame(['card'], $filter->allowedFor(['card', 'paypal'], 'GBP'));
    }

    public function test_refund_service_sees_a_listener_for_paypal_orders(): void
    {
        $order = $this->createPaypalOrder();

        $this->assertTrue(app(RefundExecutionService::class)->hasPgRefundListener($order));
    }
}
