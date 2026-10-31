<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Unit;

use App\Services\LanguagePack\LanguagePackManifestValidator;
use App\Services\LanguagePack\LanguagePackPhpArrayValidator;
use Plugins\G7plus\Paypal\Tests\PluginTestCase;

/**
 * 일본어 언어팩을 그누보드7 코어의 설치 검증기에 그대로 통과시켜 봅니다.
 */
class LanguagePackValidatorTest extends PluginTestCase
{
    public function test_core_validators_accept_the_language_pack(): void
    {
        $root = dirname(__DIR__, 2).'/lang-pack';
        $manifest = json_decode((string) file_get_contents($root.'/language-pack.json'), true);

        app(LanguagePackManifestValidator::class)->validate($manifest, $root);

        // 설치기는 언어팩 PHP 가 번역 배열만 담았는지 토큰 단위로 검사한다(코드 실행 차단).
        foreach (glob($root.'/backend/ja/*.php') as $file) {
            $this->assertNull(LanguagePackPhpArrayValidator::inspectFile($file), basename($file));
        }
    }
}
