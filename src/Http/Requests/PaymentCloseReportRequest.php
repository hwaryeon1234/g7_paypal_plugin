<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 결제 취소·실패 보고 요청 검증.
 *
 * 로그인을 강제하지 않는다 — 비회원도 PayPal 화면에서 취소할 수 있다. 컨트롤러가 주문 소유자(회원 본인 /
 * 비회원 조회 토큰)와 금액을 확인한다.
 */
class PaymentCloseReportRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:60'],
        ];
    }
}
