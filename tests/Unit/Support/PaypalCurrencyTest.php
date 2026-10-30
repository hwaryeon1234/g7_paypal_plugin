<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Hwaryeon\Paypal\Support\PaypalCurrency;

/**
 * 금액 표현 변환 — 정수 연산만 쓰고, 표현할 수 없는 금액은 반올림하지 않고 거절한다.
 *
 * 순수 함수라 DB 를 쓰지 않는 PHPUnit TestCase 로 둔다.
 */
class PaypalCurrencyTest extends TestCase
{
    /**
     * @return array<string, array{int, int, string, string}>
     */
    public static function minorCases(): array
    {
        return [
            'USD 2자리' => [1250, 2, 'USD', '12.50'],
            'USD 1센트' => [1, 2, 'USD', '0.01'],
            'EUR 큰 금액' => [123456789, 2, 'EUR', '1234567.89'],
            'JPY 0자리' => [1200, 0, 'JPY', '1200'],
            'JPY 를 2자리로 설정한 상점 (정수 엔)' => [120000, 2, 'JPY', '1200'],
            'USD 를 0자리로 설정한 상점' => [13, 0, 'USD', '13.00'],
            '소문자 통화' => [500, 2, 'usd', '5.00'],
        ];
    }

    #[DataProvider('minorCases')]
    public function test_minor_to_value(int $minor, int $decimals, string $currency, string $expected): void
    {
        $this->assertSame($expected, PaypalCurrency::minorToValue($minor, $decimals, $currency));
    }

    public function test_minor_to_value_rejects_fractional_yen(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaypalCurrency::minorToValue(120050, 2, 'JPY');
    }

    public function test_minor_to_value_rejects_krw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PaypalCurrency::minorToValue(10000, 0, 'KRW');
    }

    public function test_value_to_minor_round_trips(): void
    {
        $this->assertSame(1250, PaypalCurrency::valueToMinor('12.50', 2, 'USD'));
        $this->assertSame(1250, PaypalCurrency::valueToMinor('12.5', 2, 'USD'));
        $this->assertSame(1200, PaypalCurrency::valueToMinor('1200', 0, 'JPY'));
        $this->assertSame(120000, PaypalCurrency::valueToMinor('1200', 2, 'JPY'));
    }

    public function test_value_to_minor_rejects_garbage_and_lost_precision(): void
    {
        $this->assertNull(PaypalCurrency::valueToMinor('12.505', 2, 'USD'));
        $this->assertNull(PaypalCurrency::valueToMinor('-1.00', 2, 'USD'));
        $this->assertNull(PaypalCurrency::valueToMinor('1e3', 2, 'USD'));
        $this->assertNull(PaypalCurrency::valueToMinor('', 2, 'USD'));
        $this->assertNull(PaypalCurrency::valueToMinor('10.00', 0, 'KRW'));
    }

    public function test_major_to_value_rounds_to_paypal_precision(): void
    {
        $this->assertSame('5.25', PaypalCurrency::majorToValue(5.25, 'USD'));
        $this->assertSame('0.10', PaypalCurrency::majorToValue(0.1, 'EUR'));
        $this->assertSame('1200', PaypalCurrency::majorToValue(1200.0, 'JPY'));
        $this->assertSame('3.33', PaypalCurrency::majorToValue(3.3333, 'USD'));
    }

    public function test_normalize_allowed_keeps_supported_codes_only_in_catalog_order(): void
    {
        $this->assertSame(['EUR', 'JPY', 'USD'], PaypalCurrency::normalizeAllowed(['usd', 'KRW', 'JPY', 'EUR', 'EUR', 3, null]));
        $this->assertSame([], PaypalCurrency::normalizeAllowed('USD'));
    }

    public function test_krw_is_never_supported(): void
    {
        $this->assertFalse(PaypalCurrency::isSupported('KRW'));
        $this->assertArrayNotHasKey('KRW', PaypalCurrency::SUPPORTED);
    }
}
