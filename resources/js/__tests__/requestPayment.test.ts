import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    browserNavigation,
    CHECKOUT_PATH,
    ERROR_MODAL_ID,
    extractErrorMessage,
    isTrustedApproveUrl,
    requestPaymentHandler,
} from '../handlers/requestPayment';
import { STORAGE_KEY } from '../paymentCloseReport';

const pgPaymentData = { order_number: 'ORD-1001', amount: 1250, currency: 'USD' };

function installG7Core(post: ReturnType<typeof vi.fn>, state: Record<string, unknown> = {}) {
    const setLocal = vi.fn();
    const open = vi.fn();

    (window as any).G7Core = {
        api: { post },
        state: { get: () => state, setLocal },
        modal: { open },
        t: (key: string) => key,
    };

    return { setLocal, open };
}

describe('isTrustedApproveUrl', () => {
    it('accepts PayPal https hosts only', () => {
        expect(isTrustedApproveUrl('https://www.paypal.com/checkoutnow?token=5O190127TN364715T')).toBe(true);
        expect(isTrustedApproveUrl('https://www.sandbox.paypal.com/checkoutnow?token=X')).toBe(true);
        expect(isTrustedApproveUrl('http://www.paypal.com/checkoutnow')).toBe(false);
        expect(isTrustedApproveUrl('https://paypal.com.evil.example/checkoutnow')).toBe(false);
        expect(isTrustedApproveUrl('https://evil.example/?u=https://www.paypal.com')).toBe(false);
        expect(isTrustedApproveUrl('javascript:alert(1)')).toBe(false);
        expect(isTrustedApproveUrl(undefined)).toBe(false);
    });
});

describe('extractErrorMessage', () => {
    it('prefers the server message', () => {
        expect(extractErrorMessage({ response: { data: { message: 'PayPal is not available for this currency.' } } }))
            .toBe('PayPal is not available for this currency.');
    });

    it('falls back to a generic message', () => {
        expect(extractErrorMessage({})).toContain('PayPal');
    });
});

describe('requestPaymentHandler', () => {
    let go: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        sessionStorage.clear();
        go = vi.spyOn(browserNavigation, 'go').mockImplementation(() => undefined);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        delete (window as any).G7Core;
    });

    it('asks the server for a PayPal order with the order number only and redirects to PayPal', async () => {
        const post = vi.fn().mockResolvedValue({
            success: true,
            data: { approve_url: 'https://www.sandbox.paypal.com/checkoutnow?token=ABC', paypal_order_id: 'ABC' },
        });
        installG7Core(post);

        await requestPaymentHandler({ params: { pgPaymentData } });

        expect(post).toHaveBeenCalledWith(CHECKOUT_PATH, { orderId: 'ORD-1001' });
        expect(go).toHaveBeenCalledWith('https://www.sandbox.paypal.com/checkoutnow?token=ABC');
        expect(JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '{}')).toEqual({ orderId: 'ORD-1001', amount: 1250 });
    });

    it('sends the guest order token for guest orders', async () => {
        const post = vi.fn().mockResolvedValue({
            success: true,
            data: { approve_url: 'https://www.paypal.com/checkoutnow?token=ABC' },
        });
        installG7Core(post, { guestOrderToken: 'guest-token-1' });

        await requestPaymentHandler({ params: { pgPaymentData } });

        expect(post).toHaveBeenCalledWith(
            CHECKOUT_PATH,
            { orderId: 'ORD-1001' },
            { headers: { 'X-Guest-Order-Token': 'guest-token-1' } },
        );
    });

    it('refuses to navigate to a non-PayPal address', async () => {
        const post = vi.fn().mockResolvedValue({ success: true, data: { approve_url: 'https://evil.example/pay' } });
        const { open, setLocal } = installG7Core(post);

        await requestPaymentHandler({ params: { pgPaymentData } });

        expect(go).not.toHaveBeenCalled();
        expect(open).toHaveBeenCalledWith(ERROR_MODAL_ID);
        expect(setLocal).toHaveBeenCalledWith(expect.objectContaining({ isSubmittingOrder: false }));
        expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('shows the server error in the modal when checkout creation fails', async () => {
        const post = vi.fn().mockRejectedValue({ response: { data: { message: 'PayPal is not available for this currency.' } } });
        const { open, setLocal } = installG7Core(post);

        await requestPaymentHandler({ params: { pgPaymentData } });

        expect(go).not.toHaveBeenCalled();
        expect(open).toHaveBeenCalledWith(ERROR_MODAL_ID);
        expect(setLocal).toHaveBeenCalledWith({
            paypalErrorMessage: 'PayPal is not available for this currency.',
            isSubmittingOrder: false,
        });
    });

    it('does nothing harmful without payment data', async () => {
        const post = vi.fn();
        const { open } = installG7Core(post);

        await requestPaymentHandler({ params: {} });

        expect(post).not.toHaveBeenCalled();
        expect(open).toHaveBeenCalledWith(ERROR_MODAL_ID);
    });
});
