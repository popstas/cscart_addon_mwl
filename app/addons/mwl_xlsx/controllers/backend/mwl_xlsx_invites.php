<?php
use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Enum\UserTypes;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'invites') {
    // Получаем все активные ekey для восстановления пароля
    $current_time = TIME;
    
    $invites = db_get_array(
        "SELECT 
            e.object_id as user_id,
            e.ekey,
            e.ttl,
            e.data,
            u.email,
            u.firstname,
            u.lastname,
            u.company_id,
            u.company,
            u.last_login,
            c.company as company_name
        FROM ?:ekeys e
        LEFT JOIN ?:users u ON e.object_id = u.user_id
        LEFT JOIN ?:companies c ON u.company_id = c.company_id
        WHERE e.object_type = ?s 
        AND e.ttl > ?i
        ORDER BY e.ttl DESC",
        defined('RECOVERY_PASSWORD_EKEY_TYPE') ? RECOVERY_PASSWORD_EKEY_TYPE : 'R',
        $current_time
    );

    // Вычисляем оставшееся время для каждого ekey
    foreach ($invites as &$invite) {
        $remaining_seconds = $invite['ttl'] - $current_time;
        $invite['remaining_hours'] = max(0, round($remaining_seconds / 3600, 1));
        $invite['remaining_days'] = max(0, round($remaining_seconds / (24 * 3600), 1));
        
        // Формируем ссылку для восстановления пароля
        $invite['recover_link'] = fn_url(
            'auth.recover_password?ekey=' . rawurlencode($invite['ekey']),
            'C',
            'https'
        );
    }

    \Tygh::$app['view']->assign('invites', $invites);
}
