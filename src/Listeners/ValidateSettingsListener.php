<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Validation\Rule;
use Plugins\G7plus\Paypal\Support\PaypalCurrency;

/**
 * PayPal 설정 저장 검증 규칙.
 *
 * - 허용 통화: PayPal 지원 통화 코드만 받는다(KRW 등은 저장 단계에서 거절).
 * - 결제 후 이동 주소: `{shopBase}`·`/`·`http(s)://` 로 시작하고 공백이 없어야 한다. `//host`(다른 도메인으로 새는
 *   상대 프로토콜)와 `javascript:` 는 받지 않는다.
 * - 첫 화면·상호명: PayPal 이 받는 값과 길이로 제한한다.
 */
class ValidateSettingsListener implements HookListenerInterface
{
    private const IDENTIFIER = 'g7plus-paypal';

    /** 이동 주소 형식 — {shopBase}·/(뒤에 / 아님)·http(s):// 로 시작, 공백 없음 */
    private const REDIRECT_PATTERN = '/^(\{shopBase\}(\/(?![\/\\\\])\S*)?|\/(?![\/\\\\])\S*|https?:\/\/\S+)$/D';

    /**
     * 구독할 훅 매핑 반환.
     *
     * @return array<string, array<string, mixed>> 훅 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.update_validation_rules' => ['method' => 'rules', 'type' => 'filter', 'priority' => 10],
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
     * 이 플러그인 설정 저장에 검증 규칙을 더합니다.
     *
     * @param  array<string, mixed>  $rules  기존 검증 규칙
     * @param  string  $identifier  저장 대상 플러그인 식별자
     * @param  array<string, mixed>  $input  이번 요청 입력값
     * @return array<string, mixed> 규칙
     */
    public function rules(array $rules, string $identifier, array $input = []): array
    {
        if ($identifier !== self::IDENTIFIER) {
            return $rules;
        }

        $rules['allowed_currencies'] = ['sometimes', 'array'];
        $rules['allowed_currencies.*'] = ['string', Rule::in(array_keys(PaypalCurrency::SUPPORTED))];
        $rules['landing_page'] = ['sometimes', 'nullable', Rule::in(['NO_PREFERENCE', 'LOGIN', 'GUEST_CHECKOUT'])];
        $rules['brand_name'] = ['sometimes', 'nullable', 'string', 'max:127'];

        foreach (['sandbox_client_id', 'live_client_id', 'sandbox_webhook_id', 'live_webhook_id'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-]*$/'];
        }

        foreach (['redirect_success_url', 'redirect_fail_url'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (is_string($value) && $value !== '' && preg_match(self::REDIRECT_PATTERN, $value) !== 1) {
                    $fail(__(self::IDENTIFIER.'::messages.settings.redirect_url_format'));
                }
            }];
        }

        return $rules;
    }
}
