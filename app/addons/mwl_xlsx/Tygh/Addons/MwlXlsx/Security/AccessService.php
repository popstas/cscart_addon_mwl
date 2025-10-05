<?php

namespace Tygh\Addons\MwlXlsx\Security;

use Tygh\Registry;

class AccessService
{
    public function checkUsergroupAccess(array $auth, string $setting_key): bool
    {
        if (($auth['user_type'] ?? '') === 'A') {
            return true;
        }

        $allowed = Registry::get("addons.mwl_xlsx.$setting_key");

        if ($allowed === '' || $allowed === null) {
            return true;
        }

        if (is_array($allowed)) {
            if (!$allowed) {
                return true;
            }

            $allowed_usergroups = array_map('intval', $allowed);
        } else {
            $allowed_string = (string) $allowed;

            if ($allowed_string === '') {
                return true;
            }

            $allowed_usergroups = array_map('intval', explode(',', $allowed_string));

            if (!$allowed_usergroups) {
                return true;
            }
        }

        $usergroups = array_map('intval', $auth['usergroup_ids'] ?? []);

        return (bool) array_intersect($allowed_usergroups, $usergroups);
    }

    public function canAccessLists(array $auth): bool
    {
        return $this->checkUsergroupAccess($auth, 'allowed_usergroups');
    }

    public function canViewPrice(array $auth): bool
    {
        if (($auth['user_type'] ?? '') === 'A') {
            return true;
        }

        if (Registry::get('addons.mwl_xlsx.hide_price_for_guests') === 'Y' && empty($auth['user_id'])) {
            return false;
        }

        return $this->checkUsergroupAccess($auth, 'authorized_usergroups');
    }
}
