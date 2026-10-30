# 페이팔 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
설정 화면의 통화 칩은 서버(`/admin/meta`)가 준 목록으로 그립니다 — 통화 목록을 레이아웃에 하드코딩하지 않습니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 2개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `hwaryeon-paypal.requestPayment` |
| `copyToClipboard` | `hwaryeon-paypal.copyToClipboard` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`requestPayment` 는 서버에 주문번호만 보내고, 받은 승인 주소가 PayPal 도메인 https 일 때만 이동합니다(fail-closed). 실패는 `paypal_payment_error_modal` 로 안내하고 `isSubmittingOrder` 를 풉니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__HwaryeonPaypal` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
부팅 시 `reportPaymentFailureOnReturn()` 이 한 번 돕니다 — PayPal 에서 취소하고 돌아온 실패 화면이면 sessionStorage 의 주문 정보로 close-report 를 보냅니다. `window.__HwaryeonPaypal.initPlugin` 은 로케일 전환 후 핸들러 재등록용입니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
외부 스크립트를 싣지 않습니다(`trusted_script_hosts` 없음). CSS 는 접두 클래스(`paypal-*`)만 정의합니다.
<!-- @intent END -->
