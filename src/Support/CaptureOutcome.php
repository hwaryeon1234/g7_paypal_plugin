<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Support;

/**
 * PayPal 캡처 시도의 결과.
 *
 * 브라우저 복귀 컨트롤러는 이 결과로 리다이렉트 주소를, 웹훅 컨트롤러는 로그·응답을 정한다.
 */
final class CaptureOutcome
{
    /** 결제완료 반영 */
    public const PAID = 'paid';

    /** 같은 결제가 이미 반영돼 있었음 (새로고침·웹훅 중복) */
    public const ALREADY_RECORDED = 'already_recorded';

    /** PayPal 이 캡처를 보류(PENDING) — 웹훅 PAYMENT.CAPTURE.COMPLETED 를 기다린다 */
    public const PENDING = 'pending';

    /** 결제수단 거절 — 구매자를 PayPal 승인 화면으로 돌려보내 다른 수단을 고르게 한다 */
    public const RETRY_APPROVAL = 'retry_approval';

    /** 실패 (주문 상태는 바꾸지 않음) */
    public const FAILED = 'failed';

    /**
     * @param  string  $status  결과 상태 (위 상수)
     * @param  string|null  $errorCode  실패 코드 (FAILED 일 때)
     * @param  string|null  $redirectUrl  다시 보낼 PayPal 승인 주소 (RETRY_APPROVAL 일 때)
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $redirectUrl = null,
    ) {}

    /**
     * 성공 계열 결과를 만듭니다.
     *
     * @param  string  $status  PAID | ALREADY_RECORDED | PENDING
     * @return self 결과
     */
    public static function of(string $status): self
    {
        return new self($status);
    }

    /**
     * 실패 결과를 만듭니다.
     *
     * @param  string  $errorCode  실패 코드
     * @return self 결과
     */
    public static function failed(string $errorCode): self
    {
        return new self(self::FAILED, $errorCode);
    }

    /**
     * 승인 재시도 결과를 만듭니다.
     *
     * @param  string  $approveUrl  PayPal 승인 주소
     * @return self 결과
     */
    public static function retryApproval(string $approveUrl): self
    {
        return new self(self::RETRY_APPROVAL, null, $approveUrl);
    }

    /**
     * 구매자를 성공 화면으로 보내야 하는 결과인지 반환합니다.
     *
     * @return bool 성공 화면 여부
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, [self::PAID, self::ALREADY_RECORDED, self::PENDING], true);
    }
}
