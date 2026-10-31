# 그누보드7 페이팔 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (g7plus-paypal) — 해외 고객용 PayPal Checkout(Orders v2, 서버 주문 생성 → 승인 리다이렉트 → 서버 캡처). 소유 테이블 없음 — 상태는 sirsoft-ecommerce 결제행(payment_meta) 소유
2. 확장 방식: `RegisterPgProviderListener`/`RegisterPaypalPaymentMethodListener`/`RestrictPaymentMethodsForCurrencyListener` 로 이커머스 레지스트리·통화 필터에 등록 — 이커머스 코드는 이 플러그인을 모른다
3. 건드리면 안 되는 것: 캡처 금액·통화·custom_id 와 서버 청구액 대조(어긋나면 환불), 웹훅 서명 검증(verify-webhook-signature), token ↔ payment_meta.paypal_order_id 대조
4. 작업 위치: `plugins/_bundled/g7plus-paypal` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update g7plus-paypal --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
PayPal 을 `sirsoft-ecommerce` 에 연결하는 어댑터입니다. 대상은 **해외 고객의 외화 주문**입니다 —
PayPal 은 원화(KRW)를 결제 통화로 받지 않으므로, 원화 주문에는 PayPal 결제수단이 아예 노출되지 않습니다.
운영자는 「PayPal 결제 허용 통화」에서 받을 통화를 고릅니다(기본: PayPal 지원 통화 24종 전부).

**승인 방식**: 브라우저 SDK 를 쓰지 않는 서버 주도 리다이렉트입니다. 체크아웃이 주문을 만들면 프론트 핸들러가
`POST /api/plugins/g7plus-paypal/payment/checkout` 을 부르고, 서버가 **주문 스냅샷의 청구액**으로 PayPal 주문을
만들어 승인 주소를 돌려줍니다. 구매자가 PayPal 에서 승인하면 `/payment/return` 으로 돌아오고 서버가 캡처합니다.
금액은 어느 단계에서도 브라우저가 정하지 않습니다.

**설계 원칙**: 상태를 소유하지 않습니다(모델·테이블 0개). PayPal 주문 ID·캡처 ID·구매자 정보는 이커머스 결제행의
`payment_meta`·`transaction_id` 에 둡니다. `merchant_order_id` 는 이커머스 자신의 결제번호(NOT NULL)라 쓰지 않습니다.

**의도적으로 하지 않는 것**: 품목(items) 분해 금액을 PayPal 에 보내지 않습니다 — 쿠폰·마일리지·환산 절사가 섞인 주문은
품목 합이 청구액과 1센트라도 어긋나면 PayPal 이 주문 생성을 거절합니다. PayPal 대시보드에서 직접 낸 환불·차지백은 주문을 자동으로 바꾸지 않고 결제행에 기록만 합니다.
<!-- @intent END -->

## 2. 디렉토리 지도

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
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update g7plus-paypal --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update g7plus-paypal --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update g7plus-paypal --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update g7plus-paypal --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update g7plus-paypal --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update g7plus-paypal --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**결제 시작**: `CheckoutController@store` → `CreateCheckoutRequest`(orderId 만) → 주문 소유자 확인(회원 Sanctum /
비회원 `X-Guest-Order-Token`) → `PaypalCheckoutService::createCheckout()` — 결제 가능 상태·결제수단(`paypal`)·통화 허용
확인 → `CurrencyConversionService::resolveSnapshotPaymentCharge()` 로 청구액(최소 화폐단위) 산정 →
`PaypalCurrency::minorToValue()` 로 PayPal 금액 문자열 → `g7plus-paypal.payment.before_create` 훅 →
`PaypalApiService::createOrder()`(PayPal-Request-Id = 주문번호+통화+금액 → 더블클릭에도 PayPal 주문 1개) →
결제행 `payment_meta.paypal_order_id·paypal_charge` 저장, 상태 `in_progress` → 승인 주소 반환.

**승인 후 복귀**: `PaymentCallbackController@return` → `ReturnCallbackRequest`(order, token) →
`PaypalCheckoutService::captureAndRecord()` — 주문 락 → token 이 저장된 PayPal 주문 ID 와 같은지 → 청구액이 PayPal 주문을
만들 때와 같은지 → `captureOrder()` → `after_capture` 훅 → 캡처 금액·통화·custom_id 를 청구액과 대조(어긋나면
`refundQuietly()` 로 환불 후 실패) → `OrderProcessingService::completePayment()`. 캡처가 `PENDING` 이면 결제행을
`in_progress` 로 두고 웹훅 `PAYMENT.CAPTURE.COMPLETED` 가 완료시킵니다. 카드 거절(`INSTRUMENT_DECLINED`)은 PayPal 권장대로
승인 화면으로 돌려보냅니다.

**웹훅**: `WebhookController@handle` → `PaypalApiService::verifyWebhookSignature()`(원문 본문 그대로) →
`CHECKOUT.ORDER.APPROVED`(구매자가 복귀하지 않은 경우 서버가 캡처) / `PAYMENT.CAPTURE.COMPLETED`(캡처를 PayPal 에서
다시 조회해 대조 후 완료) / `PAYMENT.CAPTURE.DENIED`(실패 기록) / `REFUNDED`·`REVERSED`(기록·경고만).

**환불**: 관리자 취소·반품 → 이커머스 `sirsoft-ecommerce.payment.refund` 필터 → `PaymentRefundListener` →
`refundCapture()`(금액 명시, PayPal-Request-Id = 환불 레코드+금액).

**클레임 추가결제**(결제번호 `CP…`, 2.1.0~): 체크아웃 API·복귀 콜백이 `ClaimPayment::isClaimPaymentNumber()` 로 갈라져
`PaypalClaimPaymentService` 를 탄다. 승인은 이커머스 `ClaimPaymentCallback::approveAndRedirect()` →
`claim_payment.approve` 필터(`ClaimPaymentListener`) — 결제 건에 PayPal 주문 ID 칸이 없으므로 캡처 전에 PayPal 주문을
조회해 custom_id(결제번호)·금액·통화를 대조한다. 클레임 결제에는 보류 상태가 없어 PENDING 캡처는 환불 후 실패다.
환급은 `claim_payment.refund`(모듈이 준 멱등키를 PayPal-Request-Id 로), 승인 뒤 모듈 거절은 `claim_payment.void_approval`.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 4개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 11개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 7개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 2개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
`g7plus-paypal.payment.before_create` 는 PayPal 주문 본문(`$payload`)을 인자로 받는 action 훅입니다 — 예외를 던지면
PayPal 호출이 일어나지 않습니다(예: 특정 국가 배송지 차단). `after_capture` 는 금액 대조 **전**이라 결과를 바꾸지 않고
관찰만 합니다. 노출 통화를 코드로 더 좁히려면 이커머스 `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency`
필터를 priority 20 이후로 잡으세요(표시와 주문 생성 검증 양쪽에 적용됩니다).
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update g7plus-paypal --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 배포는 자동이다 — `plugin.json` 의 version 을 올려 main 에 푸시하면 `.github/workflows/release.yml` 이 `v{version}` GitHub Release 를 만든다(노트는 CHANGELOG 의 해당 버전 구간). CHANGELOG 항목 제목은 `## [x.y.z] - YYYY-MM-DD` 형식을 지킨다
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:g7plus-paypal` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 빌드 없이 update 만, 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] PayPal 지원 통화·자릿수를 바꾸면 `src/Support/PaypalCurrency.php` 만 고친다 — 설정 화면 목록·검증 규칙·노출 필터가 모두 이 상수를 읽는다 (`config/settings/defaults.json` 의 기본 목록도 함께)
- [ ] PayPal 승인 호스트를 바꾸면 `PaypalCheckoutService::APPROVE_HOSTS` 와 `resources/js/handlers/requestPayment.ts` 의 `PAYPAL_APPROVE_HOSTS` 를 함께 갱신
- [ ] 캡처·웹훅 경로를 고칠 때 `SerializesPaymentCallbacks` 락 안에서 실행되는지 확인 — 복귀와 웹훅이 동시에 도착한다

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 브라우저가 보낸 금액으로 PayPal 주문 생성 | 서버가 `resolveSnapshotPaymentCharge()` 로 청구액 산정 | 체크아웃 API 는 브라우저 요청이다 — 금액을 받으면 $0.01 주문을 만들 수 있다 |
| 캡처 응답을 확인 없이 `completePayment()` | 캡처 금액·통화·custom_id 를 청구액과 대조, 어긋나면 `refundQuietly()` | `completePayment($order, ..., $pgAmount)` 의 코어 대조는 우리가 넘긴 값만 본다 — 캡처 금액을 넘기지 않으면 코어는 불일치를 모른다 |
| 복귀 쿼리의 `token` 을 그대로 캡처 | `payment_meta.paypal_order_id` 와 `hash_equals` 대조 후 캡처 | 남의 PayPal 주문 ID 를 붙여 다른 주문을 결제완료로 만들 수 있다 |
| 웹훅 본문을 디코드 후 다시 인코드해 서명 검증 | 원문 문자열을 `webhook_event` 에 그대로 끼운다 | 슬래시·유니코드 이스케이프가 달라져 정상 웹훅이 검증에 실패한다 |
| 웹훅 본문의 금액으로 결제완료 | `getCapture()` 로 다시 조회한 금액으로 대조 | 서명은 발신자를 증명할 뿐, 대조 기준은 PayPal 의 현재 기록이어야 한다 |
| `/payment/cancel` 에서 주문 실패 처리 | 로그 + 실패 화면 리다이렉트만, 기록은 close-report | 인증 없는 GET 이라 링크 하나로 남의 결제대기 주문을 취소시킬 수 있다 |
| `merchant_order_id` 에 PayPal 주문 ID 저장 | `payment_meta.paypal_order_id` | 이커머스 결제번호(NOT NULL) 컬럼이다 — 덮으면 결제번호 조회가 깨진다 |
| Secret·액세스 토큰을 로그에 기록 | debug_id·issue 코드만 기록 | 노출되면 제3자가 환불 API 를 호출할 수 있다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 9개 | `plugins/_bundled/g7plus-paypal/tests` |
| Vitest | 3개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 0개 | — |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/g7plus-paypal/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/g7plus-paypal && powershell -Command "npm run test:run -- <대상>"

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
