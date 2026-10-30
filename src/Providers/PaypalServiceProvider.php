<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Hwaryeon\Paypal\Services\PaypalApiService;

/**
 * PayPal 플러그인 서비스 프로바이더.
 *
 * PayPal 액세스 토큰을 이 플러그인 캐시 도메인(`g7:plugin.hwaryeon-paypal:*`)에 보관하도록
 * API 서비스에 플러그인 CacheInterface 를 주입한다.
 */
class PaypalServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'hwaryeon-paypal';

    /**
     * 플러그인 캐시 드라이버를 주입받을 서비스.
     *
     * @var array<int, class-string>
     */
    protected array $cacheServices = [
        PaypalApiService::class,
    ];
}
