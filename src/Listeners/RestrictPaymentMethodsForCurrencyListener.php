<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Hwaryeon\Paypal\Services\PaypalCheckoutService;

/**
 * 결제 통화별 PayPal 노출 제한.
 *
 * 쇼핑몰 필터 `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency` 에 답한다. 쇼핑몰은 이 판정을
 * 주문서 표시(allowed_payment_methods)와 주문 생성 검증(CreateOrderRequest) 양쪽에 쓰므로, 직접 POST 로도
 * 허용되지 않은 통화의 PayPal 주문은 만들어지지 않는다.
 *
 * - 원화(KRW) 등 PayPal 미지원 통화: 항상 뺀다.
 * - 운영자가 「PayPal 결제 허용 통화」에서 해제한 통화: 뺀다.
 * - 다른 결제수단은 건드리지 않는다.
 */
class RestrictPaymentMethodsForCurrencyListener implements HookListenerInterface
{
    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.checkout.filter_payment_methods_for_currency' => [
                'method' => 'filter',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
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
     * 이 통화에서 PayPal 을 고를 수 없으면 목록에서 뺍니다.
     *
     * @param  mixed  $methodIds  후보 결제수단 id
     * @param  mixed  $currency  결제 통화
     * @return array<int, string> 남길 결제수단 id
     */
    public function filter(mixed $methodIds, mixed $currency = null): array
    {
        $ids = is_array($methodIds) ? array_values(array_filter($methodIds, 'is_string')) : [];

        if (app(PaypalCheckoutService::class)->acceptsCurrency(is_string($currency) ? $currency : null)) {
            return $ids;
        }

        return array_values(array_filter($ids, static fn (string $id): bool => $id !== PaypalCheckoutService::METHOD_ID));
    }
}
