# Changelog

이 프로젝트의 모든 주요 변경 사항은 이 파일에 기록됩니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/) 를 따르며, [Semantic Versioning](https://semver.org/lang/ko/) 을 준수합니다.

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
