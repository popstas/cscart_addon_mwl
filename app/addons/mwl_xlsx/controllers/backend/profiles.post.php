<?php

use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode === 'manage') {
    $view = Tygh::$app['view'];
    $view->assign('mwl_planfix_user_links', []);

    $users = (array) $view->getTemplateVars('users');

    if (!$users) {
        return;
    }

    $user_ids = [];
    $company_ids = [];

    foreach ($users as $user) {
        $user_id = isset($user['user_id']) ? (int) $user['user_id'] : 0;

        if ($user_id) {
            $user_ids[] = $user_id;
        }

        if (isset($user['company_id'])) {
            $company_ids[] = (int) $user['company_id'];
        }
    }

    $user_ids = array_unique(array_filter($user_ids));

    if (!$user_ids) {
        return;
    }

    $company_ids = array_unique(array_filter($company_ids));

    $link_repository = fn_mwl_planfix_link_repository();
    $planfix_origin = (string) Registry::get('addons.mwl_xlsx.planfix_origin');
    $planfix_links_raw = $link_repository->findByEntities('user', $user_ids, $company_ids);

    $planfix_links = [];

    foreach ($planfix_links_raw as $entity_id => $link) {
        if (!is_array($link)) {
            continue;
        }

        $link['planfix_url'] = fn_mwl_planfix_build_object_url($link, $planfix_origin);
        $planfix_links[(int) $entity_id] = $link;
    }

    $view->assign('mwl_planfix_user_links', $planfix_links);
}
