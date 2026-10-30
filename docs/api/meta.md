# Meta API 레퍼런스

> **소유**: plugin `g7plus-paypal` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Meta 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/g7plus-paypal/admin/meta
<!-- @generated:start:api.plugins.g7plus-paypal.admin.meta -->
- **라우트명**: `api.plugins.g7plus-paypal.admin.meta`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/g7plus-paypal/admin/meta HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 설정 화면의 안내 정보. `webhook_url`(PayPal 앱 Webhooks 에 등록할 전체 URL, 사이트 주소 기준), `webhook_events`(구독할 이벤트 5종), `currencies`(PayPal 지원 통화 `{code, decimals, in_country_only}`), `allowed_currencies`(현재 허용 통화)를 돌려준다.


