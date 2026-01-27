<?php

namespace Tygh\Addons\MwlXlsx\Planfix;

use Tygh\Addons\MwlXlsx\Telegram\TelegramService;
use Tygh\Registry;

class PlanfixService
{
    /** @var LinkRepository */
    private $linkRepository;

    /** @var McpClient */
    private $client;

    /** @var IntegrationSettings */
    private $settings;

    /** @var TelegramService */
    private $telegramService;

    /** @var callable */
    private $orderFetcher;

    /** @var callable */
    private $userFetcher;

    /** @var callable */
    private $orderUrlBuilder;

    /** @var callable */
    private $orderStatusFetcher;

    /** @var callable */
    private $orderStatusUpdater;

    public function __construct(
        LinkRepository $linkRepository,
        McpClient $client,
        IntegrationSettings $settings,
        TelegramService $telegramService,
        callable $orderFetcher = null,
        callable $userFetcher = null,
        callable $orderUrlBuilder = null,
        callable $orderStatusFetcher = null,
        callable $orderStatusUpdater = null
    ) {
        $this->linkRepository = $linkRepository;
        $this->client = $client;
        $this->settings = $settings;
        $this->telegramService = $telegramService;
        $this->orderFetcher = $orderFetcher ?: static function (int $order_id): array {
            return fn_get_order_info($order_id, false, true, true, false) ?: [];
        };
        $this->userFetcher = $userFetcher ?: static function (int $user_id): array {
            return fn_get_user_info($user_id) ?: [];
        };
        $this->orderUrlBuilder = $orderUrlBuilder ?: static function (int $order_id): string {
            if (!$order_id) {
                return '';
            }

            return fn_url('orders.details?order_id=' . $order_id, 'A', 'current', defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en', true);
        };
        $this->orderStatusFetcher = $orderStatusFetcher ?: static function (int $order_id): string {
            $status = db_get_field('SELECT status FROM ?:orders WHERE order_id = ?i', $order_id);

            return (string) ($status ?? '');
        };
        $this->orderStatusUpdater = $orderStatusUpdater ?: static function (int $order_id, string $status_to, string $status_from): bool {
            $result = fn_change_order_status($order_id, $status_to, $status_from);

            return $result !== false;
        };
    }

    public function getSettings(): IntegrationSettings
    {
        return $this->settings;
    }

    public function getMcpClient(): McpClient
    {
        return $this->client;
    }

    public function getLinkRepository(): LinkRepository
    {
        return $this->linkRepository;
    }

    public function buildObjectUrl(array $link, ?string $origin = null): string
    {
        $planfix_object_id = isset($link['planfix_object_id']) ? (string) $link['planfix_object_id'] : '';

        if ($planfix_object_id === '') {
            return '';
        }

        if ($origin === null) {
            $origin = (string) Registry::get('addons.mwl_xlsx.planfix_origin');
        }

        $origin = trim($origin);

        if ($origin === '') {
            return '';
        }

        $origin = rtrim($origin, '/');
        $type = isset($link['planfix_object_type']) ? trim((string) $link['planfix_object_type']) : '';

        if ($type !== '') {
            $origin .= '/' . ltrim($type, '/');
        }

        return $origin . '/' . rawurlencode($planfix_object_id);
    }

    public function decodeLinkExtra($extra): array
    {
        if (is_array($extra)) {
            return $extra;
        }

        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    public function updateLinkExtra(array $link, array $extraUpdates, array $additionalFields = []): void
    {
        if (empty($link['link_id'])) {
            return;
        }

        $extra = $this->decodeLinkExtra($link['extra'] ?? null);

        foreach ($extraUpdates as $key => $value) {
            if ($value === null) {
                unset($extra[$key]);
            } else {
                $extra[$key] = $value;
            }
        }

        $data = ['extra' => $extra];

        if ($additionalFields) {
            $data = array_merge($data, $additionalFields);
        }

        $this->linkRepository->update((int) $link['link_id'], $data);
    }

    public function buildSellTaskPayload(array $order_info): array
    {
        $order_id = isset($order_info['order_id']) ? (int) $order_info['order_id'] : 0;
        $company_id = isset($order_info['company_id']) ? (int) $order_info['company_id'] : 0;
        $primary_currency = isset($order_info['primary_currency'])
            ? (string) $order_info['primary_currency']
            : (defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'RUB');
        $secondary_currency = isset($order_info['secondary_currency']) ? (string) $order_info['secondary_currency'] : '';
        $currency_code = $secondary_currency !== '' ? $secondary_currency : ((string) ($order_info['currency'] ?? $primary_currency));

        // Check if order is wallet recharge
        $is_wallet_recharge = false;
        if ($order_id) {
            $wallet_recharge_order_id = db_get_field(
                'SELECT order_id FROM ?:wallet_offline_payment WHERE order_id = ?i',
                $order_id
            );
            $is_wallet_recharge = !empty($wallet_recharge_order_id);
        }

        if ($is_wallet_recharge) {
            $products = [
                [
                    'product' => __('wallet_recharge'),
                    'amount' => 1,
                ],
            ];
        } else {
            $products = isset($order_info['products']) && is_array($order_info['products'])
                ? $order_info['products']
                : [];
        }

        $first_product_name = '';
        foreach ($products as $item) {
            if (!empty($item['product'])) {
                $first_product_name = (string) $item['product'];
                break;
            }
        }

        if ($first_product_name === '') {
            $first_product_name = $order_id
                ? sprintf('#%s', fn_mwl_planfix_format_order_id($order_id))
                : __('order');
        }

        $storefront_url = fn_url('', 'C');
        $storefront_domain = $storefront_url ? parse_url($storefront_url, PHP_URL_HOST) : '';
        $storefront_domain = $storefront_domain !== '' ? $storefront_domain : 'CS-Cart';
        $task_name = sprintf('Продажа %s на %s', $first_product_name, $storefront_domain); 

        $lines = [];
        foreach ($products as $item) {
            $product_name = (string) ($item['product'] ?? '');
            if ($product_name === '' && !empty($item['product_id'])) {
                $product_name = sprintf('#%d', (int) $item['product_id']);
            }

            $amount = isset($item['amount']) ? (int) $item['amount'] : 0;
            if ($amount <= 0) {
                $amount = 1;
            }

            $subtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : 0.0;
            $price = number_format($subtotal, 2, '.', ' ');
            $price = rtrim(rtrim($price, '0'), '.');
            if ($price === '') {
                $price = '0';
            }

            $line = sprintf('- %s ×%d, %s', $product_name, $amount, $price);

            if ($currency_code !== '') {
                $line .= ' ' . $currency_code;
            }

            $lines[] = $line;
        }

        $description = implode("\n", $lines);
        $order_url = $order_id ? call_user_func($this->orderUrlBuilder, $order_id) : '';

        if ($order_url !== '') {
            if ($description !== '') {
                $description .= "\n\n";
            }

            $description .= sprintf('Ссылка на заказ: %s', $order_url);
        }

        if ($description === '' && $order_id) {
            $description = sprintf('Заказ #%s', fn_mwl_planfix_format_order_id($order_id));
        }

        $customer = [
            'name'  => trim(((string) ($order_info['firstname'] ?? '')) . ' ' . ((string) ($order_info['lastname'] ?? ''))),
            'email' => (string) ($order_info['email'] ?? ''),
            'phone' => (string) ($order_info['phone'] ?? ''),
        ];

        $customer = array_filter($customer, static function ($value) {
            return $value !== '';
        });

        $order_number = $order_id ? fn_mwl_planfix_format_order_id($order_id) : '';
        $user_id = isset($order_info['user_id']) ? (int) $order_info['user_id'] : 0;
        $user_data = isset($order_info['user_data']) && is_array($order_info['user_data'])
            ? $order_info['user_data']
            : call_user_func($this->userFetcher, $user_id);

        $payload = [
            'name'          => $task_name,
            'agency'        => (string) ($user_data['company'] ?? ''),
            'email'         => (string) ($order_info['email'] ?? ''),
            'employee_name' => trim(((string) ($user_data['firstname'] ?? '')) . ' ' . ((string) ($user_data['lastname'] ?? ''))),
            'telegram'      => $this->telegramService->resolveUserTelegram($user_id, $user_data),
            'description'   => $description,
            'order_id'      => $order_id ? (string) $order_id : '',
            'order_number'  => (string) $order_number,
            'order_url'     => $order_url,
            'company_id'    => $company_id,
            'direction'     => $this->settings->getDirectionDefault(),
            'status'        => (string) ($order_info['status'] ?? ''),
            'total'         => isset($order_info['total']) ? (float) $order_info['total'] : 0.0,
            'currency'      => $currency_code,
        ];

        if ($customer) {
            $payload['customer'] = $customer;
        }

        if (!empty($order_info['planfix_meta']) && is_array($order_info['planfix_meta'])) {
            $payload['planfix_meta'] = $order_info['planfix_meta'];
        }

        return $payload;
    }

    public function createTaskForOrder(int $order_id, array $order_info = []): array
    {
        if (!$order_info) {
            $order_info = call_user_func($this->orderFetcher, $order_id);
        }

        if (!$order_info) {
            return [
                'success' => false,
                'message' => __('mwl_xlsx.planfix_error_order_not_found'),
            ];
        }

        $payload = $this->buildSellTaskPayload($order_info);
        $company_id = isset($order_info['company_id']) ? (int) $order_info['company_id'] : 0;

        $response = $this->client->createTask($payload);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'message' => __('mwl_xlsx.planfix_error_mcp_request'),
                'response' => $response,
            ];
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $planfix_task_id = (string) ($data['taskId'] ?? '');
        $planfix_task_url = (string) ($data['url'] ?? '');

        if ($planfix_task_id === '') {
            return [
                'success' => false,
                'message' => __('mwl_xlsx.planfix_error_task_id_missing'),
                'response' => $response,
            ];
        }

        $planfix_object_type = (string) ($data['planfix_object_type'] ?? 'task');

        $extra = [
            'planfix_meta' => [
                'status_id'   => isset($data['status_id']) ? (string) $data['status_id'] : '',
                'direction'   => isset($data['direction']) ? (string) $data['direction'] : $payload['direction'],
            ],
            'created_via' => 'planfix_create_sell_task',
            'created_at'  => TIME,
        ];

        $this->linkRepository->upsert($company_id, 'order', $order_id, $planfix_object_type, $planfix_task_id, $extra);
        $link = $this->linkRepository->findByEntity($company_id, 'order', $order_id);

        if ($link) {
            $this->updateLinkExtra(
                $link,
                [],
                [
                    'last_push_at'     => TIME,
                    'last_payload_out' => json_encode(['planfix_create_sell_task' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );

            $link = $this->linkRepository->findByEntity($company_id, 'order', $order_id);
            if (is_array($link)) {
                $planfix_origin = (string) Registry::get('addons.mwl_xlsx.planfix_origin');
                $link['planfix_url'] = $this->buildObjectUrl($link, $planfix_origin);
                if ($planfix_task_url !== '' && empty($link['planfix_url'])) {
                    $link['planfix_url'] = $planfix_task_url;
                }
            }
        }

        return [
            'success'  => true,
            'message'  => __('mwl_xlsx.planfix_task_created', ['[id]' => $planfix_task_id]),
            'link'     => $link,
            'response' => $response,
        ];
    }

    public function bindTaskToOrder(int $order_id, int $company_id, string $planfix_task_id, string $planfix_object_type = 'task', array $meta = []): array
    {
        $planfix_task_id = trim($planfix_task_id);

        if ($planfix_task_id === '') {
            return [
                'success' => false,
                'message' => __('mwl_xlsx.planfix_error_task_id_missing'),
            ];
        }

        $planfix_object_type = $planfix_object_type !== '' ? $planfix_object_type : 'task';
        $existing = $this->linkRepository->findByPlanfix($planfix_object_type, $planfix_task_id, $company_id ?: null);

        if ($existing && (int) $existing['entity_id'] !== $order_id) {
            return [
                'success' => false,
                'message' => __('mwl_xlsx.planfix_task_already_bound', ['[order_id]' => (int) $existing['entity_id']]),
            ];
        }

        $extra = [
            'bound_manually' => TIME,
        ];

        if (isset($meta['planfix_meta']) && is_array($meta['planfix_meta'])) {
            $extra['planfix_meta'] = $meta['planfix_meta'];
            unset($meta['planfix_meta']);
        }

        $extra = array_merge($extra, $meta);

        $this->linkRepository->upsert($company_id, 'order', $order_id, $planfix_object_type, $planfix_task_id, $extra);
        $link = $this->linkRepository->findByEntity($company_id, 'order', $order_id);

        if ($link) {
            $link['planfix_url'] = $this->buildObjectUrl($link);
        }

        return [
            'success' => true,
            'message' => __('mwl_xlsx.planfix_task_bound', ['[id]' => $planfix_task_id]),
            'link'    => $link,
        ];
    }

    public function recordStatusSkip(int $order_id): void
    {
        $skip_orders = Registry::get('mwl_xlsx.planfix_status.skip_orders');
        if (!is_array($skip_orders)) {
            $skip_orders = [];
        }

        $skip_orders[$order_id] = TIME;
        Registry::set('mwl_xlsx.planfix_status.skip_orders', $skip_orders);
    }

    public function consumeStatusSkip(int $order_id): bool
    {
        $skip_orders = Registry::get('mwl_xlsx.planfix_status.skip_orders');
        if (!is_array($skip_orders) || !isset($skip_orders[$order_id])) {
            return false;
        }

        unset($skip_orders[$order_id]);
        Registry::set('mwl_xlsx.planfix_status.skip_orders', $skip_orders);

        return true;
    }

    public function syncOrderStatus(int $order_id, string $status_to, string $status_from, array $order_info): void
    {
        $order_id = (int) $order_id;
        if (!$order_id) {
            return;
        }

        if ($this->consumeStatusSkip($order_id)) {
            return;
        }

        $company_id = isset($order_info['company_id']) ? (int) $order_info['company_id'] : 0;
        $link = $this->linkRepository->findByEntity($company_id, 'order', $order_id);

        if (!$link || empty($link['planfix_object_id'])) {
            $creation_result = $this->createTaskForOrder($order_id, $order_info);
            if (empty($creation_result['success'])) {
                return;
            }

            $link = $creation_result['link'] ?? $this->linkRepository->findByEntity($company_id, 'order', $order_id);
            if (!$link || empty($link['planfix_object_id'])) {
                return;
            }
        }

        $planfix_object_id = (string) $link['planfix_object_id'];
        $planfix_object_type = isset($link['planfix_object_type']) && $link['planfix_object_type'] !== ''
            ? (string) $link['planfix_object_type']
            : 'task';

        $payloads = [];
        $status_payload = [
            'order_id'            => $order_id,
            'company_id'          => $company_id,
            'status_to'           => (string) $status_to,
            'status_from'         => (string) $status_from,
            'planfix_object_id'   => $planfix_object_id,
            'planfix_object_type' => $planfix_object_type,
        ];

        if (isset($order_info['total'])) {
            $status_payload['total'] = (float) $order_info['total'];
        }

        $payloads['update_sale_status'] = $status_payload;
        $this->client->updateSaleStatus($status_payload);

        if ($this->settings->shouldSyncComments()) {
            $comment = '';

            if (!empty($order_info['details'])) {
                $comment = (string) $order_info['details'];
            } elseif (!empty($order_info['notes'])) {
                $comment = (string) $order_info['notes'];
            }

            if ($comment !== '') {
                $comment_payload = [
                    'order_id'            => $order_id,
                    'company_id'          => $company_id,
                    'planfix_object_id'   => $planfix_object_id,
                    'planfix_object_type' => $planfix_object_type,
                    'comment'             => $comment,
                ];

                $payloads['append_sale_comment'] = $comment_payload;
                $this->client->appendSaleComment($comment_payload);
            }
        }

        if ($this->settings->shouldSyncPayments()) {
            $amount = null;

            if (isset($order_info['total_paid'])) {
                $amount = (float) $order_info['total_paid'];
            } elseif (isset($order_info['total'])) {
                $amount = (float) $order_info['total'];
            }

            if ($amount !== null) {
                $payment_payload = [
                    'order_id'            => $order_id,
                    'company_id'          => $company_id,
                    'planfix_object_id'   => $planfix_object_id,
                    'planfix_object_type' => $planfix_object_type,
                    'amount'              => $amount,
                ];

                if (!empty($order_info['secondary_currency'])) {
                    $payment_payload['currency'] = (string) $order_info['secondary_currency'];
                } elseif (!empty($order_info['primary_currency'])) {
                    $payment_payload['currency'] = (string) $order_info['primary_currency'];
                } elseif (!empty($order_info['currency'])) {
                    $payment_payload['currency'] = (string) $order_info['currency'];
                }

                $payloads['register_sale_payment'] = $payment_payload;
                $this->client->registerSalePayment($payment_payload);
            }
        }

        $summary_extra = [
            'last_outgoing_status' => [
                'status_to'   => (string) $status_to,
                'status_from' => (string) $status_from,
                'pushed_at'   => TIME,
            ],
        ];

        $this->updateLinkExtra(
            $link,
            $summary_extra,
            [
                'last_push_at'     => TIME,
                'last_payload_out' => json_encode($payloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function processIncomingOrderStatus(array $link, string $target_status): array
    {
        if ($target_status === '' || ($link['entity_type'] ?? '') !== 'order') {
            return [
                'success' => true,
                'message' => 'Link metadata updated',
            ];
        }

        $order_id = (int) ($link['entity_id'] ?? 0);
        if (!$order_id) {
            return [
                'success' => true,
                'message' => 'Link metadata updated',
            ];
        }

        $current_status = call_user_func($this->orderStatusFetcher, $order_id);

        if ($current_status === $target_status) {
            return [
                'success' => true,
                'message' => 'Order status already up to date',
            ];
        }

        $this->recordStatusSkip($order_id);
        $updated = call_user_func($this->orderStatusUpdater, $order_id, $target_status, (string) $current_status);

        if ($updated) {
            return [
                'success' => true,
                'message' => 'Order status updated',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to update order status',
        ];
    }
}
