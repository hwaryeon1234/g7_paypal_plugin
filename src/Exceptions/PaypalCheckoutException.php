<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Exceptions;

use RuntimeException;

/**
 * PayPal 결제를 시작할 수 없는 주문 상태·설정.
 *
 * `errorCode` 는 프론트·실패 화면 쿼리로 내려가는 기계 판독용 코드다(번역 키 `errors.{code}` 와 짝).
 */
class PaypalCheckoutException extends RuntimeException
{
    /**
     * @param  string  $errorCode  기계 판독용 코드 (예: currency_not_allowed)
     * @param  int  $httpStatus  API 응답 상태 코드
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct(__('g7plus-paypal::messages.errors.'.$errorCode));
    }
}
