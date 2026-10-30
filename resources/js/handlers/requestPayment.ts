/**
 * PayPal 결제 시작 핸들러
 *
 * 체크아웃 레이아웃이 주문 생성 API 성공 후 호출한다 (이커머스가 응답의 pg_payment_handler 로 이 이름을 내린다):
 *   handler: "hwaryeon-paypal.requestPayment"
 *   params: { pgPaymentData: response.data.pg_payment_data }
 *
 * 순서:
 *   1. POST /api/plugins/hwaryeon-paypal/payment/checkout — 서버가 주문 청구액으로 PayPal 주문을 만들고 승인 주소를 준다.
 *      금액은 보내지 않는다. 서버가 주문 스냅샷으로 정한다.
 *   2. 취소 보고에 쓸 주문 정보를 sessionStorage 에 남긴다.
 *   3. 승인 주소(PayPal 도메인)로 이동한다. 승인 후 PayPal 이 /payment/return 으로 돌려보내면 서버가 캡처한다.
 */

/* eslint-disable @typescript-eslint/no-explicit-any */

import { buildGuestOwnershipHeaders } from '../guestOrderToken';
import { rememberPendingClose } from '../paymentCloseReport';

const PLUGIN_IDENTIFIER = 'hwaryeon-paypal';

export const CHECKOUT_PATH = '/api/plugins/hwaryeon-paypal/payment/checkout';

/** 승인 주소로 허용하는 호스트 (서버 PaypalCheckoutService::APPROVE_HOSTS 미러) */
export const PAYPAL_APPROVE_HOSTS: readonly string[] = [
    'www.paypal.com',
    'www.sandbox.paypal.com',
    'paypal.com',
    'sandbox.paypal.com',
];

/** 체크아웃 화면에 끼워 넣는 안내창 id (resources/extensions/checkout-payment-error) */
export const ERROR_MODAL_ID = 'paypal_payment_error_modal';

interface PgPaymentData {
    order_number: string;
    order_name?: string;
    /** 결제 통화 최소 화폐단위 정수 (USD 12.50 → 1250) — 취소 보고 대조용 */
    amount: number;
    currency?: string;
}

interface RequestPaymentParams {
    pgPaymentData?: PgPaymentData;
}

/**
 * 번역 문자열을 얻습니다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진 부재 시 문구
 * @returns 번역된 문자열
 */
function t(key: string, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;

    if (typeof translate !== 'function') {
        return fallback;
    }

    const full = `${PLUGIN_IDENTIFIER}.${key}`;
    const result = translate(full);

    return typeof result === 'string' && result !== full ? result : fallback;
}

/**
 * 승인 주소가 PayPal 도메인의 https 주소인지 확인합니다.
 *
 * 서버도 같은 확인을 하지만, 응답이 어떤 경로로든 다른 주소를 지시하면 이동하지 않는다(fail-closed).
 *
 * @param url 승인 주소
 * @returns 신뢰 가능하면 true
 */
export function isTrustedApproveUrl(url: unknown): url is string {
    if (typeof url !== 'string') {
        return false;
    }

    try {
        const parsed = new URL(url);

        return parsed.protocol === 'https:' && PAYPAL_APPROVE_HOSTS.includes(parsed.hostname.toLowerCase());
    } catch {
        return false;
    }
}

/**
 * API 오류에서 사용자에게 보일 메시지를 꺼냅니다.
 *
 * @param error G7Core.api 가 던진 오류 또는 실패 응답
 * @returns 메시지
 */
export function extractErrorMessage(error: any): string {
    const body = error?.response?.data ?? error?.data;
    const message = body?.message ?? error?.message;
    const code = body?.errors?.code;

    const text = typeof message === 'string' && message !== ''
        ? message
        : t('payment.error.start_failed', 'Could not start PayPal checkout. Please try again.');

    // 운영자가 원인을 바로 알 수 있게 PayPal·서버 오류 코드를 덧붙인다 (예: CITY_REQUIRED).
    return typeof code === 'string' && code !== '' ? `${text} (${code})` : text;
}

/**
 * 오류 안내창을 띄우고 제출 상태를 풉니다.
 *
 * @param message 안내 문구
 */
function showError(message: string): void {
    const G7Core = (window as any).G7Core;

    G7Core?.state?.setLocal?.({ paypalErrorMessage: message, isSubmittingOrder: false });
    G7Core?.modal?.open?.(ERROR_MODAL_ID);
}

/**
 * 브라우저를 다른 주소로 보냅니다. (테스트에서 대체할 수 있게 분리)
 */
export const browserNavigation = {
    go(url: string): void {
        window.location.assign(url);
    },
};

/**
 * PayPal 결제를 시작합니다.
 *
 * ActionDispatcher 는 커스텀 핸들러를 (action, context) 로 부른다 — params 는 action.params 에 있다.
 *
 * @param action 액션 정의
 * @param _context 액션 컨텍스트
 */
export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const params = (action?.params || {}) as RequestPaymentParams;
    const pgPaymentData = params.pgPaymentData;

    if (!pgPaymentData?.order_number) {
        console.error(`[${PLUGIN_IDENTIFIER}] pgPaymentData.order_number is required`);
        showError(t('payment.error.start_failed', 'Could not start PayPal checkout. Please try again.'));

        return;
    }

    const G7Core = (window as any).G7Core;

    if (typeof G7Core?.api?.post !== 'function') {
        console.error(`[${PLUGIN_IDENTIFIER}] G7Core.api is not available`);
        showError(t('payment.error.start_failed', 'Could not start PayPal checkout. Please try again.'));

        return;
    }

    try {
        const ownershipHeaders = buildGuestOwnershipHeaders();
        const body = { orderId: pgPaymentData.order_number };
        const response = Object.keys(ownershipHeaders).length > 0
            ? await G7Core.api.post(CHECKOUT_PATH, body, { headers: ownershipHeaders })
            : await G7Core.api.post(CHECKOUT_PATH, body);

        if (response?.success === false) {
            showError(extractErrorMessage({ data: response }));

            return;
        }

        const approveUrl = response?.data?.approve_url;

        if (!isTrustedApproveUrl(approveUrl)) {
            console.error(`[${PLUGIN_IDENTIFIER}] untrusted or missing approval URL`, approveUrl);
            showError(t('payment.error.untrusted_url', 'The PayPal address is not valid. The payment was not started.'));

            return;
        }

        rememberPendingClose({ orderId: pgPaymentData.order_number, amount: pgPaymentData.amount });

        browserNavigation.go(approveUrl);
    } catch (error: any) {
        console.error(`[${PLUGIN_IDENTIFIER}] requestPayment error`, error);
        showError(extractErrorMessage(error));
    }
}
