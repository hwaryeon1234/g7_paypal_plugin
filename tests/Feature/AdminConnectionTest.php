<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Plugins\G7plus\Paypal\Controllers\AdminConnectionController;
use Plugins\G7plus\Paypal\Tests\Concerns\CreatesPaypalOrders;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * 관리자 설정 화면의 연결 테스트 — 키 인증과 웹훅 등록 상태 점검.
 */
class AdminConnectionTest extends PluginTestCase
{
    use CreatesPaypalOrders;

    private const URL = '/api/plugins/g7plus-paypal/admin/connection-test';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePaypalSettings();
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.settings.read']);
    }

    /**
     * 웹훅 조회 응답을 만듭니다.
     *
     * @param  string  $url  등록 주소
     * @param  list<string>  $events  구독 이벤트
     * @return array<string, mixed> 응답
     */
    private function webhook(string $url, array $events = AdminConnectionController::WEBHOOK_EVENTS): array
    {
        return ['id' => 'WH-TEST-1', 'url' => $url, 'event_types' => array_map(fn ($e) => ['name' => $e], $events)];
    }

    public function test_everything_ok(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v1/notifications/webhooks/WH-TEST-1' => Http::response($this->webhook(url('/plugins/g7plus-paypal/webhook'))),
        ]);

        $this->actingAs($this->admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.mode', 'sandbox')
            ->assertJsonPath('data.credentials', 'ok')
            ->assertJsonPath('data.webhook.status', 'ok')
            ->assertJsonPath('data.ok', true);
    }

    public function test_empty_keys_are_reported_without_calling_paypal(): void
    {
        $this->usePaypalSettings(['sandbox_client_id' => '', 'sandbox_client_secret' => '']);
        Http::fake();

        $this->actingAs($this->admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.credentials', 'missing')
            ->assertJsonPath('data.webhook.status', 'skipped')
            ->assertJsonPath('data.ok', false);

        Http::assertNothingSent();
    }

    public function test_rejected_keys_are_reported_as_invalid(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->actingAs($this->admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.credentials', 'invalid');
    }

    public function test_old_webhook_url_is_detected(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v1/notifications/webhooks/WH-TEST-1' => Http::response($this->webhook('https://shop.example/plugins/hwaryeon-paypal/webhook')),
        ]);

        $this->actingAs($this->admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.webhook.status', 'url_mismatch')
            ->assertJsonPath('data.webhook.registered_url', 'https://shop.example/plugins/hwaryeon-paypal/webhook');
    }

    public function test_missing_events_are_listed(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v1/notifications/webhooks/WH-TEST-1' => Http::response(
                $this->webhook(url('/plugins/g7plus-paypal/webhook'), ['PAYMENT.CAPTURE.COMPLETED']),
            ),
        ]);

        $this->actingAs($this->admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.webhook.status', 'events_missing')
            ->assertJsonCount(4, 'data.webhook.missing_events');
    }

    public function test_unknown_webhook_id_is_reported(): void
    {
        Http::fake($this->tokenFake() + [
            'api-m.sandbox.paypal.com/v1/notifications/webhooks/WH-TEST-1' => Http::response(['name' => 'INVALID_RESOURCE_ID'], 404),
        ]);

        $this->actingAs($this->admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.webhook.status', 'not_found');
    }

    public function test_requires_admin(): void
    {
        $this->actingAs(User::factory()->create())->getJson(self::URL)->assertForbidden();
    }
}
