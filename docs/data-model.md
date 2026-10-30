# 페이팔 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 모델이 없습니다._
<!-- @generated:models END -->

<!-- @intent START -->
이 플러그인은 모델을 소유하지 않습니다. 이커머스 `Order`·`OrderPayment` 를 이커머스 서비스(`OrderProcessingService`)로 갱신합니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_소유 테이블이 없습니다._
<!-- @generated:tables END -->

<!-- @intent START -->
소유 테이블이 없습니다. PayPal 정보는 이커머스 결제행에 둡니다.

| 위치 | 값 |
|---|---|
| `transaction_id` | PayPal 캡처 ID (환불 대상) |
| `card_name` / `card_number_masked` / `embedded_pg_provider` | 결제수단 요약 (PayPal · PayPal (VISA) **** 1234 · funding) |
| `payment_meta.paypal_order_id` | PayPal 주문 ID — 복귀 token 대조 기준 |
| `payment_meta.paypal_charge` | PayPal 주문을 만들 때의 청구액 {currency, minor, decimals, value} — 캡처 전 금액 변동 감지 |
| `payment_meta.paypal_approve_url` | 승인 주소 — 카드 거절 시 재승인 |
| `payment_meta.paypal_capture_id` / `paypal_capture_status` | 캡처 결과 (PENDING 이면 웹훅 대기) |
| `payment_meta.paypal_payer` / `paypal_source` / `paypal_seller_breakdown` | 구매자·결제수단·수수료 |
| `payment_meta.paypal_events` | 환불·지급거절 웹훅 기록 (최근 20건) |

`merchant_order_id` 는 이커머스 결제번호(NOT NULL)라 쓰지 않습니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_마이그레이션이 없습니다._
<!-- @generated:migrations END -->

<!-- @intent START -->
마이그레이션이 없습니다. 설치·제거가 데이터베이스를 바꾸지 않습니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
자체 Enum 이 없습니다. 결제 상태는 이커머스 `PaymentStatusEnum`(`in_progress` → `paid`)을 씁니다. 캡처 결과 분류는 `Support\CaptureOutcome` 상수입니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Repository 가 없습니다._
<!-- @generated:repositories END -->

<!-- @intent START -->
자체 Repository 가 없습니다. 주문 조회는 `OrderProcessingService::findByOrderNumber()` 를 씁니다.
<!-- @intent END -->
