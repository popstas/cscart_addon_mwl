<?php
namespace Tygh\Addons\MwlXlsx\Planfix;

use Tygh\Registry;

class IntegrationSettings
{
    /** @var string */
    protected $mcp_endpoint = '';

    /** @var string */
    protected $mcp_auth_token = '';

    /** @var string */
    protected $webhook_basic_login = '';

    /** @var string */
    protected $webhook_basic_password = '';

    /** @var string */
    protected $direction_default = '';

    /** @var array */
    protected $auto_task_statuses = [];

    /** @var bool */
    protected $sync_comments = false;

    /** @var bool */
    protected $sync_payments = false;

    /** @var array */
    protected $webhook_allowlist_ips = [];

    public static function fromRegistry(): self
    {
        $instance = new self();

        $instance->mcp_endpoint = self::normalizeString(Registry::get('addons.mwl_xlsx.planfix_mcp_endpoint'));
        $instance->mcp_auth_token = self::normalizeString(Registry::get('addons.mwl_xlsx.planfix_mcp_auth_token'));
        $instance->webhook_basic_login = self::normalizeString(Registry::get('addons.mwl_xlsx.planfix_webhook_basic_login'));
        $instance->webhook_basic_password = self::normalizeString(Registry::get('addons.mwl_xlsx.planfix_webhook_basic_password'));
        $instance->direction_default = self::normalizeString(Registry::get('addons.mwl_xlsx.planfix_direction_default'));
        $instance->auto_task_statuses = self::normalizeList(Registry::get('addons.mwl_xlsx.planfix_auto_task_statuses'));
        $instance->sync_comments = self::normalizeFlag(Registry::get('addons.mwl_xlsx.planfix_sync_comments'));
        $instance->sync_payments = self::normalizeFlag(Registry::get('addons.mwl_xlsx.planfix_sync_payments'));
        $instance->webhook_allowlist_ips = self::normalizeList(Registry::get('addons.mwl_xlsx.planfix_webhook_allowlist_ips'));

        return $instance;
    }

    public function toArray(): array
    {
        return [
            'mcp_endpoint'          => $this->getMcpEndpoint(),
            'mcp_auth_token'        => $this->getMcpAuthToken(),
            'webhook_basic_login'   => $this->getWebhookBasicLogin(),
            'webhook_basic_password'=> $this->getWebhookBasicPassword(),
            'direction_default'     => $this->getDirectionDefault(),
            'auto_task_statuses'    => $this->getAutoTaskStatuses(),
            'sync_comments'         => $this->shouldSyncComments(),
            'sync_payments'         => $this->shouldSyncPayments(),
            'webhook_allowlist_ips' => $this->getWebhookAllowlistIps(),
        ];
    }

    public function getMcpEndpoint(): string
    {
        return $this->mcp_endpoint;
    }

    public function getMcpAuthToken(): string
    {
        return $this->mcp_auth_token;
    }

    public function getWebhookBasicLogin(): string
    {
        return $this->webhook_basic_login;
    }

    public function getWebhookBasicPassword(): string
    {
        return $this->webhook_basic_password;
    }

    public function getWebhookBasicAuthCredentials(): array
    {
        return [
            $this->getWebhookBasicLogin(),
            $this->getWebhookBasicPassword(),
        ];
    }

    public function getDirectionDefault(): string
    {
        return $this->direction_default;
    }

    public function getAutoTaskStatuses(): array
    {
        return $this->auto_task_statuses;
    }

    public function shouldSyncComments(): bool
    {
        return $this->sync_comments;
    }

    public function shouldSyncPayments(): bool
    {
        return $this->sync_payments;
    }

    public function getWebhookAllowlistIps(): array
    {
        return $this->webhook_allowlist_ips;
    }

    public function hasMcpCredentials(): bool
    {
        return $this->getMcpEndpoint() !== '' && $this->getMcpAuthToken() !== '';
    }

    public function hasWebhookBasicAuth(): bool
    {
        return $this->getWebhookBasicLogin() !== '' || $this->getWebhookBasicPassword() !== '';
    }

    protected static function normalizeString($value): string
    {
        return trim((string) ($value ?? ''));
    }

    protected static function normalizeList($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\s,;]+/', (string) ($value ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!$items) {
            return [];
        }

        $items = array_map(static function ($item) {
            return trim((string) $item);
        }, $items);

        $items = array_filter($items, static function ($item) {
            return $item !== '';
        });

        return array_values(array_unique($items));
    }

    protected static function normalizeFlag($value): bool
    {
        return (string) ($value ?? '') === 'Y';
    }
}
