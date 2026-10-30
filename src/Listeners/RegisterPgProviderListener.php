<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;

/**
 * PG 제공자 등록 리스너.
 *
 * 이커머스 모듈의 PG 제공자 레지스트리에 PayPal 을 등록하고, 프론트 결제 진입 핸들러를 선언한다.
 * 이커머스 코드는 이 플러그인을 모른다 — 레지스트리 항목이 계약의 전부다.
 */
class RegisterPgProviderListener implements HookListenerInterface
{
    /**
     * 구독할 훅 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.registered_pg_providers' => [
                'method' => 'registerProvider',
                'type' => 'filter',
                'priority' => 10,
            ],
            'sirsoft-ecommerce.payment.get_client_config' => [
                'method' => 'getClientConfig',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용)
     *
     * @param  mixed  ...$args  인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * PG 제공자 목록에 PayPal 을 등록합니다.
     *
     * `supported_methods` 는 비워 둔다 — 코어 결제수단(카드·가상계좌 등)은 원화 주문에도 쓰이는데 PayPal 은 원화를
     * 받지 못하므로, 운영자가 실수로 카드의 PG 를 PayPal 로 지정할 수 없게 한다. PayPal 은 자기 결제수단(`paypal`)
     * 으로만 결제창을 연다(RegisterPaypalPaymentMethodListener 의 pg_locked).
     *
     * @param  array<int, array<string, mixed>>  $providers  기존 PG 제공자 목록
     * @return array<int, array<string, mixed>> PayPal 이 추가된 목록
     */
    public function registerProvider(array $providers): array
    {
        $providers[] = [
            'id' => PaypalCheckoutService::PROVIDER_ID,
            'name_key' => 'g7plus-paypal::provider.name',
            'name' => localized_label(nameKey: 'g7plus-paypal::provider.name'),
            'icon' => 'wallet',
            'supported_methods' => [],
            'payment_handler' => 'g7plus-paypal.requestPayment',
        ];

        return $providers;
    }

    /**
     * 프론트 클라이언트 설정을 제공합니다.
     *
     * PayPal 은 브라우저 SDK 를 쓰지 않는다(서버가 주문을 만들고 승인 주소로 이동) — 키를 내려보내지 않는다.
     *
     * @param  array<string, mixed>  $config  기존 설정
     * @param  string  $provider  PG 제공자 ID
     * @return array<string, mixed> 클라이언트 설정
     */
    public function getClientConfig(array $config, string $provider): array
    {
        if ($provider !== PaypalCheckoutService::PROVIDER_ID) {
            return $config;
        }

        return array_merge($config, [
            'checkout_endpoint' => '/api/plugins/g7plus-paypal/payment/checkout',
            'allowed_currencies' => app(PaypalCheckoutService::class)->allowedCurrencies(),
        ]);
    }
}
