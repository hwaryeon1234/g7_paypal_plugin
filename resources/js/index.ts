/**
 * PayPal 플러그인 엔트리
 *
 * ActionDispatcher 에 핸들러를 등록해 레이아웃에서 "hwaryeon-paypal.requestPayment" 로 부를 수 있게 합니다.
 */

import '../css/main.css';
import { handlerMap } from './handlers';
import { reportPaymentFailureOnReturn } from './paymentCloseReport';

const PLUGIN_IDENTIFIER = 'hwaryeon-paypal';

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

/**
 * ActionDispatcher 에 핸들러를 등록합니다.
 *
 * @returns 등록된 핸들러 수
 */
export function registerHandlers(): number {
    const g7Core = (window as any).G7Core;
    const dispatcher = typeof g7Core?.getActionDispatcher === 'function' ? g7Core.getActionDispatcher() : undefined;

    if (!dispatcher || typeof dispatcher.registerHandler !== 'function') {
        return 0;
    }

    let count = 0;
    for (const [name, handler] of Object.entries(handlerMap)) {
        dispatcher.registerHandler(`${PLUGIN_IDENTIFIER}.${name}`, handler, {
            category: 'plugin',
            source: PLUGIN_IDENTIFIER,
        });
        count++;
    }

    return count;
}

/**
 * 플러그인 초기화 — 로케일 전환 후 코어가 다시 부를 수 있도록 window 전역에 노출한다.
 *
 * ActionDispatcher 가 아직 준비되지 않았으면 100ms 간격으로 최대 5초간 재시도한다.
 */
export function initPlugin(): void {
    // PayPal 에서 취소·실패로 돌아온 화면이면 서버에 보고한다(주문 소유자 증명 포함).
    void reportPaymentFailureOnReturn();

    const doInit = () => {
        const count = registerHandlers();

        if (count > 0) {
            logger.info(`${count} handler(s) registered`);

            return;
        }

        let retries = 0;
        const interval = setInterval(() => {
            retries++;
            const result = registerHandlers();

            if (result > 0) {
                clearInterval(interval);
                logger.info(`${result} handler(s) registered (after ${retries} retries)`);

                return;
            }

            if (retries >= 50) {
                clearInterval(interval);
                logger.warn('ActionDispatcher not available after timeout');
            }
        }, 100);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', doInit);
    } else {
        doInit();
    }
}

initPlugin();

(window as any).__HwaryeonPaypal = {
    identifier: PLUGIN_IDENTIFIER,
    handlers: Object.keys(handlerMap),
    initPlugin,
};
