<?php

declare(strict_types=1);

namespace Plugins\G7plus\Paypal\Concerns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 같은 주문의 캡처를 한 번에 하나씩 처리하도록 직렬화하는 캐시 락.
 *
 * 브라우저 복귀(return)와 웹훅(CHECKOUT.ORDER.APPROVED·PAYMENT.CAPTURE.COMPLETED)은 같은 주문에 거의
 * 동시에 도착할 수 있다. 둘 다 「결제 대기」를 보고 각자 결제완료를 처리하면 마일리지·알림이 두 번 발화한다.
 * 키는 `g7plus-paypal:callback:{sha1(주문번호)}` 하나로, 복귀와 웹훅이 같은 락을 잡는다.
 * 번들 PG 플러그인들과 같은 규약(TTL 30초, 대기 10초)이다.
 */
trait SerializesPaymentCallbacks
{
    /**
     * 주문 단위 콜백 락을 획득합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return Lock 획득한 락
     *
     * @throws LockTimeoutException 대기 시간 안에 락을 얻지 못했을 때
     */
    protected function acquireOrderCallbackLock(string $orderNumber): Lock
    {
        $key = 'g7plus-paypal:callback:'.sha1(trim($orderNumber));
        $lock = Cache::lock($key, 30);

        try {
            $lock->block(10);
        } catch (LockTimeoutException $e) {
            Log::warning('PayPal: callback lock timeout', ['order_number' => $orderNumber]);

            throw $e;
        }

        return $lock;
    }

    /**
     * 획득한 콜백 락을 해제합니다. (null 이면 아무것도 하지 않음)
     *
     * @param  mixed  $lock  획득한 락
     * @return void 반환값 없음
     */
    protected function releaseOrderCallbackLock(mixed $lock): void
    {
        if (is_object($lock) && method_exists($lock, 'release')) {
            $lock->release();
        }
    }
}
