<?php

namespace Tygh\Addons\MwlXlsx\Customer;

use ArrayAccess;
use Tygh\Tygh;

class StatusResolver
{
    private const DEFAULT_LANGUAGE = 'en';

    /** @var callable */
    private $authProvider;

    /** @var callable */
    private $userInfoProvider;

    /** @var callable */
    private $usergroupsProvider;

    /** @var callable */
    private $langCodeProvider;

    /** @var array<int, string> */
    private $allowedUsergroups;

    /** @var array<string, array<string, string>> */
    private $labels;

    /** @var string */
    private $defaultLanguage;

    public function __construct(
        ?callable $authProvider = null,
        ?callable $userInfoProvider = null,
        ?callable $usergroupsProvider = null,
        ?callable $langCodeProvider = null,
        ?array $allowedUsergroups = null,
        ?array $labels = null,
        ?string $defaultLanguage = null
    ) {
        $this->authProvider = $authProvider ?: static function () {
            $container = Tygh::$app ?? null;

            if ($container instanceof ArrayAccess && $container->offsetExists('session')) {
                $session = $container['session'];

                if ($session instanceof ArrayAccess && $session->offsetExists('auth')) {
                    $auth = $session['auth'];

                    return is_array($auth) ? $auth : [];
                }

                if (is_array($session) && isset($session['auth']) && is_array($session['auth'])) {
                    return $session['auth'];
                }
            }

            return [];
        };

        $this->userInfoProvider = $userInfoProvider ?: static function (int $user_id): array {
            $info = \fn_get_user_info($user_id);

            return is_array($info) ? $info : [];
        };

        $this->usergroupsProvider = $usergroupsProvider ?: static function (): array {
            $usergroups = \fn_get_usergroups();

            return is_array($usergroups) ? $usergroups : [];
        };

        $this->langCodeProvider = $langCodeProvider ?: static function (): string {
            $container = Tygh::$app ?? null;

            if ($container instanceof ArrayAccess && $container->offsetExists('session')) {
                $session = $container['session'];

                if ($session instanceof ArrayAccess && $session->offsetExists('lang_code')) {
                    return (string) $session['lang_code'];
                }

                if (is_array($session) && isset($session['lang_code'])) {
                    return (string) $session['lang_code'];
                }
            }

            return defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : self::DEFAULT_LANGUAGE;
        };

        $this->allowedUsergroups = $allowedUsergroups ?: ['Global', 'Continental', 'National', 'Local'];

        $defaultLabels = [
            'ru' => [
                'Local' => 'Local',
                'National' => 'National',
                'Continental' => 'Continental',
                'Global' => 'Global',
            ],
            'en' => [
                'Local' => 'Local',
                'National' => 'National',
                'Continental' => 'Continental',
                'Global' => 'Global',
            ],
        ];

        $labels = $labels ?: $defaultLabels;
        $normalizedLabels = [];

        foreach ($labels as $language => $map) {
            $language = strtolower((string) $language);
            $normalizedLabels[$language] = is_array($map) ? $map : [];
        }

        $this->labels = $normalizedLabels;
        $this->defaultLanguage = strtolower($defaultLanguage ?: self::DEFAULT_LANGUAGE);
    }

    public static function fromContainer(): self
    {
        $container = Tygh::$app ?? null;

        if ($container instanceof ArrayAccess && $container->offsetExists('addons.mwl_xlsx.customer.status_resolver')) {
            return $container['addons.mwl_xlsx.customer.status_resolver'];
        }

        return new self();
    }

    public function resolveStatus(): string
    {
        $auth = ($this->authProvider)();
        $user_id = isset($auth['user_id']) ? (int) $auth['user_id'] : 0;

        if ($user_id <= 0) {
            return '';
        }

        $user_data = ($this->userInfoProvider)($user_id);
        $user_usergroups = isset($user_data['usergroups']) && is_array($user_data['usergroups'])
            ? $user_data['usergroups']
            : [];

        $active_usergroups = array_filter($user_usergroups, static function ($usergroup): bool {
            return is_array($usergroup)
                && isset($usergroup['status'])
                && $usergroup['status'] === 'A';
        });

        $user_usergroup_ids = array_map('intval', array_column($active_usergroups, 'usergroup_id'));

        if (!$user_usergroup_ids) {
            return '';
        }

        $available_usergroups = ($this->usergroupsProvider)();
        $usergroup_name_to_id = [];

        foreach ($available_usergroups as $usergroup) {
            if (!is_array($usergroup)) {
                continue;
            }

            if (!isset($usergroup['usergroup'], $usergroup['usergroup_id'])) {
                continue;
            }

            $name = (string) $usergroup['usergroup'];
            $id = (int) $usergroup['usergroup_id'];

            $usergroup_name_to_id[$name] = $id;
        }

        foreach ($this->allowedUsergroups as $allowed_group_name) {
            if (!isset($usergroup_name_to_id[$allowed_group_name])) {
                continue;
            }

            $allowed_group_id = $usergroup_name_to_id[$allowed_group_name];

            if (in_array($allowed_group_id, $user_usergroup_ids, true)) {
                return $allowed_group_name;
            }
        }

        return '';
    }

    public function resolveStatusLabel(?string $lang_code = null): string
    {
        $status = $this->resolveStatus();

        if ($status === '') {
            return '';
        }

        $lang_code = $lang_code !== null ? strtolower($lang_code) : strtolower(($this->langCodeProvider)());

        if (isset($this->labels[$lang_code][$status])) {
            return (string) $this->labels[$lang_code][$status];
        }

        $default = $this->defaultLanguage;

        if (isset($this->labels[$default][$status])) {
            return (string) $this->labels[$default][$status];
        }

        return $status;
    }
}
