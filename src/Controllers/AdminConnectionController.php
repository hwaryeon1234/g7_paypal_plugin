<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Controllers;

use App\Helpers\ResponseHelper;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;
use Plugins\G7plus\Paypal\Services\PaypalApiService;

/**
 * 관리자 설정 화면의 「연결 테스트」.
 *
 * 저장된 설정(현재 모드)의 키로 PayPal 토큰을 실제로 받아 보고, Webhook ID 로 웹훅을 조회해 등록 주소와
 * 구독 이벤트가 이 사이트와 맞는지 확인한다. 키가 비어 있거나 웹훅 주소가 옛 주소로 남아 있는 상태를 실제 결제·환불
 * 전에 찾기 위한 도구다. 아무것도 바꾸지 않는다.
 */
class AdminConnectionController
{
    /** 이 플러그인이 처리하는 웹훅 이벤트 (설정 화면 안내와 같은 목록) */
    public const WEBHOOK_EVENTS = [
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
    ];

    /**
     * @param  PaypalApiService  $api  PayPal API
     */
    public function __construct(private readonly PaypalApiService $api) {}

    /**
     * 연결 상태를 점검합니다.
     *
     * GET /api/plugins/g7plus-paypal/admin/connection-test
     *
     * @return JsonResponse {mode, credentials, webhook}
     */
    public function test(): JsonResponse
    {
        $expectedUrl = SiteUrl::absolute('/plugins/g7plus-paypal/webhook');
        $credentials = $this->api->checkCredentials();

        $webhook = [
            'status' => 'skipped',
            'expected_url' => $expectedUrl,
            'registered_url' => null,
            'missing_events' => [],
        ];

        if ($credentials === 'ok') {
            $webhook = array_merge($webhook, $this->inspectWebhook($expectedUrl));
        }

        return ResponseHelper::success('common.success', [
            'mode' => $this->api->isSandbox() ? 'sandbox' : 'live',
            'credentials' => $credentials,
            'webhook' => $webhook,
            'ok' => $credentials === 'ok' && $webhook['status'] === 'ok',
        ]);
    }

    /**
     * 설정된 웹훅을 점검합니다.
     *
     * @param  string  $expectedUrl  이 사이트의 웹훅 주소
     * @return array{status:string, registered_url:?string, missing_events:list<string>} 점검 결과
     */
    private function inspectWebhook(string $expectedUrl): array
    {
        try {
            $hook = $this->api->getConfiguredWebhook();
        } catch (PaypalApiException $e) {
            return ['status' => $e->getHttpStatus() === 404 ? 'not_found' : 'error', 'registered_url' => null, 'missing_events' => []];
        }

        if ($hook === null) {
            return ['status' => 'missing', 'registered_url' => null, 'missing_events' => []];
        }

        $registeredUrl = (string) ($hook['url'] ?? '');
        $subscribed = array_map(
            static fn ($event) => is_array($event) ? (string) ($event['name'] ?? '') : '',
            is_array($hook['event_types'] ?? null) ? $hook['event_types'] : [],
        );
        // 「*」(모든 이벤트) 구독이면 빠진 이벤트가 없다.
        $missing = in_array('*', $subscribed, true) ? [] : array_values(array_diff(self::WEBHOOK_EVENTS, $subscribed));

        $status = match (true) {
            rtrim($registeredUrl, '/') !== rtrim($expectedUrl, '/') => 'url_mismatch',
            $missing !== [] => 'events_missing',
            default => 'ok',
        };

        return ['status' => $status, 'registered_url' => $registeredUrl, 'missing_events' => $missing];
    }
}
