# Changelog

이 프로젝트의 모든 주요 변경 사항은 이 파일에 기록됩니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/) 를 따르며, [Semantic Versioning](https://semver.org/lang/ko/) 을 준수합니다.

## [2.2.1] - 2026-10-31

### Changed

- 일본어 언어팩을 전용 저장소 [hwaryeon1234/g7_paypal_plugin_ja](https://github.com/hwaryeon1234/g7_paypal_plugin_ja) 로 옮겼습니다 — 관리자 > 언어팩 > 설치 > GitHub 탭에 저장소 주소를 넣어 설치합니다(2.2.0 의 zip 내려받기 방식은 더 쓰지 않습니다)
- 두 저장소의 문구 키가 맞는지 GitHub Actions(`ja-pack-check`)로 푸시마다 대조합니다

## [2.2.0] - 2026-10-31

### Added

- 일본어 언어팩(`g7plus-plugin-g7plus-paypal-ja`) — 저장소 `lang-pack/` 에 원본을 두고, Release 마다 zip 으로 자동 첨부합니다. 이 주소의 zip 을 내려받아 관리자 > 언어팩 > 설치 > ZIP 업로드 탭에 올리거나, 서버에서 `php artisan language-pack:install https://github.com/hwaryeon1234/g7_paypal_plugin/releases/latest/download/g7plus-plugin-g7plus-paypal-ja.zip --source=url` 로 설치합니다 (`https://github.com/hwaryeon1234/g7_paypal_plugin/releases/latest/download/g7plus-plugin-g7plus-paypal-ja.zip`)

### Changed

- 결제수단 이름·설명이 ko·en 외의 언어(일본어 등)도 설치된 언어팩에서 채워지도록 변경
- 개발자(벤더) 표시를 `Hwaryeon` 으로 변경 — 플러그인 식별자(`g7plus-paypal`)는 그대로입니다

## [2.1.0] - 2026-10-31

### Added

- 클레임(교환·반품) 추가결제를 PayPal 로 받기 — 이커머스 클레임 정책의 추가결제 수단에 `paypal` 을 넣으면 신청서 화면에서 PayPal 로 결제합니다. 캡처 전에 PayPal 주문의 결제번호·금액·통화를 대조하고, 어긋나거나 보류(PENDING)되면 돌려준 뒤 실패로 끝냅니다
- 클레임 추가결제 환급(신청 철회·차액 정산)과 승인 뒤 거절 시 보상 환불
- 설정 화면 「연결 테스트」 — 저장된 키로 PayPal 인증을 실제로 해 보고, Webhook ID 로 등록 주소·구독 이벤트가 이 사이트와 맞는지 점검합니다

## [2.0.1] - 2026-10-30

### Fixed

- 환불 실패 사유가 `PayPal 환불에 실패했습니다. (NOT_CONFIGURED)` 처럼 코드로만 보이던 문제 — 키 미설정·인증 실패·연결 실패는 원인과 조치 방법을 그대로 안내

## [2.0.0] - 2026-10-30

### Changed

- 플러그인 식별자를 `hwaryeon-paypal` 에서 `g7plus-paypal` 로 변경 (네임스페이스 `Plugins\G7plus\Paypal`, 벤더 `g7plus`) — 그누보드7 은 식별자가 다르면 다른 플러그인으로 인식하므로, 1.x 를 설치한 사이트는 기존 플러그인을 삭제하고 새로 설치한 뒤 PayPal 키·허용 통화를 다시 입력해야 합니다. 결제수단 id(`paypal`)와 PG id(`paypal`)는 그대로라 기존 PayPal 주문의 환불은 새 플러그인이 처리합니다
- 발행 훅 이름 접두사 변경: `hwaryeon-paypal.payment.*` → `g7plus-paypal.payment.*`
- API·콜백 주소 변경: `/plugins/g7plus-paypal/...`, `/api/plugins/g7plus-paypal/...` — PayPal 개발자 대시보드의 웹훅 URL 을 새 주소로 바꿔야 합니다

## [1.0.2] - 2026-10-30

### Added

- `plugin.json` 에 `github_url`·`github_changelog_url` — 관리자 화면 GitHub 설치·업데이트 감지(GitHub Release 기준) 지원

### Changed

- README 설치 안내를 관리자 화면 GitHub 설치·업데이트 기준으로 변경

## [1.0.1] - 2026-10-30

### Fixed

- 도시(city)가 없는 배송지(국내식 주소 입력)에서 PayPal 주문 생성이 `CITY_REQUIRED` 로 실패하던 문제 — 도시가 없으면 배송지를 싣지 않는다
- PayPal 이 배송지 필드를 거절하면(오류 field 경로 `/shipping` 또는 주소 관련 issue) 배송지 없이 재시도하도록 판정 확대

### Changed

- 체크아웃 결제 오류 안내창에 서버·PayPal 오류 코드를 함께 표시 (예: `(CITY_REQUIRED)`)

## [1.0.0] - 2026-10-30

### Added

- PayPal Checkout(Orders v2) 연동 — 서버가 주문 청구액으로 PayPal 주문을 만들고, 승인 후 복귀 시 서버가 캡처
- 이커머스 결제수단 `paypal` 등록 (PG 고정, 기본 비활성)
- 결제 통화별 노출 제한 — 원화(KRW) 등 PayPal 미지원 통화 제외, 관리자가 허용 통화 선택 (기본: PayPal 지원 24개 통화)
- 캡처 금액·통화·주문번호 대조, 불일치 시 자동 환불
- 웹훅 서명 검증 + `CHECKOUT.ORDER.APPROVED` / `PAYMENT.CAPTURE.COMPLETED` / `DENIED` / `REFUNDED` / `REVERSED` 처리
- 주문 취소·반품 시 PayPal 환불 (부분환불, 180일 이내)
- 카드 거절(`INSTRUMENT_DECLINED`) 시 PayPal 승인 화면으로 재안내, 보류(PENDING) 캡처 지원
- 관리자 설정 화면, 체크아웃 오류 안내창, 관리자 주문 상세 PayPal 거래 정보 패널
- 발행 훅 `hwaryeon-paypal.payment.before_create` · `after_capture` · `before_refund` · `after_refund`
