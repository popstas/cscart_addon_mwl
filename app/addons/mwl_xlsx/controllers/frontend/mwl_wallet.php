<?php
use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'create_checkout') {
        $auth = Tygh::$app['session']['auth'] ?? [];

        if (empty($auth['user_id'])) {
            $return_url = rawurlencode(fn_url('mwl_wallet.topup', 'C'));

            return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form?return_url=' . $return_url];
        }

        $amount = isset($_REQUEST['amount']) ? (float) $_REQUEST['amount'] : 0.0;
        $currency = isset($_REQUEST['currency']) ? (string) $_REQUEST['currency'] : '';

        $error = null;
        if (!fn_mwl_wallet_validate_topup_amount($amount, $currency, $error)) {
            fn_set_notification('E', __('error'), $error);

            return [CONTROLLER_STATUS_REDIRECT, 'mwl_wallet.topup'];
        }

        try {
            $checkout_url = fn_mwl_wallet_create_checkout_session((int) $auth['user_id'], $amount, $currency);
        } catch (\Exception $exception) {
            fn_set_notification('E', __('error'), __('mwl_wallet.error_creating_checkout', [
                '[message]' => $exception->getMessage(),
            ]));

            return [CONTROLLER_STATUS_REDIRECT, 'mwl_wallet.topup'];
        }

        return [CONTROLLER_STATUS_REDIRECT, $checkout_url];
    }

    return [CONTROLLER_STATUS_OK];
}

if ($mode === 'webhook') {
    fn_mwl_wallet_handle_webhook();
}

$auth = Tygh::$app['session']['auth'] ?? [];

if (empty($auth['user_id'])) {
    $return_url = rawurlencode(fn_url('mwl_wallet.topup', 'C'));

    return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form?return_url=' . $return_url];
}

$user_id = (int) $auth['user_id'];

if ($mode === 'topup') {
    $balance = fn_mwl_wallet_get_balance($user_id);
    $allowed_currencies = fn_mwl_wallet_get_allowed_currencies();

    $selected_currency = isset($_REQUEST['currency']) ? strtoupper((string) $_REQUEST['currency']) : $balance['currency'];
    if (!in_array($selected_currency, $allowed_currencies, true)) {
        $selected_currency = $allowed_currencies[0];
    }

    $view = Tygh::$app['view'];
    $view->assign('wallet_balance', $balance);
    $view->assign('wallet_transactions', fn_mwl_wallet_get_transactions($user_id, ['limit' => 20]));
    $view->assign('wallet_limits', fn_mwl_wallet_get_limits());
    $view->assign('wallet_allowed_currencies', $allowed_currencies);
    $view->assign('wallet_selected_currency', $selected_currency);
    $view->assign('currencies', Registry::get('currencies'));
    $settings = Registry::get('addons.mwl_xlsx');
    $view->assign('wallet_fee_settings', [
        'percent' => isset($settings['fee_percent']) ? (float) $settings['fee_percent'] : 0.0,
        'fixed'   => isset($settings['fee_fixed']) ? (float) $settings['fee_fixed'] : 0.0,
    ]);

    return [CONTROLLER_STATUS_OK];
}

if ($mode === 'success' || $mode === 'cancel') {
    $txn_id = isset($_REQUEST['txn_id']) ? (int) $_REQUEST['txn_id'] : 0;
    $transaction = $txn_id ? fn_mwl_wallet_get_transaction($txn_id) : null;

    $view = Tygh::$app['view'];
    $view->assign('wallet_transaction', $transaction);
    $view->assign('wallet_is_success', $mode === 'success');
    $view->assign('wallet_is_cancel', $mode === 'cancel');

    return [CONTROLLER_STATUS_OK, 'mwl_wallet.result'];
}

return [CONTROLLER_STATUS_NO_PAGE];
