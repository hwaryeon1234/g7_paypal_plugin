<?php

/**
 * PayPal 플러그인 서버 메시지 (Korean)
 */
return [
    'checkout_created' => 'PayPal 결제 화면으로 이동합니다.',
    'order_description_more' => '외 :count건',

    'errors' => [
        'not_configured' => 'PayPal 연동 키가 설정되지 않았습니다. 관리자에게 문의하세요.',
        'auth_failed' => 'PayPal 인증에 실패했습니다. Client ID 와 Secret 을 확인하세요.',
        'currency_not_allowed' => '이 통화로는 PayPal 결제를 할 수 없습니다.',
        'invalid_amount' => 'PayPal 로 결제할 수 없는 금액입니다.',
        'order_not_payable' => '결제할 수 없는 주문 상태입니다.',
        'payment_method_mismatch' => 'PayPal 결제로 만든 주문이 아닙니다.',
        'create_failed' => 'PayPal 결제를 시작하지 못했습니다. 잠시 후 다시 시도해 주세요.',
        'too_many_attempts' => '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.',
        'order_not_found' => '주문을 찾을 수 없습니다.',
        'ownership_failed' => '주문 소유자 확인에 실패했습니다.',
        'amount_mismatch' => '결제 금액이 주문 금액과 일치하지 않아 결제를 취소했습니다.',
        'USER_CANCEL' => 'PayPal 결제를 취소했습니다.',
        'capture_failed' => 'PayPal 결제를 완료하지 못했습니다. 다시 시도해 주세요.',
        'capture_declined' => 'PayPal 이 결제를 거절했습니다. 다른 결제수단을 이용해 주세요.',
        'token_mismatch' => '결제 정보가 주문과 일치하지 않습니다.',
        'callback_locked' => '결제를 처리하는 중입니다. 잠시 후 주문 내역을 확인해 주세요.',
        'capture_mismatch' => '결제 정보가 주문과 일치하지 않습니다.',
        'paypal_order_mismatch' => '결제 정보가 주문과 일치하지 않습니다.',
        'invalid_params' => '잘못된 결제 요청입니다.',
    ],

    'refund' => [
        'default_note' => '주문 취소에 따른 환불',
        'verification_failed_note' => '결제 검증 실패로 자동 환불되었습니다.',
        'missing_capture_id' => 'PayPal 거래 번호(Capture ID)가 없어 환불할 수 없습니다.',
        'invalid_amount' => '환불 금액이 올바르지 않습니다.',
        'not_accepted' => 'PayPal 이 환불을 처리하지 않았습니다.',
        'api_error' => 'PayPal 환불에 실패했습니다. (:code)',
        'issues' => [
            'REFUND_AMOUNT_EXCEEDED' => '환불 금액이 남은 결제 금액보다 큽니다.',
            'CAPTURE_FULLY_REFUNDED' => '이미 전액 환불된 결제입니다.',
            'REFUND_TIME_LIMIT_EXCEEDED' => 'PayPal 환불 가능 기간(180일)이 지났습니다.',
            'TRANSACTION_REFUSED' => 'PayPal 이 환불을 거절했습니다. PayPal 대시보드에서 확인하세요.',
            'INSUFFICIENT_FUNDS' => 'PayPal 계정 잔액이 부족해 환불할 수 없습니다.',
        ],
    ],

    'settings' => [
        'redirect_url_format' => '주소는 {shopBase}, / 또는 http(s):// 로 시작해야 하며 공백을 포함할 수 없습니다.',
    ],
];
