<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\G7plus\Paypal\Services\PaypalCheckoutService;

/**
 * PayPal 결제수단을 이커머스 결제수단 카탈로그에 등록한다.
 *
 * `sirsoft-ecommerce.settings.filter_available_payment_methods` 필터에 `paypal` 항목을 더한다. 처음에는
 * 비활성(is_active=false)으로 들어가며, 운영자가 이커머스 「주문 설정 > 결제수단」에서 켠다.
 *
 * 결제수단 id `paypal` 은 코어 enum 값이 아니라 확장 id 로 그대로 저장된다(KG 이니시스 간편결제와 같은 방식) —
 * 주문 상세·통계에 「PayPal」로 남는다. PG 는 이 플러그인으로 고정(pg_locked)한다.
 */
class RegisterPaypalPaymentMethodListener implements HookListenerInterface
{
    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>> 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.settings.filter_available_payment_methods' => [
                'method' => 'injectPaypalMethod',
                'type' => 'filter',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용).
     *
     * @param  mixed  ...$args  인수
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 결제수단 목록에 PayPal 을 넣습니다. ('point' 앞, 없으면 끝)
     *
     * @param  array<int, array<string, mixed>>  $methods  결제수단 배열
     * @return array<int, array<string, mixed>> PayPal 이 포함된 배열
     */
    public function injectPaypalMethod(array $methods): array
    {
        foreach ($methods as $method) {
            if (($method['id'] ?? null) === PaypalCheckoutService::METHOD_ID) {
                return $methods;
            }
        }

        $entry = $this->buildEntry();

        foreach ($methods as $index => $method) {
            if (($method['id'] ?? null) === 'point') {
                return array_merge(array_slice($methods, 0, $index), [$entry], array_slice($methods, $index));
            }
        }

        $methods[] = $entry;

        return $methods;
    }

    /**
     * 결제수단 항목을 만듭니다 — EcommerceSettingsService::getBuiltinPaymentMethods 와 같은 형식.
     *
     * @return array<string, mixed> 결제수단 항목
     */
    private function buildEntry(): array
    {
        $nameKey = 'g7plus-paypal::payment_methods.paypal.name';
        $descriptionKey = 'g7plus-paypal::payment_methods.paypal.description';

        return [
            'id' => PaypalCheckoutService::METHOD_ID,
            'name' => [
                'ko' => __($nameKey, [], 'ko'),
                'en' => __($nameKey, [], 'en'),
            ],
            'description' => [
                'ko' => __($descriptionKey, [], 'ko'),
                'en' => __($descriptionKey, [], 'en'),
            ],
            'icon' => 'wallet',
            // PayPal 브랜드 색(#003087 바탕, 흰 글자) 배지 — 다른 간편결제와 같은 형식.
            'brand_mark' => ['text' => 'P', 'class' => 'bg-blue-900 text-white'],
            'source' => 'plugin:g7plus-paypal',
            'defaults' => [
                'pg_provider' => PaypalCheckoutService::PROVIDER_ID,
                'pg_locked' => true,
                'needs_pg' => true,
                'refund_method' => 'pg',
                // PayPal 환불 API 가 그 자리에서 구매자 계정으로 돌려준다 — 사람이 송금할 단계가 없다.
                'auto_approve_refund' => true,
                'is_active' => false,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
            ],
        ];
    }
}
