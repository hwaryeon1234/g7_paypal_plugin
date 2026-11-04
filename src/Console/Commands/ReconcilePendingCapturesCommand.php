<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Console\Commands;

use Illuminate\Console\Command;
use Plugins\G7plus\Paypal\Services\PaypalPendingCaptureService;

/**
 * 보류(PENDING) 캡처를 PayPal 에 다시 조회해 정리합니다.
 *
 * 보통은 웹훅(PAYMENT.CAPTURE.COMPLETED·DENIED)이 정리하지만, 웹훅을 등록하지 않았거나 놓친 경우의 안전망이다.
 */
class ReconcilePendingCapturesCommand extends Command
{
    protected $signature = 'g7plus-paypal:reconcile-pending {--limit=50 : 한 번에 확인할 최대 건수}';

    protected $description = 'PayPal 보류 결제를 다시 조회해 결제완료·실패로 정리합니다';

    /**
     * 명령을 실행합니다.
     *
     * @param  PaypalPendingCaptureService  $service  보류 캡처 정리
     * @return int 종료 코드
     */
    public function handle(PaypalPendingCaptureService $service): int
    {
        $summary = $service->reconcile(max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'checked=%d completed=%d failed=%d still_pending=%d errors=%d',
            $summary['checked'],
            $summary['completed'],
            $summary['failed'],
            $summary['pending'],
            $summary['errors'],
        ));

        return self::SUCCESS;
    }
}
