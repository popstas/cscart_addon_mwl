<?php

namespace Tygh\Addons\MwlXlsx\MediaList;

use InvalidArgumentException;
use Tygh\Database\Connection;
use Tygh\Registry;
use Tygh\Tygh;

class ListRepository
{
    /** @var Connection */
    private $db;

    /** @var bool */
    private $userSettingsTableEnsured = false;

    public function __construct(?Connection $db = null)
    {
        if ($db === null) {
            $container = Tygh::$app ?? null;
            if ($container instanceof \ArrayAccess && $container->offsetExists('db')) {
                $db = $container['db'];
            }
        }

        if (!$db instanceof Connection) {
            throw new InvalidArgumentException('Database connection is required');
        }

        $this->db = $db;
    }

    public function findByUserId(int $list_id, int $user_id): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND user_id = ?i',
            $list_id,
            $user_id
        ) ?: null;
    }

    public function findBySessionId(int $list_id, string $session_id): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND session_id = ?s',
            $list_id,
            $session_id
        ) ?: null;
    }

    public function getListsByUserId(int $user_id): array
    {
        return $this->getLists(['user_id' => $user_id]);
    }

    public function getListsBySessionId(string $session_id): array
    {
        return $this->getLists(['session_id' => $session_id]);
    }

    private function getLists(array $condition): array
    {
        return $this->db->getArray(
            'SELECT l.*, COUNT(lp.product_id) AS products_count'
            . ' FROM ?:mwl_xlsx_lists AS l'
            . ' LEFT JOIN ?:mwl_xlsx_list_products AS lp ON lp.list_id = l.list_id'
            . ' WHERE ?w GROUP BY l.list_id ORDER BY l.created_at ASC',
            $condition
        );
    }

    public function countListsByUserId(int $user_id): int
    {
        return (int) $this->db->getField(
            'SELECT COUNT(*) FROM ?:mwl_xlsx_lists WHERE user_id = ?i',
            $user_id
        );
    }

    public function countListsBySessionId(string $session_id): int
    {
        return (int) $this->db->getField(
            'SELECT COUNT(*) FROM ?:mwl_xlsx_lists WHERE session_id = ?s',
            $session_id
        );
    }

    public function countProducts(int $list_id): int
    {
        return (int) $this->db->getField(
            'SELECT COUNT(*) FROM ?:mwl_xlsx_list_products WHERE list_id = ?i',
            $list_id
        );
    }

    public function productExists(int $list_id, int $product_id, string $product_options): bool
    {
        return (bool) $this->db->getField(
            'SELECT 1 FROM ?:mwl_xlsx_list_products WHERE list_id = ?i AND product_id = ?i AND product_options = ?s',
            $list_id,
            $product_id,
            $product_options
        );
    }

    public function insertProduct(int $list_id, int $product_id, string $product_options, int $amount): void
    {
        $this->db->query('INSERT INTO ?:mwl_xlsx_list_products ?e', [
            'list_id'         => $list_id,
            'product_id'      => $product_id,
            'product_options' => $product_options,
            'amount'          => $amount,
            'timestamp'       => TIME,
        ]);
    }

    public function deleteProduct(int $list_id, int $product_id): int
    {
        return (int) $this->db->query(
            'DELETE FROM ?:mwl_xlsx_list_products WHERE list_id = ?i AND product_id = ?i',
            $list_id,
            $product_id
        );
    }

    public function touchList(int $list_id, string $timestamp): void
    {
        $this->db->query(
            'UPDATE ?:mwl_xlsx_lists SET updated_at = ?s WHERE list_id = ?i',
            $timestamp,
            $list_id
        );
    }

    public function renameList(int $list_id, string $name, string $timestamp): void
    {
        $this->db->query(
            'UPDATE ?:mwl_xlsx_lists SET name = ?s, updated_at = ?s WHERE list_id = ?i',
            $name,
            $timestamp,
            $list_id
        );
    }

    public function listBelongsTo(int $list_id, ?int $user_id, ?string $session_id): bool
    {
        if ($user_id !== null) {
            return (bool) $this->db->getField(
                'SELECT list_id FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND user_id = ?i',
                $list_id,
                $user_id
            );
        }

        if ($session_id !== null) {
            return (bool) $this->db->getField(
                'SELECT list_id FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND session_id = ?s',
                $list_id,
                $session_id
            );
        }

        return false;
    }

    public function deleteList(int $list_id): void
    {
        $this->db->query('DELETE FROM ?:mwl_xlsx_lists WHERE list_id = ?i', $list_id);
    }

    public function deleteListProducts(int $list_id): void
    {
        $this->db->query('DELETE FROM ?:mwl_xlsx_list_products WHERE list_id = ?i', $list_id);
    }

    public function getUserSettingsByUserId(int $user_id): ?array
    {
        $this->ensureUserSettingsTable();

        $row = $this->db->getRow(
            'SELECT price_multiplier, price_append, round_to FROM ?:mwl_xlsx_user_settings WHERE user_id = ?i ORDER BY id DESC LIMIT 1',
            $user_id
        );

        return $row ?: null;
    }

    public function getUserSettingsBySessionId(string $session_id): ?array
    {
        $this->ensureUserSettingsTable();

        $row = $this->db->getRow(
            'SELECT price_multiplier, price_append, round_to FROM ?:mwl_xlsx_user_settings WHERE session_id = ?s ORDER BY id DESC LIMIT 1',
            $session_id
        );

        return $row ?: null;
    }

    public function saveUserSettingsForUser(int $user_id, array $data): void
    {
        $this->ensureUserSettingsTable();

        $exists = $this->db->getField(
            'SELECT id FROM ?:mwl_xlsx_user_settings WHERE user_id = ?i ORDER BY id DESC LIMIT 1',
            $user_id
        );

        if ($exists) {
            $this->db->query('UPDATE ?:mwl_xlsx_user_settings SET ?u WHERE id = ?i', $data, (int) $exists);

            return;
        }

        $this->db->query('INSERT INTO ?:mwl_xlsx_user_settings ?e', $data);
    }

    public function saveUserSettingsForSession(string $session_id, array $data): void
    {
        $this->ensureUserSettingsTable();

        $exists = $this->db->getField(
            'SELECT id FROM ?:mwl_xlsx_user_settings WHERE session_id = ?s ORDER BY id DESC LIMIT 1',
            $session_id
        );

        if ($exists) {
            $this->db->query('UPDATE ?:mwl_xlsx_user_settings SET ?u WHERE id = ?i', $data, (int) $exists);

            return;
        }

        $this->db->query('INSERT INTO ?:mwl_xlsx_user_settings ?e', $data);
    }

    private function ensureUserSettingsTable(): void
    {
        if ($this->userSettingsTableEnsured) {
            return;
        }

        $this->db->query("CREATE TABLE IF NOT EXISTS `?:mwl_xlsx_user_settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `session_id` VARCHAR(64) NOT NULL DEFAULT '',
            `price_multiplier` DECIMAL(12,4) NOT NULL DEFAULT '1.0000',
            `price_append` INT NOT NULL DEFAULT '0',
            `round_to` INT NOT NULL DEFAULT '10',
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `session_id` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $prefix = (string) Registry::get('config.table_prefix');
        $table = $prefix . 'mwl_xlsx_user_settings';

        $has_round_to = (int) $this->db->getField(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s AND COLUMN_NAME = 'round_to'",
            $table
        );

        if (!$has_round_to) {
            $this->db->query('ALTER TABLE ?:mwl_xlsx_user_settings ADD COLUMN `round_to` DECIMAL(12,4) NOT NULL DEFAULT 10');
        }

        $this->userSettingsTableEnsured = true;
    }
}
