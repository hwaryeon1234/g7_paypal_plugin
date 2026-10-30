<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PayPal 결제 시작(PayPal 주문 생성) 요청 검증.
 *
 * 금액은 받지 않는다 — 청구액은 서버가 주문 스냅샷으로 정한다. 소유자 확인은 컨트롤러가 한다
 * (비회원도 결제하므로 로그인을 강제하지 않는다).
 */
class CreateCheckoutRequest extends FormRequest
{
    /**
     * 요청을 허용합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 규칙
     */
    public function rules(): array
    {
        return [
            'orderId' => ['required', 'string', 'max:40'],
        ];
    }
}
