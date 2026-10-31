<?php

/**
 * PayPal プラグイン サーバーメッセージ (Japanese)
 */
return [
    'checkout_created' => 'PayPal の決済画面へ移動します。',
    'order_description_more' => 'ほか :count 件',

    'errors' => [
        'not_configured' => 'PayPal 連携キー(Client ID・Secret)が設定されていません。プラグイン設定で現在のモード(サンドボックス/ライブ)のキーを入力してください。',
        'connection_failed' => 'PayPal サーバーに接続できませんでした。サーバーの外部接続(api-m.paypal.com)を確認してください。',
        'auth_failed' => 'PayPal の認証に失敗しました。Client ID と Secret を確認してください。',
        'currency_not_allowed' => 'この通貨では PayPal 決済をご利用いただけません。',
        'invalid_amount' => 'PayPal で決済できない金額です。',
        'order_not_payable' => '決済できない注文状態です。',
        'payment_method_mismatch' => 'PayPal 決済で作成された注文ではありません。',
        'create_failed' => 'PayPal 決済を開始できませんでした。しばらくしてからもう一度お試しください。',
        'too_many_attempts' => 'リクエストが多すぎます。しばらくしてからもう一度お試しください。',
        'order_not_found' => '注文が見つかりません。',
        'ownership_failed' => '注文者の確認に失敗しました。',
        'amount_mismatch' => '決済金額が注文金額と一致しないため、決済を取り消しました。',
        'USER_CANCEL' => 'PayPal 決済をキャンセルしました。',
        'capture_failed' => 'PayPal 決済を完了できませんでした。もう一度お試しください。',
        'capture_declined' => 'PayPal が決済を拒否しました。別のお支払い方法をご利用ください。',
        'token_mismatch' => '決済情報が注文と一致しません。',
        'callback_locked' => '決済を処理しています。しばらくしてから注文履歴をご確認ください。',
        'capture_mismatch' => '決済情報が注文と一致しません。',
        'paypal_order_mismatch' => '決済情報が注文と一致しません。',
        'invalid_params' => '不正な決済リクエストです。',
    ],

    'refund' => [
        'default_note' => '注文キャンセルによる返金',
        'verification_failed_note' => '決済の検証に失敗したため自動的に返金されました。',
        'missing_capture_id' => 'PayPal の取引番号(Capture ID)がないため返金できません。',
        'invalid_amount' => '返金金額が正しくありません。',
        'not_accepted' => 'PayPal が返金を処理しませんでした。',
        'api_error' => 'PayPal の返金に失敗しました。(:code)',
        'issues' => [
            'REFUND_AMOUNT_EXCEEDED' => '返金金額が残りの決済金額を超えています。',
            'CAPTURE_FULLY_REFUNDED' => 'すでに全額返金された決済です。',
            'REFUND_TIME_LIMIT_EXCEEDED' => 'PayPal の返金可能期間(180日)を過ぎています。',
            'TRANSACTION_REFUSED' => 'PayPal が返金を拒否しました。PayPal ダッシュボードで確認してください。',
            'INSUFFICIENT_FUNDS' => 'PayPal アカウントの残高が不足しているため返金できません。',
        ],
    ],

    'claim' => [
        'description' => '交換・返品の追加決済 :number',
        'failed' => 'PayPal の追加決済を完了できませんでした。(:code)',
    ],

    'settings' => [
        'redirect_url_format' => 'アドレスは {shopBase}、/ または http(s):// で始まり、空白を含めることはできません。',
    ],
];
