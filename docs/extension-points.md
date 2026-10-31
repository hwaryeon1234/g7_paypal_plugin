# 페이팔 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 4종 / 호출 지점 4곳.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `g7plus-paypal.payment.after_capture` | action | PayPal 결제 캡처 완료 후 (금액 대조 전) | `src/Services/PaypalCheckoutService.php:453` |
| `g7plus-paypal.payment.after_refund` | action | PayPal 환불 완료 후 | `src/Listeners/PaymentRefundListener.php:166` |
| `g7plus-paypal.payment.before_create` | action | PayPal 주문(Orders v2) 생성 API 호출 전 | `src/Services/PaypalCheckoutService.php:188` |
| `g7plus-paypal.payment.before_refund` | action | PayPal 환불 API 호출 전 | `src/Listeners/PaymentRefundListener.php:136` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`before_create` 만 PayPal 호출 전에 막을 수 있습니다(예외 → PayPal 주문을 만들지 않음). `after_capture` 는 금액 대조 전이므로 관찰 용도로만 쓰세요 — 결제완료를 보장하지 않습니다. 결제완료 후속 작업은 이커머스 `sirsoft-ecommerce.order.after_payment_complete` 를 잡는 것이 맞습니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.plugin_settings.update_validation_rules` | filter | `ValidateSettingsListener` | `rules` | 10 |
| `sirsoft-ecommerce.checkout.filter_payment_methods_for_currency` | filter | `RestrictPaymentMethodsForCurrencyListener` | `filter` | 20 |
| `sirsoft-ecommerce.claim_payment.approve` | filter | `ClaimPaymentListener` | `approve` | 10 |
| `sirsoft-ecommerce.claim_payment.refund` | filter | `ClaimPaymentListener` | `refund` | 10 |
| `sirsoft-ecommerce.claim_payment.void_approval` | filter | `ClaimPaymentListener` | `voidApproval` | 10 |
| `sirsoft-ecommerce.payment.get_client_config` | filter | `RegisterPgProviderListener` | `getClientConfig` | 10 |
| `sirsoft-ecommerce.payment.refund` | filter | `PaymentRefundListener` | `processRefund` | 10 |
| `sirsoft-ecommerce.payment.registered_pg_providers` | filter | `RegisterPgProviderListener` | `registerProvider` | 10 |
| `sirsoft-ecommerce.payment.supports_partial_cancel` | filter | `PaymentRefundListener` | `supportsPartialCancel` | 10 |
| `sirsoft-ecommerce.settings.filter_available_payment_methods` | filter | `RegisterPaypalPaymentMethodListener` | `injectPaypalMethod` | 20 |
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | `ProvideNecessaryStorageKeysListener` | `provide` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`filter_payment_methods_for_currency` 는 `sync: true` 입니다 — 주문 생성 검증(FormRequest)이 같은 판정을 쓰므로 큐로 빠지면 안 됩니다. `payment.refund` 리스너는 다른 PG 결제의 결과를 건드리지 않습니다(`handled` 계약).
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ClaimPaymentListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/ClaimPaymentListener.php` |
| `PaymentRefundListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/PaymentRefundListener.php` |
| `ProvideNecessaryStorageKeysListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvideNecessaryStorageKeysListener.php` |
| `RegisterPaypalPaymentMethodListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterPaypalPaymentMethodListener.php` |
| `RegisterPgProviderListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/RegisterPgProviderListener.php` |
| `RestrictPaymentMethodsForCurrencyListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RestrictPaymentMethodsForCurrencyListener.php` |
| `ValidateSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
리스너는 모두 상태를 갖지 않고, 설정은 호출 시점에 `plugin_settings()` 로 읽습니다 — 관리자가 설정을 바꾸면 다음 요청부터 반영됩니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_order_payment.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_ecommerce_order_detail` |
| `resources/extensions/checkout-payment-error.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `shop_checkout_extensions` |
| `/home/user/g7_paypal_plugin/resources/extensions/admin_order_payment.json` | `getLayoutExtensions()` 선언 |
| `/home/user/g7_paypal_plugin/resources/extensions/checkout-payment-error.json` | `getLayoutExtensions()` 선언 |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
체크아웃 오류 안내창은 `shop_checkout_extensions` 슬롯에 붙고, 관리자 주문 상세 패널은 `tab_content_area` 에 덧붙습니다. 두 조각 모두 Tailwind 기본 유틸만 써서 관리자·사용자 템플릿 어느 쪽에서도 깨지지 않게 합니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
없습니다. 웹훅은 CSRF 만 면제하고 서명 검증은 컨트롤러가 합니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
없습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
없습니다. 승인만 하고 캡처되지 않은 주문은 웹훅(CHECKOUT.ORDER.APPROVED)이 마무리하고, 끝내 결제되지 않은 주문은 이커머스의 만료 주문 정리가 처리합니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
없습니다. 결제완료 알림은 이커머스가 보냅니다.
<!-- @intent END -->
