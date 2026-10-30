/**
 * PayPal 결제 취소·실패 보고
 *
 * PayPal 화면에서 취소하고 돌아오는 브라우저 복귀(`/payment/cancel`)는 인증도 서명도 없어 주문 상태를 바꾸지
 * 않는다. 정당한 취소·실패를 기록하는 것은 주문 소유자(회원 로그인 / 비회원 조회 토큰)를 확인하는 close-report
 * 엔드포인트뿐이며, 이 모듈이 그 호출을 담당한다.
 *
 * PayPal 승인 화면은 전체 페이지 이동으로 열리고 돌아오므로, 이동 직전에 주문 정보를 sessionStorage 에 남겨
 * 두었다가 실패 화면으로 돌아왔을 때 꺼내 쓴다. sessionStorage 는 같은 탭에서 외부 도메인을 다녀와도 유지된다.
 */

import { buildGuestOwnershipHeaders, waitForGuestOrderTokenContract } from './guestOrderToken';

const PLUGIN_IDENTIFIER = 'g7plus-paypal';

export const STORAGE_KEY = 'g7:g7plus-paypal:pendingClose';

export const CLOSE_REPORT_PATH = '/api/plugins/g7plus-paypal/payment/close-report';

export interface PaymentCloseReportContext {
    orderId: string;
    amount: number;
}

/**
 * sessionStorage 접근은 브라우저 설정(사이트 데이터 차단·시크릿 모드)에 따라 예외를 던진다.
 * 보고는 편의 장치이므로 실패해도 결제 흐름을 막지 않는다.
 *
 * @returns sessionStorage 또는 null
 */
function safeSessionStorage(): Storage | null {
    try {
        return window.sessionStorage ?? null;
    } catch {
        return null;
    }
}

/**
 * PayPal 로 이동하기 직전에 주문 정보를 저장합니다.
 *
 * @param context 보고에 필요한 주문 정보
 */
export function rememberPendingClose(context: PaymentCloseReportContext): void {
    try {
        safeSessionStorage()?.setItem(STORAGE_KEY, JSON.stringify(context));
    } catch {
        // 저장 실패는 무시 — 이커머스의 만료 주문 자동 정리가 최종 안전망이다.
    }
}

/**
 * 저장해 둔 주문 정보를 지웁니다.
 */
export function forgetPendingClose(): void {
    try {
        safeSessionStorage()?.removeItem(STORAGE_KEY);
    } catch {
        // 무시
    }
}

/**
 * 저장해 둔 주문 정보를 읽습니다.
 *
 * @returns 저장된 정보, 없거나 형식이 깨졌으면 null
 */
function readPendingClose(): PaymentCloseReportContext | null {
    try {
        const raw = safeSessionStorage()?.getItem(STORAGE_KEY);
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as PaymentCloseReportContext;

        return parsed && typeof parsed.orderId === 'string' && parsed.orderId !== '' ? parsed : null;
    } catch {
        return null;
    }
}

/**
 * close-report 엔드포인트에 보고합니다.
 *
 * @param context 주문 정보
 * @param code 실패 코드
 * @param reason 사람이 읽을 실패 사유
 */
async function postCloseReport(context: PaymentCloseReportContext, code: string, reason: string): Promise<void> {
    const payload = {
        orderId: context.orderId,
        amount: Number(context.amount),
        code: code.slice(0, 60),
        reason: reason.trim().slice(0, 160),
    };

    // 비회원 조회 토큰은 화면 초기화가 `_global.guestOrderToken` 에 올린다 — 그 전일 수 있으므로 먼저 기다린다.
    await waitForGuestOrderTokenContract();

    const apiClient = (window as any).G7Core?.api;
    if (typeof apiClient?.post !== 'function') {
        console.warn(`[${PLUGIN_IDENTIFIER}] G7Core.api is not available — payment cancellation not reported`);

        return;
    }

    const ownershipHeaders = buildGuestOwnershipHeaders();

    if (Object.keys(ownershipHeaders).length > 0) {
        await apiClient.post(CLOSE_REPORT_PATH, payload, { headers: ownershipHeaders });
    } else {
        await apiClient.post(CLOSE_REPORT_PATH, payload);
    }
}

/**
 * PayPal 에서 실패·취소로 돌아왔으면 저장해 둔 정보로 서버에 보고합니다.
 *
 * 성공으로 돌아온 경우에는 보고하지 않고 저장분만 지운다 — 성공 확정은 서버의 캡처가 담당한다.
 * 플러그인 부팅 시 1회 호출한다.
 */
export async function reportPaymentFailureOnReturn(): Promise<void> {
    const pending = readPendingClose();
    if (!pending) {
        return;
    }

    let params: URLSearchParams;
    try {
        params = new URLSearchParams(window.location.search);
    } catch {
        return;
    }

    const orderIdInUrl = params.get('orderId') ?? '';

    // 저장분과 화면의 주문번호가 다르면 이번 이동과 무관한 잔여물이다.
    if (orderIdInUrl !== '' && orderIdInUrl !== pending.orderId) {
        return;
    }

    if (/\/(complete|success)(\/|$|\?)/.test(window.location.pathname)) {
        forgetPendingClose();

        return;
    }

    const code = params.get('error') ?? '';
    const message = params.get('message') ?? '';

    // 실패 표시가 전혀 없으면 판단하지 않는다.
    if (code === '' && orderIdInUrl === '') {
        return;
    }

    // 결제 처리 중(callback_locked)은 실패가 아니다 — 서버가 아직 캡처 중일 수 있다.
    if (code === 'callback_locked') {
        forgetPendingClose();

        return;
    }

    forgetPendingClose();

    try {
        await postCloseReport(pending, code, message !== '' ? message : code);
    } catch (error) {
        console.warn(`[${PLUGIN_IDENTIFIER}] failed to report payment cancellation`, error);
    }
}
