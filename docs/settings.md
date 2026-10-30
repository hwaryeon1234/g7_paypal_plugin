# 페이팔 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `is_sandbox` | `boolean` | `true` | 샌드박스 모드 |
| `sandbox_client_id` | `string` | - | 샌드박스 Client ID |
| `sandbox_client_secret` | `string` | - | 샌드박스 Secret |
| `sandbox_webhook_id` | `string` | - | 샌드박스 Webhook ID |
| `live_client_id` | `string` | - | 라이브 Client ID |
| `live_client_secret` | `string` | - | 라이브 Secret |
| `live_webhook_id` | `string` | - | 라이브 Webhook ID |
| `allowed_currencies` | `array` | `["AUD","BRL","CAD","CNY","CZK","DKK","EUR","HKD","HUF","ILS","JPY","MYR","MXN","TWD","NZD","NOK","PHP","PLN","GBP","SGD","SEK","CHF","THB","USD"]` | PayPal 결제 허용 통화 |
| `brand_name` | `string` | - | PayPal 결제창 상호명 |
| `landing_page` | `string` | `NO_PREFERENCE` | PayPal 첫 화면 |
| `redirect_success_url` | `string` | `{shopBase}/orders/{orderId}/complete` | 결제 성공 리다이렉트 URL |
| `redirect_fail_url` | `string` | `{shopBase}/checkout` | 결제 실패 리다이렉트 URL |

기본값 파일: `/home/user/hwaryeon-paypal/config/settings/defaults.json` · 설정 화면 레이아웃: `/home/user/hwaryeon-paypal/resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`allowed_currencies` 가 비어 있으면 PayPal 이 어디에도 나타나지 않습니다. 키가 아예 없으면(설치 직후) PayPal 지원 통화 전체로 봅니다. Secret 은 `sensitive` 라 응답에서 가려집니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
자체 권한이 없습니다. 설정 화면은 코어 `core.plugins.update`, 안내 정보 API 는 `sirsoft-ecommerce.settings.read` 를 씁니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
자체 메뉴가 없습니다. 관리자 > 플러그인 목록의 설정 버튼으로 들어갑니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `/home/user/hwaryeon-paypal/src/routes/api.php` | `/api/plugins/hwaryeon-paypal/...` |
| `web` | `/home/user/hwaryeon-paypal/src/routes/web.php` | `/plugins/hwaryeon-paypal/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
`/payment/return`·`/payment/cancel`·`/webhook` 은 인증이 없는 외부 진입점입니다 — 앞의 둘은 token 대조, 웹훅은 서명 검증이 유일한 신뢰 근거입니다. `/payment/checkout`·`/payment/close-report` 는 `optional.sanctum` + 주문 소유자 확인을 거칩니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

| 확장 | 유형 | 버전 제약 | 번들 |
|---|---|---|---|
| `sirsoft-ecommerce` | 모듈 | `>=1.3.0` | ✅ |

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
이커머스 1.3.0 이상이 필요합니다 — 통화별 결제수단 필터(`filter_payment_methods_for_currency`)와 확장 결제수단 id 저장이 그 버전의 계약입니다.
<!-- @intent END -->
