<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Support;

use App\Support\SiteUrl;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 결제 완료·실패 후 돌아갈 상점 화면 주소를 만듭니다.
 *
 * 상점 주소는 운영자 설정(`basic_info.route_path`)이라 `/shop` 리터럴을 쓰면 주소를 바꾼 상점에서
 * 존재하지 않는 화면으로 떨어진다. 기본값에 `{shopBase}` 자리표시자를 두고 이동 직전에 쇼핑몰 모듈의
 * 해석기로 바꾼다. 최종 주소는 요청 Host 가 아니라 사이트 주소(`SiteUrl`)로 절대화한다.
 */
final class ShopRedirectUrl
{
    private const PLUGIN_IDENTIFIER = 'g7plus-paypal';

    /** 결제 성공 후 이동할 기본 주소 */
    public const DEFAULT_SUCCESS_URL = '{shopBase}/orders/{orderId}/complete';

    /** 결제 실패 후 이동할 기본 주소 */
    public const DEFAULT_FAIL_URL = '{shopBase}/checkout';

    /**
     * 자리표시자를 실제 값으로 바꾼 주소를 반환합니다.
     *
     * @param  string  $template  설정에 저장된 주소 템플릿
     * @param  array<string, string>  $tokens  추가 치환 토큰
     * @return string 치환이 끝난 주소
     */
    public static function resolve(string $template, array $tokens = []): string
    {
        return strtr($template, ['{shopBase}' => ShopPathResolver::base()] + $tokens);
    }

    /**
     * 결제 성공 화면의 절대 주소를 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return string 절대 URL
     */
    public static function success(string $orderNumber): string
    {
        $template = self::setting('redirect_success_url') ?? self::DEFAULT_SUCCESS_URL;

        return SiteUrl::absolute(self::resolve($template, ['{orderId}' => rawurlencode($orderNumber)]));
    }

    /**
     * 결제 실패 화면의 절대 주소를 만듭니다.
     *
     * @param  array<string, string|null>  $query  덧붙일 쿼리 (error, message, orderId)
     * @return string 절대 URL
     */
    public static function fail(array $query = []): string
    {
        $template = self::setting('redirect_fail_url') ?? self::DEFAULT_FAIL_URL;
        $base = SiteUrl::absolute(self::resolve($template));
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');

        if ($query === []) {
            return $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * 비어 있지 않은 주소 설정을 읽습니다.
     *
     * @param  string  $key  설정 키
     * @return string|null 설정값 (비었으면 null)
     */
    private static function setting(string $key): ?string
    {
        $value = plugin_settings(self::PLUGIN_IDENTIFIER)[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
