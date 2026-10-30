<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Hwaryeon\Paypal\Plugin;
use Plugins\Hwaryeon\Paypal\Support\PaypalCurrency;

/**
 * plugin.php 는 업데이트 때 src/ 오토로더 없이 읽힌다 — src 클래스를 참조하지 않으면서 같은 통화 목록을 가져야 한다.
 */
class PluginManifestTest extends TestCase
{
    public function test_default_currencies_match_the_supported_catalog_and_defaults_json(): void
    {
        $this->assertSame(array_keys(PaypalCurrency::SUPPORTED), Plugin::DEFAULT_ALLOWED_CURRENCIES);

        $defaults = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/config/settings/defaults.json'), true);
        $this->assertSame(Plugin::DEFAULT_ALLOWED_CURRENCIES, $defaults['defaults']['allowed_currencies']);
    }

    public function test_plugin_php_does_not_reference_src_classes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/plugin.php');

        $this->assertDoesNotMatchRegularExpression('/^use Plugins\\\\Hwaryeon\\\\Paypal\\\\(Support|Services|Concerns|Exceptions|Controllers)\\\\/m', $source);
    }
}
