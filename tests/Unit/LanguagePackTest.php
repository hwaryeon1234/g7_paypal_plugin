<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 일본어 언어팩(별도 저장소 hwaryeon1234/g7_paypal_plugin_ja)이 플러그인 문구와 어긋나지 않는지 확인합니다.
 *
 * 플러그인에 문구 키를 더하고 언어팩에 빠뜨리면 일본어 화면에 키가 그대로 보인다 — 여기서 먼저 잡는다.
 * 언어팩 경로는 환경변수 G7PLUS_PAYPAL_JA_PACK, 없으면 이 저장소 옆의 g7_paypal_plugin_ja 다. 둘 다 없으면 건너뛴다
 * (GitHub Actions 의 ja-pack-check 워크플로가 같은 대조를 한다).
 */
class LanguagePackTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /**
     * 언어팩 저장소 경로를 찾고, 없으면 테스트를 건너뜁니다.
     *
     * @return string 언어팩 루트
     */
    public static function packRoot(): string
    {
        $candidates = array_filter([getenv('G7PLUS_PAYPAL_JA_PACK') ?: null, self::ROOT.'/../g7_paypal_plugin_ja']);
        foreach ($candidates as $path) {
            if (is_file($path.'/language-pack.json')) {
                return $path;
            }
        }

        self::markTestSkipped('일본어 언어팩 저장소(g7_paypal_plugin_ja)가 없어 건너뜁니다.');
    }

    /**
     * 중첩 배열을 점 표기 키 → 값 목록으로 펼칩니다.
     *
     * @param  array<string, mixed>  $data  배열
     * @param  string  $prefix  접두사
     * @return array<string, string> 펼친 배열
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * 문구 안의 치환 자리(:name, {name})를 모읍니다.
     *
     * @param  string  $text  문구
     * @return list<string> 치환 자리
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+|\{[A-Za-z_]+\}/', $text, $m);
        $found = $m[0];
        sort($found);

        return $found;
    }

    public function test_backend_files_have_the_same_keys_and_placeholders(): void
    {
        foreach (glob(self::ROOT.'/lang/ko/*.php') as $koFile) {
            $jaFile = self::packRoot().'/backend/ja/'.basename($koFile);
            $this->assertFileExists($jaFile);

            $ko = $this->flatten(require $koFile);
            $ja = $this->flatten(require $jaFile);

            $this->assertSame(array_keys($ko), array_keys($ja), basename($koFile).' keys differ');
            foreach ($ko as $key => $text) {
                $this->assertSame($this->placeholders($text), $this->placeholders($ja[$key]), basename($koFile).": {$key} placeholders differ");
            }
        }
    }

    public function test_frontend_file_has_the_same_keys_and_placeholders(): void
    {
        $ko = $this->flatten(json_decode((string) file_get_contents(self::ROOT.'/resources/lang/ko.json'), true));
        $ja = $this->flatten(json_decode((string) file_get_contents(self::packRoot().'/frontend/ja.json'), true));

        $this->assertSame(array_keys($ko), array_keys($ja));
        foreach ($ko as $key => $text) {
            $this->assertSame($this->placeholders($text), $this->placeholders($ja[$key]), "{$key} placeholders differ");
        }
    }

    public function test_manifest_targets_this_plugin_and_version(): void
    {
        $pack = json_decode((string) file_get_contents(self::packRoot().'/language-pack.json'), true);
        $plugin = json_decode((string) file_get_contents(self::ROOT.'/plugin.json'), true);

        $this->assertSame('plugin', $pack['scope']);
        $this->assertSame($plugin['identifier'], $pack['target_identifier']);
        $this->assertSame($pack['namespace'].'-plugin-'.$plugin['identifier'].'-ja', $pack['identifier']);
        $this->assertSame('ja', $pack['locale']);

        $this->assertMatchesRegularExpression('/^>=\d+\.\d+\.\d+$/', $pack['requires']['target_version']);
        $this->assertTrue(version_compare($plugin['version'], substr($pack['requires']['target_version'], 2), '>='));
    }
}
