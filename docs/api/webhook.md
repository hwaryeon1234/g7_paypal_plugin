# Webhook API 레퍼런스

> **소유**: plugin `hwaryeon-paypal` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Webhook 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /plugins/hwaryeon-paypal/webhook
<!-- @generated:start:web.plugins.hwaryeon-paypal.webhook.receive -->
- **라우트명**: `web.plugins.hwaryeon-paypal.webhook.receive`
- **컨트롤러**: `Plugins\Hwaryeon\Paypal\Controllers\WebhookController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /plugins/hwaryeon-paypal/webhook HTTP/1.1
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

**설명** PayPal 웹훅 수신. `PAYPAL-AUTH-ALGO`·`PAYPAL-CERT-URL`·`PAYPAL-TRANSMISSION-ID`·`PAYPAL-TRANSMISSION-SIG`·`PAYPAL-TRANSMISSION-TIME` 헤더와 원문 본문을 PayPal 서명 검증 API 로 확인한다(설정의 Webhook ID 필요).
서명 실패는 400, 그 밖(처리·무관·중복)은 200 `{status}` — PayPal 재전송 폭주를 막는다. CSRF 면제.


