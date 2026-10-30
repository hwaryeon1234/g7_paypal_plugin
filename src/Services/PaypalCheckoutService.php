<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Services;

use App\Extension\HookManager;
use App\Support\LocalizedValue;
use App\Support\SiteUrl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Hwaryeon\Paypal\Concerns\SerializesPaymentCallbacks;
use Plugins\Hwaryeon\Paypal\Exceptions\PaypalApiException;
use Plugins\Hwaryeon\Paypal\Exceptions\PaypalCheckoutException;
use Plugins\Hwaryeon\Paypal\Support\CaptureOutcome;
use Plugins\Hwaryeon\Paypal\Support\PaypalCurrency;

/**
 * PayPal 결제 흐름 (주문 생성 → 구매자 승인 → 서버 캡처 → 금액 대조 → 결제완료).
 *
 * 금액은 언제나 서버가 정한다. PayPal 주문은 서버가 주문 스냅샷의 청구액으로 만들고, 브라우저는 승인 주소로
 * 이동만 한다. 캡처 응답의 금액·통화·주문번호(custom_id)를 다시 청구액과 대조하고, 어긋나면 캡처를 환불해
 * 되돌린 뒤 실패로 끝낸다 — 브라우저를 거친 값은 어떤 단계에서도 금액 근거가 되지 않는다.
 *
 * 브라우저 복귀와 웹훅이 같은 `captureAndRecord()` 를 같은 주문 락 아래에서 탄다. 구매자가 승인 후 창을
 * 닫아 복귀하지 않아도 웹훅(CHECKOUT.ORDER.APPROVED)이 캡처를 마무리한다.
 */
class PaypalCheckoutService
{
    use SerializesPaymentCallbacks;

    public const PLUGIN_IDENTIFIER = 'hwaryeon-paypal';

    /** PG 제공자 id (registered_pg_providers) */
    public const PROVIDER_ID = 'paypal';

    /** 결제수단 id (filter_available_payment_methods) */
    public const METHOD_ID = 'paypal';

    /** PayPal 승인 화면으로 보낼 수 있는 호스트 */
    private const APPROVE_HOSTS = ['www.paypal.com', 'www.sandbox.paypal.com', 'paypal.com', 'sandbox.paypal.com'];

    /** PayPal 이 설명 필드에 허용하는 최대 길이 */
    private const DESCRIPTION_MAX = 127;

    /**
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  CurrencyConversionService  $currencyService  통화 환산 서비스 (청구액 SSoT)
     * @param  PaypalApiService  $api  PayPal API
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly CurrencyConversionService $currencyService,
        private readonly PaypalApiService $api,
    ) {}

    /**
     * 운영자가 허용한 PayPal 결제 통화 목록을 반환합니다.
     *
     * 설정이 없으면(설치 직후) PayPal 지원 통화 전체다. 운영자가 전부 해제해 빈 배열로 저장했으면 빈 목록이다.
     *
     * @return list<string> 통화 코드
     */
    public function allowedCurrencies(): array
    {
        $settings = plugin_settings(self::PLUGIN_IDENTIFIER);

        if (! array_key_exists('allowed_currencies', $settings) || $settings['allowed_currencies'] === null) {
            return array_keys(PaypalCurrency::SUPPORTED);
        }

        return PaypalCurrency::normalizeAllowed($settings['allowed_currencies']);
    }

    /**
     * 이 통화 주문에서 PayPal 을 고를 수 있는지 판정합니다.
     *
     * @param  string|null  $currency  결제 통화
     * @return bool 허용 여부
     */
    public function acceptsCurrency(?string $currency): bool
    {
        return is_string($currency) && in_array(strtoupper($currency), $this->allowedCurrencies(), true);
    }

    /**
     * 주문의 PayPal 청구액을 계산합니다.
     *
     * @param  Order  $order  대상 주문
     * @return array{currency:string, minor:int, decimals:int, value:string} 청구 통화·금액
     *
     * @throws PaypalCheckoutException 청구할 수 없는 통화·금액
     */
    public function resolveCharge(Order $order): array
    {
        try {
            $charge = $this->currencyService->resolveSnapshotPaymentCharge(
                (float) $order->total_due_amount,
                $order->currency_snapshot ?? [],
            );
        } catch (InvalidArgumentException $e) {
            Log::error('PayPal: order currency is not chargeable', [
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            throw new PaypalCheckoutException('currency_not_allowed');
        }

        $currency = strtoupper((string) $charge['currency']);

        if (! $this->acceptsCurrency($currency)) {
            throw new PaypalCheckoutException('currency_not_allowed');
        }

        $minor = (int) $charge['minor_unit_amount'];
        $decimals = (int) $charge['decimal_places'];

        if ($minor <= 0) {
            throw new PaypalCheckoutException('invalid_amount');
        }

        try {
            $value = PaypalCurrency::minorToValue($minor, $decimals, $currency);
        } catch (InvalidArgumentException) {
            // 예: 운영자가 JPY 를 소수 2자리로 설정해 ¥1,200.50 이 나온 경우 — PayPal 은 엔화 소수를 받지 않는다.
            throw new PaypalCheckoutException('invalid_amount');
        }

        return ['currency' => $currency, 'minor' => $minor, 'decimals' => $decimals, 'value' => $value];
    }

    /**
     * 주문이 결제를 받을 수 있는 상태인지 확인합니다.
     *
     * @param  Order  $order  대상 주문
     * @return bool 결제 가능 여부
     */
    public function isPayable(Order $order): bool
    {
        if (! $order->order_status?->isBeforePayment()) {
            return false;
        }

        return ! ($order->payment?->isPaid() ?? false);
    }

    /**
     * PayPal 주문을 만들고 구매자를 보낼 승인 주소를 반환합니다.
     *
     * 같은 주문·같은 금액으로 다시 부르면 같은 PayPal-Request-Id 를 실어 PayPal 이 같은 주문을 돌려준다 —
     * 더블클릭·재시도로 PayPal 주문이 여러 개 생기지 않는다.
     *
     * @param  Order  $order  대상 주문
     * @return array{paypal_order_id:string, approve_url:string} PayPal 주문 ID 와 승인 주소
     *
     * @throws PaypalCheckoutException 결제를 시작할 수 없는 주문
     * @throws PaypalApiException PayPal API 실패
     */
    public function createCheckout(Order $order): array
    {
        if (! $this->isPayable($order)) {
            throw new PaypalCheckoutException('order_not_payable');
        }

        if ($order->payment?->paymentMethodId() !== self::METHOD_ID) {
            throw new PaypalCheckoutException('payment_method_mismatch');
        }

        if (! $this->api->isConfigured()) {
            throw new PaypalCheckoutException('not_configured', 503);
        }

        $charge = $this->resolveCharge($order);
        $requestId = 'g7-order-'.$order->order_number.'-'.$charge['currency'].'-'.$charge['minor'];

        $payload = $this->buildOrderPayload($order, $charge, withShipping: true);

        HookManager::doAction('hwaryeon-paypal.payment.before_create', $order, $payload);

        try {
            $paypalOrder = $this->api->createOrder($payload, $requestId);
        } catch (PaypalApiException $e) {
            // 해외 주소 형식은 나라마다 달라 PayPal 이 배송지를 거절할 수 있다. 그때는 배송지 없이 다시 만든다 —
            // 배송지가 없어도 결제는 성립하고, 배송은 쇼핑몰 주문의 주소로 한다.
            if (! $this->isShippingAddressRejection($e) || ! isset($payload['purchase_units'][0]['shipping'])) {
                throw $e;
            }

            Log::warning('PayPal: shipping address rejected, retrying without shipping', [
                'order_number' => $order->order_number,
                'issues' => $e->getIssues(),
            ]);

            $payload = $this->buildOrderPayload($order, $charge, withShipping: false);
            $paypalOrder = $this->api->createOrder($payload, $requestId.'-noship');
        }

        $paypalOrderId = (string) ($paypalOrder['id'] ?? '');
        $approveUrl = $this->findLink($paypalOrder, 'payer-action') ?? $this->findLink($paypalOrder, 'approve');

        if ($paypalOrderId === '' || $approveUrl === null || ! $this->isTrustedApproveUrl($approveUrl)) {
            Log::error('PayPal: create order response has no usable approval link', [
                'order_number' => $order->order_number,
                'paypal_order_id' => $paypalOrderId,
                'status' => $paypalOrder['status'] ?? null,
            ]);

            throw new PaypalCheckoutException('create_failed', 502);
        }

        $payment = $order->payment;
        $meta = $payment->payment_meta ?? [];
        $meta['paypal_order_id'] = $paypalOrderId;
        $meta['paypal_approve_url'] = $approveUrl;
        $meta['paypal_charge'] = Arr::only($charge, ['currency', 'minor', 'decimals', 'value']);
        $meta['paypal_mode'] = $this->api->isSandbox() ? 'sandbox' : 'live';

        $payment->update([
            'pg_provider' => self::PROVIDER_ID,
            'payment_status' => PaymentStatusEnum::IN_PROGRESS->value,
            'payment_meta' => $meta,
        ]);

        Log::info('PayPal: order created', [
            'order_number' => $order->order_number,
            'paypal_order_id' => $paypalOrderId,
            'currency' => $charge['currency'],
            'value' => $charge['value'],
        ]);

        return ['paypal_order_id' => $paypalOrderId, 'approve_url' => $approveUrl];
    }

    /**
     * 구매자가 승인한 PayPal 주문을 캡처하고 결과를 주문에 반영합니다.
     *
     * 브라우저 복귀와 웹훅이 함께 쓰며, 주문 단위 락으로 직렬화된다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $paypalOrderId  PayPal 주문 ID (복귀 쿼리의 token 또는 웹훅 resource.id)
     * @param  Request|null  $request  브라우저 요청 (결제 기기 판별, 웹훅이면 null)
     * @return CaptureOutcome 결과
     */
    public function captureAndRecord(Order $order, string $paypalOrderId, ?Request $request = null): CaptureOutcome
    {
        $lock = null;

        try {
            $lock = $this->acquireOrderCallbackLock((string) $order->order_number);

            return $this->captureLocked($order->fresh(['payment']) ?? $order, $paypalOrderId, $request);
        } catch (LockTimeoutException) {
            $latest = $order->fresh(['payment']) ?? $order;

            return $this->isRecordedWith($latest, $paypalOrderId)
                ? CaptureOutcome::of(CaptureOutcome::ALREADY_RECORDED)
                : CaptureOutcome::failed('callback_locked');
        } finally {
            $this->releaseOrderCallbackLock($lock);
        }
    }

    /**
     * 웹훅이 알려 온 캡처 완료(보류 해제 포함)를 반영합니다.
     *
     * 웹훅 본문은 서명 검증을 거쳤지만, 금액은 본문이 아니라 PayPal 에서 다시 조회한 캡처로 대조한다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $captureId  캡처 ID
     * @return CaptureOutcome 결과
     */
    public function completeCaptureFromWebhook(Order $order, string $captureId): CaptureOutcome
    {
        $lock = null;

        try {
            $lock = $this->acquireOrderCallbackLock((string) $order->order_number);
            $order = $order->fresh(['payment']) ?? $order;

            if ($order->payment?->isPaid()) {
                return CaptureOutcome::of(CaptureOutcome::ALREADY_RECORDED);
            }

            $meta = $order->payment?->payment_meta ?? [];
            $knownCapture = (string) ($meta['paypal_capture_id'] ?? '');

            // 이 주문에서 우리가 만든 캡처가 아니면 반영하지 않는다.
            if ($knownCapture !== '' && ! hash_equals($knownCapture, $captureId)) {
                return CaptureOutcome::failed('capture_mismatch');
            }

            $capture = $this->api->getCapture($captureId);
            $paypalOrderId = (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');
            $expectedOrderId = (string) ($meta['paypal_order_id'] ?? '');

            if ($expectedOrderId === '' || ! hash_equals($expectedOrderId, $paypalOrderId)) {
                return CaptureOutcome::failed('paypal_order_mismatch');
            }

            if ((string) ($capture['custom_id'] ?? '') !== (string) $order->order_number) {
                return CaptureOutcome::failed('capture_mismatch');
            }

            // 캡처 조회 응답에는 구매자·결제수단 정보가 없다 — 복귀 때 저장해 둔 값을 그대로 쓴다.
            return $this->recordCapture($order, $capture, $paypalOrderId, [], null);
        } catch (LockTimeoutException) {
            return CaptureOutcome::failed('callback_locked');
        } catch (PaypalApiException $e) {
            return CaptureOutcome::failed($e->publicCode());
        } finally {
            $this->releaseOrderCallbackLock($lock);
        }
    }

    /**
     * 락을 쥔 상태에서 캡처를 수행합니다.
     *
     * @param  Order  $order  대상 주문 (최신)
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @param  Request|null  $request  브라우저 요청
     * @return CaptureOutcome 결과
     */
    private function captureLocked(Order $order, string $paypalOrderId, ?Request $request): CaptureOutcome
    {
        $meta = $order->payment?->payment_meta ?? [];
        $expectedPaypalOrderId = (string) ($meta['paypal_order_id'] ?? '');

        // 복귀 쿼리의 token 은 브라우저를 거친다 — 이 주문에 우리가 만든 PayPal 주문이어야 한다.
        if ($expectedPaypalOrderId === '' || ! hash_equals($expectedPaypalOrderId, $paypalOrderId)) {
            Log::warning('PayPal: token does not belong to the order', [
                'order_number' => $order->order_number,
                'token' => $paypalOrderId,
            ]);

            return CaptureOutcome::failed('token_mismatch');
        }

        if ($this->isRecordedWith($order, $paypalOrderId)) {
            return CaptureOutcome::of(CaptureOutcome::ALREADY_RECORDED);
        }

        if (! $this->isPayable($order)) {
            return CaptureOutcome::failed('order_not_payable');
        }

        // 승인 화면을 연 사이 주문 금액이 바뀌었으면 캡처하지 않는다(쿠폰 취소·관리자 수정 등).
        try {
            $charge = $this->resolveCharge($order);
        } catch (PaypalCheckoutException $e) {
            return CaptureOutcome::failed($e->errorCode);
        }

        $created = $meta['paypal_charge'] ?? [];
        if (($created['currency'] ?? null) !== $charge['currency'] || (int) ($created['minor'] ?? -1) !== $charge['minor']) {
            Log::warning('PayPal: order charge changed after PayPal order creation — capture skipped', [
                'order_number' => $order->order_number,
                'created' => $created,
                'current' => Arr::only($charge, ['currency', 'minor']),
            ]);

            return CaptureOutcome::failed('amount_mismatch');
        }

        // 멱등키 — 복귀와 웹훅이 같은 시도를 겹쳐 보내도 PayPal 이 한 번만 캡처한다. 카드 거절 후 재승인은 새 시도라
        // 번호를 올린다(같은 키로 다시 보내면 PayPal 이 앞 시도의 거절 응답을 그대로 돌려줄 수 있다).
        $attempt = (int) ($meta['paypal_capture_attempt'] ?? 0);
        $captureRequestId = 'g7-capture-'.$paypalOrderId.($attempt > 0 ? '-'.$attempt : '');

        try {
            $paypalOrder = $this->api->captureOrder($paypalOrderId, $captureRequestId);
        } catch (PaypalApiException $e) {
            // 카드 거절 등 — PayPal 권장대로 승인 화면으로 돌려보내 다른 결제수단을 고르게 한다.
            if ($e->hasIssue('INSTRUMENT_DECLINED') && is_string($meta['paypal_approve_url'] ?? null)) {
                $order->payment->update(['payment_meta' => array_merge($meta, ['paypal_capture_attempt' => $attempt + 1])]);

                return CaptureOutcome::retryApproval($meta['paypal_approve_url']);
            }

            // 다른 경로(웹훅·다른 탭)가 먼저 캡처했다 — 주문을 조회해 같은 대조를 거친다.
            if ($e->hasIssue('ORDER_ALREADY_CAPTURED')) {
                try {
                    $paypalOrder = $this->api->getOrder($paypalOrderId);
                } catch (PaypalApiException $inner) {
                    return CaptureOutcome::failed($inner->publicCode());
                }
            } else {
                return CaptureOutcome::failed($e->publicCode());
            }
        }

        $capture = $paypalOrder['purchase_units'][0]['payments']['captures'][0] ?? null;

        if (! is_array($capture)) {
            Log::error('PayPal: capture response has no capture', [
                'order_number' => $order->order_number,
                'paypal_order_id' => $paypalOrderId,
                'status' => $paypalOrder['status'] ?? null,
            ]);

            return CaptureOutcome::failed('capture_failed');
        }

        // custom_id 는 우리가 만들 때 넣은 주문번호다 — 다른 주문의 PayPal 결제를 끌어오는 것을 막는다.
        $customId = (string) ($paypalOrder['purchase_units'][0]['custom_id'] ?? $capture['custom_id'] ?? '');
        if ($customId !== (string) $order->order_number) {
            Log::error('PayPal: custom_id does not match the order — refunding', [
                'order_number' => $order->order_number,
                'custom_id' => $customId,
            ]);
            $this->refundQuietly($capture, $order, 'custom_id_mismatch');

            return CaptureOutcome::failed('amount_mismatch');
        }

        return $this->recordCapture($order, $capture, $paypalOrderId, $paypalOrder, $request);
    }

    /**
     * 캡처를 청구액과 대조하고 결과를 주문에 반영합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<string, mixed>  $capture  캡처 객체
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @param  array<string, mixed>  $paypalOrder  PayPal 주문 객체 (구매자·결제수단 정보)
     * @param  Request|null  $request  브라우저 요청
     * @return CaptureOutcome 결과
     */
    private function recordCapture(Order $order, array $capture, string $paypalOrderId, array $paypalOrder, ?Request $request): CaptureOutcome
    {
        HookManager::doAction('hwaryeon-paypal.payment.after_capture', $order, $capture);

        $captureId = (string) ($capture['id'] ?? '');
        $status = (string) ($capture['status'] ?? '');

        if (in_array($status, ['DECLINED', 'FAILED'], true) || $captureId === '') {
            Log::warning('PayPal: capture not successful', [
                'order_number' => $order->order_number,
                'status' => $status,
            ]);

            return CaptureOutcome::failed('capture_declined');
        }

        try {
            $charge = $this->resolveCharge($order);
        } catch (PaypalCheckoutException $e) {
            $this->refundQuietly($capture, $order, $e->errorCode);

            return CaptureOutcome::failed($e->errorCode);
        }

        $currency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
        $captured = PaypalCurrency::valueToMinor((string) ($capture['amount']['value'] ?? ''), $charge['decimals'], $currency);

        if ($currency !== $charge['currency'] || $captured !== $charge['minor']) {
            Log::error('PayPal: captured amount does not match the order charge — refunding', [
                'order_number' => $order->order_number,
                'expected' => Arr::only($charge, ['currency', 'value']),
                'captured' => $capture['amount'] ?? null,
            ]);
            $this->refundQuietly($capture, $order, 'amount_mismatch');

            return CaptureOutcome::failed('amount_mismatch');
        }

        // 캡처 왕복 사이에 주문이 결제 불가가 됐으면(관리자 취소 등) 결과를 쓰지 않고 돈을 돌려준다.
        $order = $order->fresh(['payment']) ?? $order;
        if (! $this->isPayable($order)) {
            if ($this->isRecordedWith($order, $paypalOrderId)) {
                return CaptureOutcome::of(CaptureOutcome::ALREADY_RECORDED);
            }

            $this->refundQuietly($capture, $order, 'order_not_payable');

            return CaptureOutcome::failed('order_not_payable');
        }

        $meta = $order->payment?->payment_meta ?? [];
        $source = isset($paypalOrder['payment_source'])
            ? $this->describePaymentSource($paypalOrder['payment_source'])
            : ($meta['paypal_source'] ?? $this->describePaymentSource([]));

        $meta = array_merge($meta, [
            'paypal_order_id' => $paypalOrderId,
            'paypal_capture_id' => $captureId,
            'paypal_capture_status' => $status,
            'paypal_source' => $source,
            'paypal_seller_breakdown' => $capture['seller_receivable_breakdown'] ?? ($meta['paypal_seller_breakdown'] ?? null),
            'paypal_seller_protection' => $capture['seller_protection']['status'] ?? ($meta['paypal_seller_protection'] ?? null),
        ]);

        if (isset($paypalOrder['payer']) && is_array($paypalOrder['payer'])) {
            $meta['paypal_payer'] = array_filter([
                'payer_id' => $paypalOrder['payer']['payer_id'] ?? null,
                'email' => $paypalOrder['payer']['email_address'] ?? null,
                'country' => $paypalOrder['payer']['address']['country_code'] ?? null,
            ]);
        }

        // PENDING(eCheck·검토 보류 등) — 아직 돈이 확정되지 않았다. 결제 진행중으로 두고 웹훅이 완료시킨다.
        if ($status === 'PENDING') {
            $order->payment->update([
                'pg_provider' => self::PROVIDER_ID,
                'payment_status' => PaymentStatusEnum::IN_PROGRESS->value,
                'transaction_id' => $captureId,
                'payment_meta' => $meta + ['paypal_pending_reason' => $capture['status_details']['reason'] ?? null],
            ]);

            Log::info('PayPal: capture pending', [
                'order_number' => $order->order_number,
                'capture_id' => $captureId,
                'reason' => $capture['status_details']['reason'] ?? null,
            ]);

            return CaptureOutcome::of(CaptureOutcome::PENDING);
        }

        if ($status !== 'COMPLETED') {
            return CaptureOutcome::failed('capture_failed');
        }

        try {
            $this->orderService->completePayment($order, [
                'transaction_id' => $captureId,
                'card_name' => $source['label'],
                'card_number_masked' => $source['masked'],
                'embedded_pg_provider' => $source['funding'],
                'payment_meta' => $meta,
                'payment_device' => $request !== null ? DeviceDetector::detect($request) : null,
            ], $charge['minor']);
        } catch (PaymentAmountMismatchException $e) {
            // 모듈이 컴포넌트 재합산에서 금액을 거절했다 — 결제완료로 가지 않으므로 돈을 돌려준다.
            Log::error('PayPal: module rejected the payment amount — refunding', [
                'order_number' => $order->order_number,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);
            $this->refundQuietly($capture, $order, 'amount_mismatch');

            return CaptureOutcome::failed('amount_mismatch');
        }

        Log::info('PayPal: payment completed', [
            'order_number' => $order->order_number,
            'capture_id' => $captureId,
            'currency' => $charge['currency'],
            'value' => $charge['value'],
        ]);

        return CaptureOutcome::of(CaptureOutcome::PAID);
    }

    /**
     * 같은 PayPal 주문이 이미 결제완료(또는 보류)로 기록됐는지 확인합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @return bool 기록됐으면 true
     */
    public function isRecordedWith(Order $order, string $paypalOrderId): bool
    {
        $payment = $order->payment;

        if (! $payment || $paypalOrderId === '' || ($payment->payment_meta['paypal_order_id'] ?? null) !== $paypalOrderId) {
            return false;
        }

        if ($payment->isPaid()) {
            return true;
        }

        return ($payment->payment_meta['paypal_capture_status'] ?? null) === 'PENDING' && ! empty($payment->transaction_id);
    }

    /**
     * 대조에 실패한 캡처를 조용히 환불합니다. (보상 — 실패해도 흐름은 계속, 운영자 대사용 로그)
     *
     * @param  array<string, mixed>  $capture  캡처 객체
     * @param  Order  $order  대상 주문 (로그 문맥)
     * @param  string  $reason  환불 사유 코드
     * @return void 반환값 없음
     */
    private function refundQuietly(array $capture, Order $order, string $reason): void
    {
        $captureId = (string) ($capture['id'] ?? '');
        $status = (string) ($capture['status'] ?? '');

        if ($captureId === '' || ! in_array($status, ['COMPLETED', 'PENDING'], true)) {
            return;
        }

        try {
            $this->api->refundCapture(
                $captureId,
                null,
                (string) ($capture['amount']['currency_code'] ?? 'USD'),
                __('hwaryeon-paypal::messages.refund.verification_failed_note'),
                'g7-void-'.$captureId,
            );

            Log::warning('PayPal: capture refunded after verification failure', [
                'order_number' => $order->order_number,
                'capture_id' => $captureId,
                'reason' => $reason,
            ]);
        } catch (PaypalApiException $e) {
            Log::critical('PayPal: compensating refund failed — manual refund required', [
                'order_number' => $order->order_number,
                'capture_id' => $captureId,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'debug_id' => $e->getDebugId(),
            ]);
        }
    }

    /**
     * PayPal 주문 생성 본문을 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array{currency:string, minor:int, decimals:int, value:string}  $charge  청구액
     * @param  bool  $withShipping  배송지를 실을지
     * @return array<string, mixed> 본문
     */
    private function buildOrderPayload(Order $order, array $charge, bool $withShipping): array
    {
        $settings = plugin_settings(self::PLUGIN_IDENTIFIER);
        $orderNumber = (string) $order->order_number;
        $shipping = $withShipping ? $this->buildShipping($order) : null;

        $unit = array_filter([
            'reference_id' => $orderNumber,
            'custom_id' => mb_substr($orderNumber, 0, 127),
            'invoice_id' => mb_substr($orderNumber, 0, 127),
            'description' => $this->buildDescription($order),
            'amount' => [
                'currency_code' => $charge['currency'],
                'value' => $charge['value'],
            ],
            'shipping' => $shipping,
        ], static fn ($v) => $v !== null && $v !== '');

        $returnQuery = '?order='.rawurlencode($orderNumber);

        $experience = array_filter([
            'brand_name' => mb_substr(trim((string) ($settings['brand_name'] ?? '')), 0, 127),
            'landing_page' => $this->landingPage($settings['landing_page'] ?? null),
            'shipping_preference' => $shipping !== null ? 'SET_PROVIDED_ADDRESS' : 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => SiteUrl::absolute('/plugins/'.self::PLUGIN_IDENTIFIER.'/payment/return').$returnQuery,
            'cancel_url' => SiteUrl::absolute('/plugins/'.self::PLUGIN_IDENTIFIER.'/payment/cancel').$returnQuery,
        ], static fn ($v) => $v !== null && $v !== '');

        $paypalSource = ['experience_context' => $experience];

        $email = (string) ($order->shippingAddress?->orderer_email ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $paypalSource['email_address'] = $email;
        }

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => ['paypal' => $paypalSource],
        ];
    }

    /**
     * 주문 설명(상품명 요약)을 만듭니다. 해외 고객이 보므로 영어 이름을 우선합니다.
     *
     * @param  Order  $order  대상 주문
     * @return string 설명 (최대 127자)
     */
    private function buildDescription(Order $order): string
    {
        $options = $order->options;
        $first = $options->first()?->product_name;
        $locale = $order->shippingAddress?->orderer_locale ?: 'en';

        $name = is_array($first)
            ? (LocalizedValue::pick($first, $locale) ?? '')
            : (string) ($first ?? '');

        if ($options->count() > 1) {
            $name .= ' '.__('hwaryeon-paypal::messages.order_description_more', ['count' => $options->count() - 1], 'en');
        }

        $name = trim($name) !== '' ? $name : 'Order '.$order->order_number;

        return mb_substr($name, 0, self::DESCRIPTION_MAX);
    }

    /**
     * 주문 배송지를 PayPal shipping 객체로 바꿉니다. 정보가 모자라면 null 입니다.
     *
     * PayPal 판매자 보호는 거래에 기록된 주소로의 배송 증빙을 요구하므로, 가능하면 배송지를 싣는다.
     *
     * @param  Order  $order  대상 주문
     * @return array<string, mixed>|null shipping 객체
     */
    private function buildShipping(Order $order): ?array
    {
        $address = $order->shippingAddress;
        $country = strtoupper(trim((string) ($address?->recipient_country_code ?? '')));

        if ($address === null || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return null;
        }

        $line1 = trim((string) ($address->address_line_1 ?: $address->address));
        $line2 = trim((string) ($address->address_line_2 ?: $address->address_detail));
        $city = trim((string) ($address->intl_city ?: $address->recipient_city));
        $state = trim((string) ($address->intl_state ?: $address->recipient_province_code));
        $postal = trim((string) ($address->intl_postal_code ?: $address->zipcode));
        $name = trim((string) ($address->recipient_name ?? ''));

        if ($line1 === '' || $name === '') {
            return null;
        }

        return [
            'name' => ['full_name' => mb_substr($name, 0, 300)],
            'address' => array_filter([
                'address_line_1' => mb_substr($line1, 0, 300),
                'address_line_2' => mb_substr($line2, 0, 300),
                'admin_area_2' => mb_substr($city, 0, 120),
                'admin_area_1' => mb_substr($state, 0, 300),
                'postal_code' => mb_substr($postal, 0, 60),
                'country_code' => $country,
            ], static fn ($v) => $v !== ''),
        ];
    }

    /**
     * 배송지 거절 오류인지 판정합니다.
     *
     * @param  PaypalApiException  $e  PayPal 오류
     * @return bool 배송지 문제면 true
     */
    private function isShippingAddressRejection(PaypalApiException $e): bool
    {
        foreach ($e->getIssues() as $issue) {
            if (str_contains($issue, 'SHIPPING') || str_contains($issue, 'POSTAL_CODE') || str_contains($issue, 'COUNTRY') || str_contains($issue, 'ADDRESS')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 결제수단 정보를 화면 표시용으로 요약합니다.
     *
     * @param  mixed  $source  PayPal payment_source
     * @return array{funding:string|null, label:string|null, masked:string|null} 요약
     */
    private function describePaymentSource(mixed $source): array
    {
        if (! is_array($source) || $source === []) {
            return ['funding' => 'paypal', 'label' => 'PayPal', 'masked' => null];
        }

        $funding = (string) array_key_first($source);
        $detail = is_array($source[$funding] ?? null) ? $source[$funding] : [];

        if ($funding === 'card') {
            $brand = (string) ($detail['brand'] ?? 'Card');
            $last = (string) ($detail['last_digits'] ?? '');

            return ['funding' => 'card', 'label' => 'PayPal ('.$brand.')', 'masked' => $last !== '' ? '**** '.$last : null];
        }

        $label = match ($funding) {
            'paypal' => 'PayPal',
            'venmo' => 'Venmo',
            'paylater', 'pay_later' => 'PayPal Pay Later',
            default => 'PayPal ('.ucfirst($funding).')',
        };

        return ['funding' => $funding, 'label' => $label, 'masked' => null];
    }

    /**
     * landing_page 설정값을 PayPal 허용값으로 맞춥니다.
     *
     * @param  mixed  $value  설정값
     * @return string PayPal landing_page
     */
    private function landingPage(mixed $value): string
    {
        return in_array($value, ['LOGIN', 'GUEST_CHECKOUT', 'NO_PREFERENCE'], true) ? $value : 'NO_PREFERENCE';
    }

    /**
     * HATEOAS 링크에서 rel 이 일치하는 href 를 찾습니다.
     *
     * @param  array<string, mixed>  $resource  PayPal 리소스
     * @param  string  $rel  링크 rel
     * @return string|null href
     */
    private function findLink(array $resource, string $rel): ?string
    {
        foreach (($resource['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === $rel && is_string($link['href'] ?? null)) {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * 승인 주소가 PayPal 도메인의 https 주소인지 확인합니다.
     *
     * @param  string  $url  승인 주소
     * @return bool 신뢰 가능하면 true
     */
    private function isTrustedApproveUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::APPROVE_HOSTS, true);
    }
}
