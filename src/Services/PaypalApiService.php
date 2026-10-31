<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Services;

use App\Contracts\Extension\CacheInterface;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugins\G7plus\Paypal\Exceptions\PaypalApiException;

/**
 * PayPal REST API 호출 서비스 (Orders v2 · Payments v2 · Webhooks v1).
 *
 * - 인증: Client ID/Secret 으로 OAuth2 client_credentials 토큰을 받아 플러그인 캐시에 보관한다.
 *   캐시 키에는 모드와 Client ID 해시를 넣는다 — 운영자가 키를 바꾸면 이전 토큰을 쓰지 않는다.
 * - 멱등: 상태를 바꾸는 호출(주문 생성·캡처·환불)은 `PayPal-Request-Id` 를 싣는다. 네트워크 재시도로 같은
 *   요청이 두 번 닿아도 PayPal 이 첫 응답을 돌려줘 이중 캡처·이중 환불이 생기지 않는다.
 * - 비밀값(Secret·토큰)은 로그·예외 메시지에 싣지 않는다.
 */
class PaypalApiService
{
    private const PLUGIN_IDENTIFIER = 'g7plus-paypal';

    private const LIVE_BASE_URL = 'https://api-m.paypal.com';

    private const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    /** 요청 타임아웃(초) — 캡처 왕복이 콜백 락 TTL(30초)보다 짧아야 한다. */
    private const TIMEOUT_SECONDS = 20;

    private bool $sandbox;

    private string $clientId;

    private string $clientSecret;

    private string $webhookId;

    /**
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     * @param  CacheInterface  $cache  플러그인 캐시 (액세스 토큰 보관)
     */
    public function __construct(
        PluginSettingsService $pluginSettingsService,
        private readonly CacheInterface $cache,
    ) {
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->sandbox = (bool) ($settings['is_sandbox'] ?? true);
        $prefix = $this->sandbox ? 'sandbox_' : 'live_';
        $this->clientId = trim((string) ($settings[$prefix.'client_id'] ?? ''));
        $this->clientSecret = trim((string) ($settings[$prefix.'client_secret'] ?? ''));
        $this->webhookId = trim((string) ($settings[$prefix.'webhook_id'] ?? ''));
    }

    /**
     * 샌드박스 모드인지 반환합니다.
     *
     * @return bool 샌드박스 여부
     */
    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * API 자격증명이 설정됐는지 반환합니다.
     *
     * @return bool 설정 여부
     */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * 웹훅 ID 가 설정됐는지 반환합니다.
     *
     * @return bool 설정 여부
     */
    public function hasWebhookId(): bool
    {
        return $this->webhookId !== '';
    }

    /**
     * 현재 모드의 자격증명으로 토큰을 새로 받아 봅니다. (관리자 연결 테스트용)
     *
     * 캐시된 토큰을 버리고 다시 발급받으므로, 키를 바꾼 직후에도 실제 키로 판정한다.
     *
     * @return string missing | ok | invalid | unreachable
     */
    public function checkCredentials(): string
    {
        if (! $this->isConfigured()) {
            return 'missing';
        }

        $this->forgetAccessToken();

        try {
            $this->accessToken();

            return 'ok';
        } catch (PaypalApiException $e) {
            return $e->getErrorName() === 'CONNECTION_ERROR' ? 'unreachable' : 'invalid';
        }
    }

    /**
     * 설정된 웹훅을 PayPal 에서 조회합니다. (GET /v1/notifications/webhooks/{id})
     *
     * @return array<string, mixed>|null 웹훅 객체 (ID 미설정이면 null)
     *
     * @throws PaypalApiException API 호출 실패 시 (없는 ID 는 404)
     */
    public function getConfiguredWebhook(): ?array
    {
        if (! $this->hasWebhookId()) {
            return null;
        }

        return $this->request('GET', '/v1/notifications/webhooks/'.rawurlencode($this->webhookId));
    }

    /**
     * PayPal 주문을 생성합니다. (POST /v2/checkout/orders)
     *
     * @param  array<string, mixed>  $payload  주문 생성 본문
     * @param  string  $requestId  멱등키 (PayPal-Request-Id)
     * @return array<string, mixed> PayPal 주문 객체
     *
     * @throws PaypalApiException API 호출 실패 시
     */
    public function createOrder(array $payload, string $requestId): array
    {
        return $this->request('POST', '/v2/checkout/orders', $payload, [
            'PayPal-Request-Id' => $requestId,
            'Prefer' => 'return=representation',
        ]);
    }

    /**
     * PayPal 주문을 조회합니다. (GET /v2/checkout/orders/{id})
     *
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @return array<string, mixed> PayPal 주문 객체
     *
     * @throws PaypalApiException API 호출 실패 시
     */
    public function getOrder(string $paypalOrderId): array
    {
        return $this->request('GET', '/v2/checkout/orders/'.rawurlencode($paypalOrderId));
    }

    /**
     * 구매자가 승인한 PayPal 주문을 캡처합니다. (POST /v2/checkout/orders/{id}/capture)
     *
     * @param  string  $paypalOrderId  PayPal 주문 ID
     * @param  string  $requestId  멱등키 (PayPal-Request-Id)
     * @return array<string, mixed> 캡처된 PayPal 주문 객체
     *
     * @throws PaypalApiException API 호출 실패 시
     */
    public function captureOrder(string $paypalOrderId, string $requestId): array
    {
        return $this->request('POST', '/v2/checkout/orders/'.rawurlencode($paypalOrderId).'/capture', null, [
            'PayPal-Request-Id' => $requestId,
            'Prefer' => 'return=representation',
        ]);
    }

    /**
     * 캡처를 조회합니다. (GET /v2/payments/captures/{id})
     *
     * @param  string  $captureId  캡처 ID
     * @return array<string, mixed> 캡처 객체
     *
     * @throws PaypalApiException API 호출 실패 시
     */
    public function getCapture(string $captureId): array
    {
        return $this->request('GET', '/v2/payments/captures/'.rawurlencode($captureId));
    }

    /**
     * 캡처를 환불합니다. (POST /v2/payments/captures/{id}/refund)
     *
     * @param  string  $captureId  캡처 ID
     * @param  string|null  $value  환불 금액 문자열 (null 이면 남은 금액 전액)
     * @param  string  $currency  통화 코드
     * @param  string|null  $note  구매자에게 보일 메모 (최대 255자)
     * @param  string  $requestId  멱등키 (PayPal-Request-Id)
     * @return array<string, mixed> 환불 객체
     *
     * @throws PaypalApiException API 호출 실패 시
     */
    public function refundCapture(string $captureId, ?string $value, string $currency, ?string $note, string $requestId): array
    {
        $payload = [];

        if ($value !== null) {
            $payload['amount'] = ['value' => $value, 'currency_code' => strtoupper($currency)];
        }

        if ($note !== null && $note !== '') {
            $payload['note_to_payer'] = mb_substr($note, 0, 255);
        }

        return $this->request('POST', '/v2/payments/captures/'.rawurlencode($captureId).'/refund', $payload ?: null, [
            'PayPal-Request-Id' => $requestId,
            'Prefer' => 'return=representation',
        ]);
    }

    /**
     * 웹훅 서명을 PayPal 에 검증받습니다. (POST /v1/notifications/verify-webhook-signature)
     *
     * 본문은 받은 원문을 그대로 `webhook_event` 에 끼워 보낸다. 디코드 후 다시 인코드하면 슬래시·유니코드·
     * 숫자 표기가 달라져 정상 이벤트도 검증에 실패한다.
     *
     * @param  array<string, string>  $headers  PayPal 전송 헤더 (auth_algo, cert_url, transmission_id, transmission_sig, transmission_time)
     * @param  string  $rawBody  웹훅 원문 본문
     * @return bool 검증 성공 여부 (웹훅 ID 미설정·헤더 누락이면 false)
     */
    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        if (! $this->hasWebhookId() || trim($rawBody) === '') {
            return false;
        }

        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $key) {
            if (! isset($headers[$key]) || $headers[$key] === '') {
                return false;
            }
        }

        $envelope = json_encode([
            'auth_algo' => $headers['auth_algo'],
            'cert_url' => $headers['cert_url'],
            'transmission_id' => $headers['transmission_id'],
            'transmission_sig' => $headers['transmission_sig'],
            'transmission_time' => $headers['transmission_time'],
            'webhook_id' => $this->webhookId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // 마지막 `}` 를 떼고 원문 이벤트를 붙인다.
        $body = substr($envelope, 0, -1).',"webhook_event":'.$rawBody.'}';

        try {
            $response = $this->send('POST', '/v1/notifications/verify-webhook-signature', $body);
        } catch (PaypalApiException $e) {
            Log::warning('PayPal: webhook signature verification call failed', [
                'http_status' => $e->getHttpStatus(),
                'debug_id' => $e->getDebugId(),
            ]);

            return false;
        }

        return ($response['verification_status'] ?? null) === 'SUCCESS';
    }

    /**
     * JSON 요청을 보냅니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  API 경로
     * @param  array<string, mixed>|null  $payload  본문 (null 이면 빈 객체)
     * @param  array<string, string>  $headers  추가 헤더
     * @return array<string, mixed> 응답 JSON
     *
     * @throws PaypalApiException API 호출 실패 시
     */
    private function request(string $method, string $path, ?array $payload = null, array $headers = []): array
    {
        $body = $method === 'GET'
            ? null
            : ($payload === null ? '{}' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $this->send($method, $path, $body, $headers);
    }

    /**
     * 인증 토큰을 붙여 요청을 보내고, 토큰이 만료됐으면 한 번 새로 받아 재시도합니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  API 경로
     * @param  string|null  $body  JSON 본문 문자열
     * @param  array<string, string>  $headers  추가 헤더
     * @return array<string, mixed> 응답 JSON
     *
     * @throws PaypalApiException API 호출 실패 시
     */
    private function send(string $method, string $path, ?string $body, array $headers = []): array
    {
        $response = $this->dispatch($method, $path, $body, $headers, $this->accessToken());

        if ($response->status() === 401) {
            $this->forgetAccessToken();
            $response = $this->dispatch($method, $path, $body, $headers, $this->accessToken());
        }

        if ($response->failed()) {
            throw $this->toException($response, $method, $path);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * HTTP 요청 1회를 보냅니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  API 경로
     * @param  string|null  $body  JSON 본문 문자열
     * @param  array<string, string>  $headers  추가 헤더
     * @param  string  $token  액세스 토큰
     * @return Response 응답
     *
     * @throws PaypalApiException 네트워크 오류
     */
    private function dispatch(string $method, string $path, ?string $body, array $headers, string $token): Response
    {
        $client = Http::baseUrl($this->baseUrl())
            ->timeout(self::TIMEOUT_SECONDS)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders($headers);

        if ($body !== null) {
            $client = $client->withBody($body, 'application/json');
        }

        try {
            return $client->send($method, $path);
        } catch (ConnectionException $e) {
            Log::error('PayPal: API connection failed', ['method' => $method, 'path' => $path, 'error' => $e->getMessage()]);

            throw new PaypalApiException(__('g7plus-paypal::messages.errors.connection_failed'), 0, 'CONNECTION_ERROR');
        }
    }

    /**
     * 캐시된 액세스 토큰을 반환하고, 없으면 새로 받습니다.
     *
     * @return string 액세스 토큰
     *
     * @throws PaypalApiException 자격증명 미설정·발급 실패
     */
    private function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new PaypalApiException(__('g7plus-paypal::messages.errors.not_configured'), 0, 'NOT_CONFIGURED');
        }

        $cached = $this->cache->get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->acceptJson()
                ->asForm()
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException $e) {
            Log::error('PayPal: token connection failed', ['error' => $e->getMessage()]);

            throw new PaypalApiException(__('g7plus-paypal::messages.errors.connection_failed'), 0, 'CONNECTION_ERROR');
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($response->failed() || $token === '') {
            Log::error('PayPal: access token request failed', [
                'http_status' => $response->status(),
                'error' => $response->json('error'),
                'sandbox' => $this->sandbox,
            ]);

            throw new PaypalApiException(__('g7plus-paypal::messages.errors.auth_failed'), $response->status(), 'AUTHENTICATION_FAILURE');
        }

        // 만료 1분 전에 버린다 — 요청 도중 만료돼 401 을 맞는 일을 줄인다.
        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 60);
        $this->cache->put($this->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * 캐시된 액세스 토큰을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function forgetAccessToken(): void
    {
        $this->cache->forget($this->tokenCacheKey());
    }

    /**
     * 액세스 토큰 캐시 키를 만듭니다.
     *
     * @return string 캐시 키
     */
    private function tokenCacheKey(): string
    {
        return 'access_token:'.($this->sandbox ? 'sandbox' : 'live').':'.sha1($this->clientId);
    }

    /**
     * 모드에 맞는 API 베이스 URL 을 반환합니다.
     *
     * @return string 베이스 URL
     */
    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
    }

    /**
     * 실패 응답을 예외로 바꾸고 기록합니다.
     *
     * @param  Response  $response  실패 응답
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  API 경로
     * @return PaypalApiException 예외
     */
    private function toException(Response $response, string $method, string $path): PaypalApiException
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        $issues = [];
        $fields = [];
        foreach (($json['details'] ?? []) as $detail) {
            if (is_array($detail) && is_string($detail['issue'] ?? null)) {
                $issues[] = $detail['issue'];
            }
            if (is_array($detail) && is_string($detail['field'] ?? null)) {
                $fields[] = $detail['field'];
            }
        }

        $name = is_string($json['name'] ?? null) ? $json['name'] : null;
        $debugId = is_string($json['debug_id'] ?? null) ? $json['debug_id'] : null;
        $message = is_string($json['message'] ?? null) ? $json['message'] : 'PayPal API request failed.';

        Log::error('PayPal: API request failed', [
            'method' => $method,
            'path' => $path,
            'http_status' => $response->status(),
            'name' => $name,
            'issues' => $issues,
            'fields' => $fields,
            'debug_id' => $debugId,
        ]);

        return new PaypalApiException($message, $response->status(), $name, $issues, $debugId, $fields);
    }
}
