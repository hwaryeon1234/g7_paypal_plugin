<?php

declare(strict_types=1);

namespace Plugins\Hwaryeon\Paypal\Exceptions;

use RuntimeException;

/**
 * PayPal REST API 호출 실패.
 *
 * PayPal 오류 응답의 `name`(예: UNPROCESSABLE_ENTITY)과 `details[].issue`(예: INSTRUMENT_DECLINED)를
 * 함께 실어, 호출자가 「다른 결제수단으로 다시 승인」 같은 분기를 issue 로 판단할 수 있게 한다.
 */
class PaypalApiException extends RuntimeException
{
    /**
     * @param  string  $message  사람이 읽을 메시지
     * @param  int  $httpStatus  HTTP 상태 코드 (네트워크 오류는 0)
     * @param  string|null  $errorName  PayPal 오류 이름
     * @param  list<string>  $issues  PayPal 오류 상세 issue 코드 목록
     * @param  string|null  $debugId  PayPal debug_id (PayPal 지원팀 문의용)
     * @param  list<string>  $fields  PayPal 오류 상세의 문제 필드 경로 목록
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $errorName = null,
        private readonly array $issues = [],
        private readonly ?string $debugId = null,
        private readonly array $fields = [],
    ) {
        parent::__construct($message, $httpStatus);
    }

    /**
     * HTTP 상태 코드를 반환합니다.
     *
     * @return int 상태 코드
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * PayPal 오류 이름을 반환합니다.
     *
     * @return string|null 오류 이름
     */
    public function getErrorName(): ?string
    {
        return $this->errorName;
    }

    /**
     * PayPal issue 코드 목록을 반환합니다.
     *
     * @return list<string> issue 코드
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * 특정 issue 가 포함됐는지 판정합니다.
     *
     * @param  string  $issue  issue 코드
     * @return bool 포함 여부
     */
    public function hasIssue(string $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }

    /**
     * 문제 필드의 JSON 경로 목록을 반환합니다.
     *
     * @return list<string> 필드 경로
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * PayPal debug_id 를 반환합니다.
     *
     * @return string|null debug_id
     */
    public function getDebugId(): ?string
    {
        return $this->debugId;
    }

    /**
     * 사용자에게 보일 수 있는 오류 코드를 반환합니다.
     *
     * @return string 첫 issue, 없으면 오류 이름, 그것도 없으면 PAYPAL_API_ERROR
     */
    public function publicCode(): string
    {
        return $this->issues[0] ?? $this->errorName ?? 'PAYPAL_API_ERROR';
    }
}
