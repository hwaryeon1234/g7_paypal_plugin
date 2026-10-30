# Payment API 레퍼런스

> **소유**: plugin `hwaryeon-paypal` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Payment 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/hwaryeon-paypal/payment/checkout
<!-- @generated:start:api.plugins.hwaryeon-paypal.payment.checkout -->
- **라우트명**: `api.plugins.hwaryeon-paypal.payment.checkout`
- **컨트롤러**: `Plugins\Hwaryeon\Paypal\Controllers\CheckoutController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/hwaryeon-paypal/payment/checkout HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: no-token — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: no-token — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** PayPal 결제 시작. 요청 본문 `{"orderId": "주문번호"}` (필수, 최대 40자) — 금액은 받지 않는다.
주문 소유자(회원 Bearer / 비회원 `X-Guest-Order-Token`)만 호출할 수 있고, 아니면 주문이 없는 것과 같은 404 를 준다.
성공 `data`: `{paypal_order_id, approve_url}` — 브라우저는 `approve_url`(PayPal 도메인 https) 로 이동한다.
실패 `errors.code`: `currency_not_allowed`·`invalid_amount`·`order_not_payable`·`payment_method_mismatch`(422), `not_configured`(503), `create_failed`(502). IP+주문 기준 분당 10회 제한(429).


### POST /api/plugins/hwaryeon-paypal/payment/close-report
<!-- @generated:start:api.plugins.hwaryeon-paypal.payment.close-report -->
- **라우트명**: `api.plugins.hwaryeon-paypal.payment.close-report`
- **컨트롤러**: `Plugins\Hwaryeon\Paypal\Controllers\PaymentCloseReportController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/hwaryeon-paypal/payment/close-report HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: no-token — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: no-token — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 결제 취소·실패 보고. 본문 `{orderId, amount(결제 통화 최소 화폐단위 정수), code?, reason?}`.
주문 소유자 확인 + 금액 대조 후 주문을 결제 실패로 기록한다. 이미 결제됐거나 캡처가 진행된 주문은 `status: ignored` 로 무시한다. 프론트 `paymentCloseReport.ts` 가 PayPal 취소 복귀 화면에서 자동으로 보낸다.


### GET /plugins/hwaryeon-paypal/payment/cancel
<!-- @generated:start:web.plugins.hwaryeon-paypal.payment.cancel -->
- **라우트명**: `web.plugins.hwaryeon-paypal.payment.cancel`
- **컨트롤러**: `Plugins\Hwaryeon\Paypal\Controllers\PaymentCallbackController@cancel`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/hwaryeon-paypal/payment/cancel HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: no-token — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: no-token — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** PayPal 화면에서 취소 후 브라우저 복귀 (`?order=&token=`). 주문 상태를 바꾸지 않고 실패 화면(`error=USER_CANCEL`)으로 리다이렉트한다.


### GET /plugins/hwaryeon-paypal/payment/return
<!-- @generated:start:web.plugins.hwaryeon-paypal.payment.return -->
- **라우트명**: `web.plugins.hwaryeon-paypal.payment.return`
- **컨트롤러**: `Plugins\Hwaryeon\Paypal\Controllers\PaymentCallbackController@return`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/hwaryeon-paypal/payment/return HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: no-token — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: no-token — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** PayPal 승인 후 브라우저 복귀 (`?order=주문번호&token=PayPal주문ID&PayerID=`). token 을 결제행에 저장된 PayPal 주문 ID 와 대조하고 서버가 캡처한다.
성공·보류는 결제 성공 URL, 카드 거절은 PayPal 승인 화면, 그 밖은 실패 URL(`error=코드&message=&orderId=`)로 리다이렉트한다.


