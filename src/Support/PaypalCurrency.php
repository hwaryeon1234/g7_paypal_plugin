<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Support;

use InvalidArgumentException;

/**
 * PayPal 결제 통화 SSoT — 지원 통화 목록, 통화별 소수 자릿수, 금액 문자열 변환.
 *
 * PayPal REST API 는 금액을 「주 단위 10진 문자열」(`"12.50"`)로 받는다. 이커머스 모듈은 PG 청구액을
 * 「쇼핑몰 통화 설정의 decimal_places 기준 최소 화폐단위 정수」(USD 2자리 → 1250)로 준다. 두 표현의
 * 자릿수가 어긋날 수 있으므로(예: 운영자가 JPY 를 2자리로 설정) 변환은 전부 정수 연산으로 하고, PayPal 이
 * 받을 수 없는 끝자리가 남으면 반올림하지 않고 거절한다 — 반올림하면 청구액과 승인액이 달라진다.
 *
 * KRW 는 PayPal 이 결제 통화로 지원하지 않는다. 이 플러그인은 해외 고객 결제용이며, 원화 주문에는
 * PayPal 결제수단이 아예 노출되지 않는다(RestrictPaymentMethodsForCurrencyListener).
 *
 * 출처: https://developer.paypal.com/reference/currency-codes/
 */
final class PaypalCurrency
{
    /**
     * PayPal 이 결제 통화로 지원하는 통화 → 소수 자릿수.
     *
     * HUF·JPY·TWD 는 PayPal 이 소수 금액을 받지 않는다(0자리).
     *
     * @var array<string, int>
     */
    public const SUPPORTED = [
        'AUD' => 2,
        'BRL' => 2,
        'CAD' => 2,
        'CNY' => 2,
        'CZK' => 2,
        'DKK' => 2,
        'EUR' => 2,
        'HKD' => 2,
        'HUF' => 0,
        'ILS' => 2,
        'JPY' => 0,
        'MYR' => 2,
        'MXN' => 2,
        'TWD' => 0,
        'NZD' => 2,
        'NOK' => 2,
        'PHP' => 2,
        'PLN' => 2,
        'GBP' => 2,
        'SGD' => 2,
        'SEK' => 2,
        'CHF' => 2,
        'THB' => 2,
        'USD' => 2,
    ];

    /**
     * 해당 국가에 등록된 PayPal 계정만 받을 수 있는 통화 (관리 화면 안내용).
     *
     * @var list<string>
     */
    public const IN_COUNTRY_ONLY = ['BRL', 'CNY', 'MYR'];

    /**
     * PayPal 이 지원하는 통화인지 판정합니다.
     *
     * @param  string|null  $currency  ISO 4217 코드
     * @return bool 지원 여부
     */
    public static function isSupported(?string $currency): bool
    {
        return is_string($currency) && isset(self::SUPPORTED[strtoupper($currency)]);
    }

    /**
     * PayPal 기준 소수 자릿수를 반환합니다.
     *
     * @param  string  $currency  ISO 4217 코드
     * @return int 소수 자릿수
     *
     * @throws InvalidArgumentException 지원하지 않는 통화
     */
    public static function decimals(string $currency): int
    {
        $code = strtoupper($currency);

        if (! isset(self::SUPPORTED[$code])) {
            throw new InvalidArgumentException("Currency {$code} is not supported by PayPal.");
        }

        return self::SUPPORTED[$code];
    }

    /**
     * 운영자가 고른 허용 통화 설정을 정규화합니다.
     *
     * PayPal 미지원 코드(KRW 등)·중복·형식이 깨진 값은 버린다. 순서는 SUPPORTED 선언 순서를 따른다.
     *
     * @param  mixed  $value  설정값 (배열 기대)
     * @return list<string> 허용 통화 코드
     */
    public static function normalizeAllowed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $wanted = [];
        foreach ($value as $code) {
            if (is_string($code)) {
                $wanted[strtoupper(trim($code))] = true;
            }
        }

        return array_values(array_filter(
            array_keys(self::SUPPORTED),
            static fn (string $code): bool => isset($wanted[$code]),
        ));
    }

    /**
     * 쇼핑몰 최소 화폐단위 정수를 PayPal 금액 문자열로 바꿉니다.
     *
     * @param  int  $minorAmount  쇼핑몰 기준 최소 화폐단위 금액 (예: USD 2자리 설정이면 1250 = $12.50)
     * @param  int  $shopDecimals  쇼핑몰 통화 설정의 소수 자릿수
     * @param  string  $currency  ISO 4217 코드
     * @return string PayPal 금액 문자열 (예: "12.50", JPY "1200")
     *
     * @throws InvalidArgumentException 지원하지 않는 통화, 음수, PayPal 자릿수로 표현할 수 없는 금액
     */
    public static function minorToValue(int $minorAmount, int $shopDecimals, string $currency): string
    {
        if ($minorAmount < 0 || $shopDecimals < 0) {
            throw new InvalidArgumentException('Amount and decimals must not be negative.');
        }

        $paypalDecimals = self::decimals($currency);
        $units = self::rescale($minorAmount, $shopDecimals, $paypalDecimals);

        return self::formatUnits($units, $paypalDecimals);
    }

    /**
     * PayPal 금액 문자열을 쇼핑몰 최소 화폐단위 정수로 바꿉니다.
     *
     * 승인·캡처 응답의 금액을 청구액과 대조할 때 쓴다. 부동소수를 거치지 않는다.
     *
     * @param  string  $value  PayPal 금액 문자열
     * @param  int  $shopDecimals  쇼핑몰 통화 설정의 소수 자릿수
     * @param  string  $currency  ISO 4217 코드
     * @return int|null 최소 화폐단위 정수, 형식이 깨졌거나 표현할 수 없으면 null
     */
    public static function valueToMinor(string $value, int $shopDecimals, string $currency): ?int
    {
        if (! self::isSupported($currency) || $shopDecimals < 0) {
            return null;
        }

        if (preg_match('/^(\d{1,15})(?:\.(\d{1,6}))?$/D', trim($value), $m) !== 1) {
            return null;
        }

        $fraction = $m[2] ?? '';
        $digits = strlen($fraction);
        $units = (int) ($m[1].$fraction);

        try {
            return self::rescale($units, $digits, $shopDecimals);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * 주 단위 금액(float)을 PayPal 금액 문자열로 바꿉니다.
     *
     * 환불처럼 이커머스가 결제 통화 주 단위로 금액을 주는 경로에서 쓴다. PayPal 자릿수에서 반올림한다.
     *
     * @param  float  $majorAmount  주 단위 금액 (예: 12.5)
     * @param  string  $currency  ISO 4217 코드
     * @return string PayPal 금액 문자열
     *
     * @throws InvalidArgumentException 지원하지 않는 통화, 음수
     */
    public static function majorToValue(float $majorAmount, string $currency): string
    {
        if ($majorAmount < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        $decimals = self::decimals($currency);
        $units = (int) round($majorAmount * (10 ** $decimals));

        return self::formatUnits($units, $decimals);
    }

    /**
     * 정수 금액의 소수 자릿수를 바꿉니다. 끝자리가 버려지면 예외입니다.
     *
     * @param  int  $units  정수 금액
     * @param  int  $fromDecimals  현재 자릿수
     * @param  int  $toDecimals  목표 자릿수
     * @return int 바뀐 정수 금액
     *
     * @throws InvalidArgumentException 버려지는 끝자리가 0 이 아닐 때
     */
    private static function rescale(int $units, int $fromDecimals, int $toDecimals): int
    {
        if ($fromDecimals === $toDecimals) {
            return $units;
        }

        if ($fromDecimals < $toDecimals) {
            return $units * (10 ** ($toDecimals - $fromDecimals));
        }

        $divisor = 10 ** ($fromDecimals - $toDecimals);

        if ($units % $divisor !== 0) {
            throw new InvalidArgumentException('Amount has more decimal places than the currency allows.');
        }

        return intdiv($units, $divisor);
    }

    /**
     * 정수 금액을 소수점 문자열로 씁니다.
     *
     * @param  int  $units  정수 금액
     * @param  int  $decimals  소수 자릿수
     * @return string 금액 문자열
     */
    private static function formatUnits(int $units, int $decimals): string
    {
        if ($decimals === 0) {
            return (string) $units;
        }

        $scale = 10 ** $decimals;

        return intdiv($units, $scale).'.'.str_pad((string) ($units % $scale), $decimals, '0', STR_PAD_LEFT);
    }
}
