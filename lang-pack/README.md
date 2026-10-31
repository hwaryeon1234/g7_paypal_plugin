# PayPal 플러그인 일본어 언어팩 (g7plus-plugin-g7plus-paypal-ja)

[g7plus-paypal](../README.md) 플러그인의 문구를 일본어로 제공하는 그누보드7 언어팩입니다.

## 설치

아래 주소에서 zip 을 내려받아 관리자 > 언어팩 > 설치 > **ZIP 업로드** 탭에 올립니다. 이 주소는 항상 최신 Release 에 첨부된 언어팩을 가리킵니다.

```text
https://github.com/hwaryeon1234/g7_paypal_plugin/releases/latest/download/g7plus-plugin-g7plus-paypal-ja.zip
```

관리자 화면의 **GitHub** 탭에는 넣을 수 없습니다 — 그 칸은 저장소 주소(`https://github.com/owner/repo`)만 받고 저장소 전체를 언어팩으로 설치하는데, 이 저장소의 본체는 플러그인이기 때문입니다.

명령줄(서버에서 주소로 바로 설치):

```bash
php artisan language-pack:install https://github.com/hwaryeon1234/g7_paypal_plugin/releases/latest/download/g7plus-plugin-g7plus-paypal-ja.zip --source=url
```

- PayPal 플러그인(g7plus-paypal) 2.1.0 이상이 설치돼 있어야 합니다.
- 일본어 코어 언어팩(`g7-core-ja`)이 켜져 있어야 활성화됩니다.
- 새 버전은 같은 주소로 다시 설치하면 됩니다(GitHub 업데이트 알림 대상이 아닙니다 — 플러그인과 저장소를 함께 쓰기 때문).

## 구조

| 경로 | 내용 |
|---|---|
| `language-pack.json` | 매니페스트 |
| `backend/ja/*.php` | 서버 문구 (플러그인 `lang/ko/*.php` 와 같은 키) |
| `frontend/ja.json` | 화면 문구 (플러그인 `resources/lang/ko.json` 과 같은 키) |
| `seed/manifest.json` | 플러그인 이름·설명 |

플러그인에 문구 키를 더하면 이 언어팩에도 같은 키를 더하고 `version` 과 CHANGELOG 를 올립니다(키가 빠지면 플러그인 테스트가 실패합니다).
