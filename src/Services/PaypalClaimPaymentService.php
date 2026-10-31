<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Services;

use App\Support\SiteUrl;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;
use Plugins\G7plus\Paypal\Exceptions\PaypalCheckoutException;
use Plugins\G7plus\Paypal\Support\PaypalCurrency;

/**
 * 클레임(교환·반품) 추가결제의 PayPal 흐름.
 *
 * 클레임 결제 건(ClaimPayment, 결제번호 CP…)은 주문이 아니다 — 이커머스는 주문 결제와 다른 필터
 * (`claim_payment.approve` / `refund` / `void_approval`)로 결제 플러그인에 위임한다. 계약의 단일 출처는
 * 이커머스 `docs/api/claim-payments.md` 「결제 플러그인 연동 계약」 절이다.
 *
 * 주문 결제와 같은 원칙을 지킨다 — 청구액은 서버(`ClaimPaymentService::resolvePaymentCharge()`)가 정하고,
 * 캡처 직전에 PayPal 주문을 다시 조회해 custom_id(결제번호)·금액·통화를 대조한다. 결제 건에는 PayPal 주문
 * ID 를 저장할 칸이 없으므로, 복귀 token 의 소속은 PayPal 이 돌려준 custom_id 로 확인한다(서버 간 조회라 위조 불가).
 */
class PaypalClaimPaymentService
{
    /**
     * @param  PaypalApiService  $api  PayPal API
     * @param  PaypalCheckoutService  $checkoutService  허용 통화 판정
     * @param  ClaimPaymentService  $claimPaymentService  클레임 결제 청구액
     * @param  CurrencyConversionService  $currencyService  통화 소수 자릿수
     */
    public function __construct(
        private readonly PaypalApiService $api,
        private readonly PaypalCheckoutService $checkoutService,
        private readonly ClaimPaymentService $claimPaymentService,
        private readonly CurrencyConversionService $currencyService,
    ) {}

    /**
     * 클레임 결제 건의 PayPal 청구액을 계산합니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return array{currency:string, minor:int, decimals:int, value:string} 청구 통화·금액
     *
     * @throws PaypalCheckoutException 청구할 수 없는 통화·금액
     */
    public function resolveCharge(ClaimPayment $payment): array
    {
        $charge = $this->claimPaymentService->resolvePaymentCharge($payment);

        if ($charge === null) {
            throw new PaypalCheckoutException('currency_not_allowed');
        }

        $currency = strtoupper($charge['currency']);
        if (! $this->checkoutService->acceptsCurrency($currency)) {
            throw new PaypalCheckoutException('currency_not_allowed');
        }

        $minor = (int) $charge['minor_unit_amount'];
        if ($minor <= 0) {
            throw new PaypalCheckoutException('invalid_amount');
        }

        $payment->loadMissing('order');
        $decimals = $this->currencyService->getDecimalPlaces($currency, $payment->order?->currency_snapshot);

        try {
            $value = PaypalCurrency::minorToValue($minor, $decimals, $currency);
        } catch (InvalidArgumentException) {
            throw new PaypalCheckoutException('invalid_amount');
        }

        return ['currency' => $currency, 'minor' => $minor, 'decimals' => $decimals, 'value' => $value];
    }

    /**
     * 클레임 결제 건의 PayPal 주문을 만들고 승인 주소를 반환합니다.
     *
     * @param  ClaimPayment  $payment  결제 대기 건
     * @param  string  $description  PayPal 화면에 보일 설명
     * @return array{paypal_order_id:string, approve_url:string} PayPal 주문 ID 와 승인 주소
     *
     * @throws PaypalCheckoutException 결제할 수 없는 건
     * @throws PaypalApiException PayPal API 실패
     */
    public function createCheckout(ClaimPayment $payment, string $description = ''): array
    {
        if (! $payment->isPending()) {
            throw new PaypalCheckoutException('order_not_payable');
        }

        if ($payment->pg_provider !== PaypalCheckoutService::PROVIDER_ID) {
            throw new PaypalCheckoutException('payment_method_mismatch');
        }

        if (! $this->api->isConfigured()) {
            throw new PaypalCheckoutException('not_configured', 503);
        }

        $charge = $this->resolveCharge($payment);
        $number = (string) $payment->payment_number;
        $settings = plugin_settings(PaypalCheckoutService::PLUGIN_IDENTIFIER);
        $query = '?order='.rawurlencode($number);

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [array_filter([
                'reference_id' => $number,
                'custom_id' => $number,
                'invoice_id' => $number,
                'description' => mb_substr($description !== '' ? $description : $number, 0, 127),
                'amount' => ['currency_code' => $charge['currency'], 'value' => $charge['value']],
            ])],
            'payment_source' => ['paypal' => ['experience_context' => array_filter([
                'brand_name' => mb_substr(trim((string) ($settings['brand_name'] ?? '')), 0, 127),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => SiteUrl::absolute('/plugins/'.PaypalCheckoutService::PLUGIN_IDENTIFIER.'/payment/return').$query,
                'cancel_url' => SiteUrl::absolute('/plugins/'.PaypalCheckoutService::PLUGIN_IDENTIFIER.'/payment/cancel').$query,
            ], static fn ($v) => $v !== '')]],
        ];

        $paypalOrder = $this->api->createOrder($payload, 'g7-claim-'.$number.'-'.$charge['currency'].'-'.$charge['minor']);

        $paypalOrderId = (string) ($paypalOrder['id'] ?? '');
        $approveUrl = $this->checkoutService->approveUrlOf($paypalOrder);

        if ($paypalOrderId === '' || $approveUrl === null) {
            throw new PaypalCheckoutException('create_failed', 502);
        }

        Log::info('PayPal: claim payment order created', [
            'payment_number' => $number,
            'paypal_order_id' => $paypalOrderId,
            'currency' => $charge['currency'],
            'value' => $charge['value'],
        ]);

        return ['paypal_order_id' => $paypalOrderId, 'approve_url' => $approveUrl];
    }

    /**
     * 승인된 PayPal 주문을 대조한 뒤 캡처합니다. (`claim_payment.approve` 필터의 본체)
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  string  $paypalOrderId  복귀 token (PayPal 주문 ID)
     * @return array<string, mixed> 필터 반환 스키마 {handled, success, transaction_id, paid_amount, raw_response, error_code, error_message}
     */
    public function approve(ClaimPayment $payment, string $paypalOrderId): array
    {
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $paypalOrderId) !== 1) {
            return $this->failure('INVALID_CALLBACK');
        }

        try {
            $charge = $this->resolveCharge($payment);
        } catch (PaypalCheckoutException $e) {
            return $this->failure(strtoupper($e->errorCode));
        }

        try {
            // token 은 브라우저를 거친다 — PayPal 에 조회해 이 결제 건·이 금액의 주문인지 먼저 확인한다.
            $paypalOrder = $this->api->getOrder($paypalOrderId);
            $mismatch = $this->orderMismatch($paypalOrder, $payment, $charge);
            if ($mismatch !== null) {
                Log::warning('PayPal: claim payment order does not match — capture skipped', [
                    'payment_number' => $payment->payment_number,
                    'paypal_order_id' => $paypalOrderId,
                    'reason' => $mismatch,
                ]);

                return $this->failure($mismatch);
            }

            if (($paypalOrder['status'] ?? '') !== 'COMPLETED') {
                $paypalOrder = $this->api->captureOrder($paypalOrderId, 'g7-claimcap-'.$paypalOrderId);
            }
        } catch (PaypalApiException $e) {
            return $this->failure($e->publicCode(), $e->getMessage());
        }

        $capture = $paypalOrder['purchase_units'][0]['payments']['captures'][0] ?? null;
        $captureId = is_array($capture) ? (string) ($capture['id'] ?? '') : '';
        $status = is_array($capture) ? (string) ($capture['status'] ?? '') : '';

        if ($captureId === '') {
            return $this->failure('CAPTURE_FAILED', null, $paypalOrder);
        }

        $captured = PaypalCurrency::valueToMinor((string) ($capture['amount']['value'] ?? ''), $charge['decimals'], (string) ($capture['amount']['currency_code'] ?? ''));
        $currencyMatches = strtoupper((string) ($capture['amount']['currency_code'] ?? '')) === $charge['currency'];

        // 클레임 결제에는 「보류」 상태가 없다 — 보류 캡처는 확정할 길이 없으므로 돌려주고 실패로 끝낸다.
        if ($status !== 'COMPLETED' || ! $currencyMatches || $captured !== $charge['minor']) {
            Log::error('PayPal: claim capture not acceptable — refunding', [
                'payment_number' => $payment->payment_number,
                'capture_id' => $captureId,
                'status' => $status,
                'captured' => $capture['amount'] ?? null,
                'expected' => ['currency' => $charge['currency'], 'value' => $charge['value']],
            ]);
            $this->voidCapture($captureId, (string) ($capture['amount']['currency_code'] ?? $charge['currency']), $payment);

            return $this->failure($status === 'COMPLETED' ? 'AMOUNT_MISMATCH' : 'CAPTURE_'.($status ?: 'FAILED'), null, $paypalOrder);
        }

        Log::info('PayPal: claim payment captured', [
            'payment_number' => $payment->payment_number,
            'capture_id' => $captureId,
            'value' => $charge['value'],
            'currency' => $charge['currency'],
        ]);

        return [
            'handled' => true,
            'success' => true,
            'transaction_id' => $captureId,
            'paid_amount' => $captured,
            'raw_response' => $paypalOrder,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * 캡처를 전액 환불합니다. (보상 — 실패하면 운영자 대사용 critical 로그)
     *
     * @param  string  $captureId  캡처 ID
     * @param  string  $currency  통화
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     * @return bool 환불 요청 성공 여부
     */
    public function voidCapture(string $captureId, string $currency, ClaimPayment $payment): bool
    {
        try {
            $this->api->refundCapture(
                $captureId,
                null,
                $currency,
                __('g7plus-paypal::messages.refund.verification_failed_note'),
                'g7-claimvoid-'.$captureId,
            );

            return true;
        } catch (PaypalApiException $e) {
            Log::critical('PayPal: claim compensating refund failed — manual refund required', [
                'payment_number' => $payment->payment_number,
                'capture_id' => $captureId,
                'error' => $e->getMessage(),
                'debug_id' => $e->getDebugId(),
            ]);

            return false;
        }
    }

    /**
     * PayPal 주문이 이 결제 건·청구액의 것인지 확인합니다.
     *
     * @param  array<string, mixed>  $paypalOrder  PayPal 주문 객체
     * @param  ClaimPayment  $payment  결제 건
     * @param  array{currency:string, minor:int, decimals:int, value:string}  $charge  청구액
     * @return string|null 어긋난 사유 코드 (일치하면 null)
     */
    private function orderMismatch(array $paypalOrder, ClaimPayment $payment, array $charge): ?string
    {
        $unit = $paypalOrder['purchase_units'][0] ?? [];

        if ((string) ($unit['custom_id'] ?? '') !== (string) $payment->payment_number) {
            return 'ORDER_ID_MISMATCH';
        }

        $currency = strtoupper((string) ($unit['amount']['currency_code'] ?? ''));
        $amount = PaypalCurrency::valueToMinor((string) ($unit['amount']['value'] ?? ''), $charge['decimals'], $currency);

        return $currency === $charge['currency'] && $amount === $charge['minor'] ? null : 'AMOUNT_MISMATCH';
    }

    /**
     * 실패 반환값을 조립합니다.
     *
     * @param  string  $code  실패 코드
     * @param  string|null  $message  실패 사유
     * @param  array<string, mixed>|null  $raw  PayPal 원본 응답
     * @return array<string, mixed> 필터 반환값
     */
    private function failure(string $code, ?string $message = null, ?array $raw = null): array
    {
        return [
            'handled' => true,
            'success' => false,
            'transaction_id' => null,
            'paid_amount' => null,
            'raw_response' => $raw,
            'error_code' => $code,
            'error_message' => $message ?? __('g7plus-paypal::messages.claim.failed', ['code' => $code]),
        ];
    }
}
