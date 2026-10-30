import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CLOSE_REPORT_PATH, reportPaymentFailureOnReturn, STORAGE_KEY } from '../paymentCloseReport';

function setLocation(path: string): void {
    window.history.replaceState({}, '', path);
}

function installG7Core(post: ReturnType<typeof vi.fn>, state: Record<string, unknown> = { guestOrderToken: null }) {
    (window as any).G7Core = { api: { post }, state: { get: () => state } };
}

describe('reportPaymentFailureOnReturn', () => {
    beforeEach(() => {
        sessionStorage.clear();
    });

    afterEach(() => {
        delete (window as any).G7Core;
        setLocation('/');
    });

    it('reports a PayPal cancellation with the stored order and amount', async () => {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ orderId: 'ORD-1', amount: 1250 }));
        setLocation('/shop/checkout?error=USER_CANCEL&message=cancelled&orderId=ORD-1');
        const post = vi.fn().mockResolvedValue({ success: true });
        installG7Core(post);

        await reportPaymentFailureOnReturn();

        expect(post).toHaveBeenCalledWith(CLOSE_REPORT_PATH, {
            orderId: 'ORD-1',
            amount: 1250,
            code: 'USER_CANCEL',
            reason: 'cancelled',
        });
        expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('attaches the guest ownership header', async () => {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ orderId: 'ORD-2', amount: 900 }));
        setLocation('/shop/checkout?error=USER_CANCEL&orderId=ORD-2');
        const post = vi.fn().mockResolvedValue({ success: true });
        installG7Core(post, { guestOrderToken: 'tok' });

        await reportPaymentFailureOnReturn();

        expect(post).toHaveBeenCalledWith(CLOSE_REPORT_PATH, expect.any(Object), { headers: { 'X-Guest-Order-Token': 'tok' } });
    });

    it('does not report on the completion page', async () => {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ orderId: 'ORD-3', amount: 100 }));
        setLocation('/shop/orders/ORD-3/complete');
        const post = vi.fn();
        installG7Core(post);

        await reportPaymentFailureOnReturn();

        expect(post).not.toHaveBeenCalled();
        expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('ignores leftovers from a different order', async () => {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ orderId: 'ORD-OLD', amount: 100 }));
        setLocation('/shop/checkout?error=USER_CANCEL&orderId=ORD-NEW');
        const post = vi.fn();
        installG7Core(post);

        await reportPaymentFailureOnReturn();

        expect(post).not.toHaveBeenCalled();
    });

    it('does not treat a busy callback as a failure', async () => {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ orderId: 'ORD-4', amount: 100 }));
        setLocation('/shop/checkout?error=callback_locked&orderId=ORD-4');
        const post = vi.fn();
        installG7Core(post);

        await reportPaymentFailureOnReturn();

        expect(post).not.toHaveBeenCalled();
    });
});
