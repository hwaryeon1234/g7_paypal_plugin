# 페이팔 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
서버 주도 리다이렉트 방식입니다. PayPal JS SDK(스마트 버튼)를 쓰지 않는 이유는 두 가지입니다 — ① 그누보드7 체크아웃은
주문 생성 후 `pg_payment_handler` 하나를 dispatch 하는 계약이라, 버튼을 미리 그려 두는 SDK 흐름과 맞지 않고 ② 외부 스크립트를
주입하지 않으면 `trusted_script_hosts` 신뢰 목록과 CSP 부담이 없습니다. 대가로 PayPal 화면이 팝업이 아닌 전체 페이지 이동으로 열립니다.

금액 SSoT 는 이커머스 `CurrencyConversionService::resolveSnapshotPaymentCharge()` 입니다(주문 시점 통화 스냅샷 환율·절사 규칙).
PayPal 표현(주 단위 10진 문자열)과 이커머스 표현(최소 화폐단위 정수)의 변환은 `PaypalCurrency` 가 정수 연산으로만 하며,
표현할 수 없는 금액은 반올림하지 않고 거절합니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```text
브라우저 ─ requestPayment.ts ─▶ CheckoutController ─▶ PaypalCheckoutService::createCheckout ─▶ PaypalApiService::createOrder
PayPal  ─ 302 ─▶ PaymentCallbackController@return ─▶ PaypalCheckoutService::captureAndRecord ─▶ captureOrder ─▶ OrderProcessingService::completePayment
PayPal  ─ POST ─▶ WebhookController ─▶ verifyWebhookSignature ─▶ captureAndRecord / completeCaptureFromWebhook / recordPaymentFailure
이커머스 ─ payment.refund 필터 ─▶ PaymentRefundListener ─▶ PaypalApiService::refundCapture
브라우저 ─ paymentCloseReport.ts ─▶ PaymentCloseReportController ─▶ RecordsPaymentFailure (소유자 확인 후)
```

`PaypalApiService` 만 PayPal HTTP 를 알고, `PaypalCheckoutService` 만 주문 상태를 바꿉니다(실패 기록은 `RecordsPaymentFailure`).
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update hwaryeon-paypal --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update hwaryeon-paypal --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update hwaryeon-paypal --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update hwaryeon-paypal --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update hwaryeon-paypal --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update hwaryeon-paypal --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
