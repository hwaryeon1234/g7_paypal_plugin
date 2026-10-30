<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 쿠키 미동의 방문자에게도 필요한 이 플러그인의 브라우저 저장 키를 개인정보 동의 플러그인(sirsoft-gdpr)에 제공합니다.
 *
 * PayPal 승인은 전체 페이지 이동으로 열리고 돌아오므로, 이동 직전에 남긴 기록이 복귀 화면에서 지워지면 결제 취소
 * 보고가 통째로 누락된다(주문 이행, GDPR Art.6(1)(b)). 동의 플러그인이 없으면 이 훅은 발행되지 않는다.
 */
class ProvideNecessaryStorageKeysListener implements HookListenerInterface
{
    /**
     * 저장소 종류별 제공 키.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYS = [
        'sessionStorage' => [
            'g7:hwaryeon-paypal:pendingClose',
        ],
    ];

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-gdpr.necessary_storage.filter_provided_keys' => [
                'method' => 'provide',
                'type' => 'filter',
                'priority' => 10,
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
     * 지금까지 모인 제공 키에 이 플러그인의 키를 덧붙입니다.
     *
     * @param  mixed  $keys  저장소 종류 => 키 배열
     * @return array<string, array<int, string>> 키가 더해진 배열
     */
    public function provide(mixed $keys): array
    {
        $keys = is_array($keys) ? $keys : [];

        foreach (self::KEYS as $scope => $items) {
            $current = is_array($keys[$scope] ?? null) ? $keys[$scope] : [];
            $keys[$scope] = array_values(array_unique([...$current, ...$items]));
        }

        return $keys;
    }
}
