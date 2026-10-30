<?php

namespace Plugins\G7plus\Paypal;

use App\Extension\AbstractPlugin;

/**
 * PayPal 결제 플러그인
 *
 * 해외 고객을 위한 PayPal Checkout(Orders v2) 연동을 제공합니다.
 * sirsoft-ecommerce 모듈 전용 플러그인이며, 원화(KRW)가 아닌 외화 주문에만 노출됩니다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 허용 통화 기본값 — PayPal 지원 통화 전체.
     *
     * plugin.php 는 플러그인 업데이트 때 src/ 오토로더보다 먼저 읽히므로 src 클래스(PaypalCurrency)를 참조하지 않는다.
     * 목록은 PaypalCurrency::SUPPORTED 의 키와 같아야 한다(테스트가 대조한다).
     *
     * @var list<string>
     */
    public const DEFAULT_ALLOWED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR',
        'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /**
     * 플러그인 메타데이터 반환
     *
     * @return array 메타데이터
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'g7plus',
            'license' => 'MIT',
            'keywords' => ['payment', 'paypal', 'pg', 'global', 'ecommerce'],
        ];
    }

    /**
     * 환경설정 스키마 반환
     *
     * @return array 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            'is_sandbox' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '샌드박스 모드', 'en' => 'Sandbox Mode'],
                'hint' => [
                    'ko' => '샌드박스에서는 실제 결제가 발생하지 않습니다. 샌드박스 앱 키를 사용합니다.',
                    'en' => 'No real money moves in sandbox. Sandbox app credentials are used.',
                ],
            ],
            'sandbox_client_id' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '샌드박스 Client ID', 'en' => 'Sandbox Client ID'],
            ],
            'sandbox_client_secret' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '샌드박스 Secret', 'en' => 'Sandbox Secret'],
            ],
            'sandbox_webhook_id' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '샌드박스 Webhook ID', 'en' => 'Sandbox Webhook ID'],
            ],
            'live_client_id' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 Client ID', 'en' => 'Live Client ID'],
            ],
            'live_client_secret' => [
                'type' => 'string',
                'default' => '',
                'sensitive' => true,
                'label' => ['ko' => '라이브 Secret', 'en' => 'Live Secret'],
            ],
            'live_webhook_id' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '라이브 Webhook ID', 'en' => 'Live Webhook ID'],
            ],
            'allowed_currencies' => [
                'type' => 'array',
                'default' => self::DEFAULT_ALLOWED_CURRENCIES,
                'label' => ['ko' => 'PayPal 결제 허용 통화', 'en' => 'Currencies accepted via PayPal'],
                'hint' => [
                    'ko' => '여기서 고른 통화로 주문할 때만 체크아웃에 PayPal 이 나타납니다. 원화(KRW)는 PayPal 이 지원하지 않습니다.',
                    'en' => 'PayPal appears at checkout only for orders in these currencies. PayPal does not support KRW.',
                ],
            ],
            'brand_name' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => 'PayPal 결제창 상호명', 'en' => 'Brand name on PayPal'],
                'hint' => [
                    'ko' => 'PayPal 결제창에 보일 상점 이름입니다. 비워두면 PayPal 계정의 상호를 씁니다.',
                    'en' => 'Shown on the PayPal approval page. Leave blank to use the PayPal account name.',
                ],
            ],
            'landing_page' => [
                'type' => 'string',
                'default' => 'NO_PREFERENCE',
                'label' => ['ko' => 'PayPal 첫 화면', 'en' => 'PayPal landing page'],
            ],
            'redirect_success_url' => [
                'type' => 'string',
                'default' => '{shopBase}/orders/{orderId}/complete',
                'label' => ['ko' => '결제 성공 리다이렉트 URL', 'en' => 'Payment Success Redirect URL'],
            ],
            'redirect_fail_url' => [
                'type' => 'string',
                'default' => '{shopBase}/checkout',
                'label' => ['ko' => '결제 실패 리다이렉트 URL', 'en' => 'Payment Failure Redirect URL'],
            ],
        ];
    }

    /**
     * 기본 설정값 반환
     *
     * @return array 기본 설정값
     */
    public function getConfigValues(): array
    {
        return [
            'is_sandbox' => true,
            'sandbox_client_id' => '',
            'sandbox_client_secret' => '',
            'sandbox_webhook_id' => '',
            'live_client_id' => '',
            'live_client_secret' => '',
            'live_webhook_id' => '',
            'allowed_currencies' => self::DEFAULT_ALLOWED_CURRENCIES,
            'brand_name' => '',
            'landing_page' => 'NO_PREFERENCE',
            'redirect_success_url' => '{shopBase}/orders/{orderId}/complete',
            'redirect_fail_url' => '{shopBase}/checkout',
        ];
    }

    /**
     * 훅 리스너 클래스 반환
     *
     * @return array 리스너 클래스 목록
     */
    public function getHookListeners(): array
    {
        return [
            Listeners\RegisterPgProviderListener::class,
            Listeners\RegisterPaypalPaymentMethodListener::class,
            Listeners\RestrictPaymentMethodsForCurrencyListener::class,
            Listeners\PaymentRefundListener::class,
            Listeners\ValidateSettingsListener::class,
            Listeners\ProvideNecessaryStorageKeysListener::class,
        ];
    }

    /**
     * 훅 정의 반환
     *
     * @return array 훅 정의
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'g7plus-paypal.payment.before_create',
                'type' => 'action',
                'description' => [
                    'ko' => 'PayPal 주문(Orders v2) 생성 API 호출 전',
                    'en' => 'Before the PayPal Orders v2 create call',
                ],
            ],
            [
                'name' => 'g7plus-paypal.payment.after_capture',
                'type' => 'action',
                'description' => [
                    'ko' => 'PayPal 결제 캡처 완료 후 (금액 대조 전)',
                    'en' => 'After a PayPal capture returns (before amount verification)',
                ],
            ],
            [
                'name' => 'g7plus-paypal.payment.before_refund',
                'type' => 'action',
                'description' => [
                    'ko' => 'PayPal 환불 API 호출 전',
                    'en' => 'Before the PayPal refund call',
                ],
            ],
            [
                'name' => 'g7plus-paypal.payment.after_refund',
                'type' => 'action',
                'description' => [
                    'ko' => 'PayPal 환불 완료 후',
                    'en' => 'After a PayPal refund succeeds',
                ],
            ],
        ];
    }
}
