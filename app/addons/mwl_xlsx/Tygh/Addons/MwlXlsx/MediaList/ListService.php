<?php

namespace Tygh\Addons\MwlXlsx\MediaList;

use Tygh\Registry;
use Tygh\Tygh;

class ListService
{
    public const STATUS_ADDED = 'added';
    public const STATUS_EXISTS = 'exists';
    public const STATUS_LIMIT = 'limit';

    /** @var ListRepository */
    private $repository;

    /** @var object|null */
    private $session;

    public function __construct(ListRepository $repository, $session = null)
    {
        $this->repository = $repository;
        $this->session = $session ?: $this->resolveSessionFromContainer();
    }

    public function getList(int $list_id, array $auth): ?array
    {
        if (!empty($auth['user_id'])) {
            return $this->repository->findByUserId($list_id, (int) $auth['user_id']);
        }

        $session_id = $this->getSessionId();

        return $session_id === null
            ? null
            : $this->repository->findBySessionId($list_id, $session_id);
    }

    public function getLists(?int $user_id = null, ?string $session_id = null): array
    {
        if ($user_id) {
            return $this->repository->getListsByUserId($user_id);
        }

        $session_id = $session_id ?: $this->getSessionId();

        if ($session_id === null) {
            return [];
        }

        return $this->repository->getListsBySessionId($session_id);
    }

    public function getMediaListsCount(array $auth): int
    {
        if (!empty($auth['user_id'])) {
            return $this->repository->countListsByUserId((int) $auth['user_id']);
        }

        $session_id = $this->getSessionId();

        if ($session_id === null) {
            return 0;
        }

        return $this->repository->countListsBySessionId($session_id);
    }

    public function addProduct(int $list_id, int $product_id, array $options = [], int $amount = 1): string
    {
        $limit = (int) Registry::get('addons.mwl_xlsx.max_list_items');

        if ($limit > 0 && $this->repository->countProducts($list_id) >= $limit) {
            return self::STATUS_LIMIT;
        }

        $serialized = serialize($options);

        if ($this->repository->productExists($list_id, $product_id, $serialized)) {
            return self::STATUS_EXISTS;
        }

        $timestamp = $this->now();
        $this->repository->insertProduct($list_id, $product_id, $serialized, $amount);
        $this->repository->touchList($list_id, $timestamp);

        return self::STATUS_ADDED;
    }

    public function removeProduct(int $list_id, int $product_id): bool
    {
        $removed = $this->repository->deleteProduct($list_id, $product_id);

        if ($removed) {
            $this->repository->touchList($list_id, $this->now());
        }

        return $removed > 0;
    }

    public function updateListName(int $list_id, string $name, ?int $user_id = null, ?string $session_id = null): bool
    {
        if ($name === '') {
            return false;
        }

        [$user_id, $session_id] = $this->normalizeIdentity($user_id, $session_id);

        if (!$this->repository->listBelongsTo($list_id, $user_id, $session_id)) {
            return false;
        }

        $this->repository->renameList($list_id, $name, $this->now());

        return true;
    }

    public function deleteList(int $list_id, ?int $user_id = null, ?string $session_id = null): bool
    {
        [$user_id, $session_id] = $this->normalizeIdentity($user_id, $session_id);

        if (!$this->repository->listBelongsTo($list_id, $user_id, $session_id)) {
            return false;
        }

        $this->repository->deleteListProducts($list_id);
        $this->repository->deleteList($list_id);

        return true;
    }

    public function getUserSettings(array $auth): array
    {
        if (!empty($auth['user_id'])) {
            $row = $this->repository->getUserSettingsByUserId((int) $auth['user_id']);
        } else {
            $session_id = $this->getSessionId();
            $row = $session_id === null ? null : $this->repository->getUserSettingsBySessionId($session_id);
        }

        return [
            'price_multiplier' => isset($row['price_multiplier']) ? (float) $row['price_multiplier'] : 1.0,
            'price_append'     => isset($row['price_append']) ? (int) $row['price_append'] : 0,
            'round_to'         => isset($row['round_to']) ? (int) $row['round_to'] : 10,
        ];
    }

    public function saveUserSettings(array $auth, array $data): void
    {
        $settings = [
            'price_multiplier' => isset($data['price_multiplier']) ? (float) $data['price_multiplier'] : 1.0,
            'price_append'     => isset($data['price_append']) ? (int) $data['price_append'] : 0,
            'round_to'         => isset($data['round_to']) ? (int) $data['round_to'] : 10,
            'updated_at'       => $this->now(),
        ];

        if (!empty($auth['user_id'])) {
            $this->repository->saveUserSettingsForUser((int) $auth['user_id'], [
                'user_id'         => (int) $auth['user_id'],
                'session_id'      => '',
            ] + $settings);

            return;
        }

        $session_id = $this->getSessionId();

        if ($session_id === null) {
            return;
        }

        $this->repository->saveUserSettingsForSession($session_id, [
            'user_id'         => 0,
            'session_id'      => $session_id,
        ] + $settings);
    }

    private function normalizeIdentity(?int $user_id, ?string $session_id): array
    {
        if ($user_id) {
            return [$user_id, null];
        }

        if ($session_id) {
            return [null, $session_id];
        }

        return [null, $this->getSessionId()];
    }

    private function getSessionId(): ?string
    {
        $session = $this->session;

        if ($session && method_exists($session, 'getID')) {
            $id = $session->getID();

            return is_string($id) ? $id : null;
        }

        $container = Tygh::$app ?? null;

        if ($container instanceof \ArrayAccess && $container->offsetExists('session')) {
            $session = $container['session'];
            if ($session && method_exists($session, 'getID')) {
                $id = $session->getID();

                return is_string($id) ? $id : null;
            }
        }

        return null;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function resolveSessionFromContainer()
    {
        $container = Tygh::$app ?? null;

        if ($container instanceof \ArrayAccess && $container->offsetExists('session')) {
            return $container['session'];
        }

        return null;
    }
}
