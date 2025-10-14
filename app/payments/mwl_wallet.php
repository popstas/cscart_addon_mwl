<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

if (defined('PAYMENT_NOTIFICATION')) {
    return;
}

$order_id = (int) ($order_info['order_id'] ?? 0);
$user_id = (int) ($order_info['user_id'] ?? 0);
$currency = (string) ($order_info['secondary_currency'] ?? CART_PRIMARY_CURRENCY);
$amount = (float) ($order_info['total'] ?? 0.0);

if ($user_id <= 0 || $order_id <= 0) {
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => __('mwl_wallet_payment_requires_login'),
    ];
} elseif (!fn_mwl_wallet_require_balance($user_id, $amount, $currency)) {
    $pp_response = [
        'order_status' => 'F',
        'reason_text'  => __('mwl_wallet_insufficient_funds'),
    ];
} else {
    $wallet = fn_mwl_wallet_get_balance($user_id);
    $wallet_currency = $wallet['currency'];
    $amount_in_wallet_currency = fn_mwl_wallet_convert_amount($amount, $currency, $wallet_currency);

    fn_mwl_wallet_change_balance($user_id, -$amount_in_wallet_currency, $wallet_currency, [
        'type'        => 'debit',
        'status'      => 'succeeded',
        'source'      => 'order',
        'external_id' => (string) $order_id,
        'meta'        => [
            'order_id'           => $order_id,
            'order_currency'     => $currency,
            'order_total'        => $amount,
            'wallet_currency'    => $wallet_currency,
            'debited_amount'     => $amount_in_wallet_currency,
        ],
    ]);

    $pp_response = [
        'order_status'    => 'P',
        'transaction_id'  => 'WALLET-' . $order_id,
        'reason_text'     => '',
    ];
}

fn_finish_payment($order_id, $pp_response, false);
fn_order_placement_routines('route', $order_id);
