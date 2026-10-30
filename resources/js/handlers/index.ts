import { requestPaymentHandler } from './requestPayment';
import { copyToClipboardHandler } from './copyToClipboard';

/**
 * PayPal 플러그인 핸들러 맵
 *
 * ActionDispatcher 에 등록될 핸들러 목록입니다.
 * 네임스페이스 접두사(g7plus-paypal.)는 index.ts 에서 자동으로 붙습니다.
 */
export const handlerMap: Record<string, (...args: any[]) => unknown> = {
    requestPayment: requestPaymentHandler,
    copyToClipboard: copyToClipboardHandler,
} as const;
