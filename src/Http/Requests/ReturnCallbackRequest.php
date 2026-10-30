<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Plugins\Hwaryeon\Paypal\Support\ShopRedirectUrl;

/**
 * PayPal 승인·취소 후 브라우저 복귀 요청 검증.
 *
 * GET /plugins/hwaryeon-paypal/payment/return?order={주문번호}&token={PayPal 주문 ID}&PayerID=...
 * GET /plugins/hwaryeon-paypal/payment/cancel?order={주문번호}&token={PayPal 주문 ID}
 *
 * `order` 는 우리가 return_url 에 넣은 값이고, `token`·`PayerID` 는 PayPal 이 붙인다. 셋 다 브라우저를
 * 거치므로 서버는 token 이 그 주문에 저장된 PayPal 주문 ID 와 같은지 대조한 뒤에만 쓴다.
 */
class ReturnCallbackRequest extends FormRequest
{
    /**
     * PG 복귀는 인증 없이 허용합니다.
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
            'order' => ['required', 'string', 'max:40'],
            'token' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'PayerID' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * 검증 실패는 Referer 가 아니라 사이트 주소의 실패 화면으로 보냅니다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     *
     * @throws HttpResponseException 실패 화면으로의 302
     */
    protected function failedValidation(Validator $validator): void
    {
        Log::warning('PayPal: return callback validation failed', [
            'errors' => array_keys($validator->errors()->toArray()),
        ]);

        throw new HttpResponseException(redirect(ShopRedirectUrl::fail(['error' => 'invalid_params'])));
    }
}
