# Orders API 레퍼런스

> **소유**: plugin `g7plus-paypal` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Orders 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/g7plus-paypal/admin/orders/{orderNumber}/payment
<!-- @generated:start:api.plugins.g7plus-paypal.admin.orders.payment -->
- **라우트명**: `api.plugins.g7plus-paypal.admin.orders.payment`
- **컨트롤러**: `Plugins\G7plus\Paypal\Controllers\AdminOrderPaymentController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.orders.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| orderNumber | path | string | 예 | — | 대상 order number의 식별자 |

**요청 예시**

```http
GET /api/plugins/g7plus-paypal/admin/orders/{orderNumber}/payment HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: no-token — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: no-token — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.orders.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 주문 상세의 「PayPal 거래 정보」 패널이 읽는다. 이커머스 주문 API 는 결제행 `payment_meta` 를 내보내지 않으므로, PayPal 수수료(`fee`)·정산 예정액(`net`)·판매자 보호(`seller_protection`)·환불/차지백 웹훅 기록(`events`)·PayPal 대시보드 거래 주소(`dashboard_url`)를 이 API 가 정리해 준다. PayPal 결제가 아닌 주문은 `data.is_paypal=false` 만 돌려준다. 권한: `sirsoft-ecommerce.orders.read`.

