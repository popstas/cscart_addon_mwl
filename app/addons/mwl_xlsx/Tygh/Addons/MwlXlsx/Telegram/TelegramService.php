<?php

namespace Tygh\Addons\MwlXlsx\Telegram;

use ArrayAccess;
use Tygh\Http;
use Tygh\Tygh;

class TelegramService
{
    /** @var callable */
    private $user_info_getter;

    /** @var callable */
    private $profile_fields_getter;

    /** @var callable */
    private $http_get;

    /** @var callable */
    private $http_post;

    /** @var callable */
    private $translator;

    /** @var array<int>|null */
    private $telegram_field_ids;

    public function __construct(
        ?callable $user_info_getter = null,
        ?callable $profile_fields_getter = null,
        ?callable $http_get = null,
        ?callable $http_post = null,
        ?callable $translator = null
    ) {
        $this->user_info_getter = $user_info_getter ?: static function (int $user_id) {
            return fn_get_user_info($user_id);
        };
        $this->profile_fields_getter = $profile_fields_getter ?: static function (string $section) {
            return fn_get_profile_fields($section);
        };
        $this->http_get = $http_get ?: static function (string $url, array $params = [], array $options = []) {
            return Http::get($url, $params, $options);
        };
        $this->http_post = $http_post ?: static function (string $url, array $data = [], array $options = []) {
            return Http::post($url, $data, $options);
        };
        $this->translator = $translator ?: static function (string $key, array $params = [], ?string $lang_code = null) {
            $lang = $lang_code !== null ? $lang_code : (defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en');

            return __($key, $params, $lang);
        };
    }

    public static function fromContainer(): self
    {
        $container = Tygh::$app ?? null;

        if ($container instanceof ArrayAccess && $container->offsetExists('addons.mwl_xlsx.telegram_service')) {
            return $container['addons.mwl_xlsx.telegram_service'];
        }

        return new self();
    }

    public function resolveUserTelegram(int $user_id = 0, ?array $user_info = null): string
    {
        if ($user_info === null) {
            if ($user_id <= 0) {
                return '';
            }

            $user_info = ($this->user_info_getter)($user_id);
        }

        if (!$user_info || !is_array($user_info)) {
            return '';
        }

        $value = $this->extractTelegram($user_info);

        if ($value !== '') {
            return $value;
        }

        $fields = isset($user_info['fields']) && is_array($user_info['fields']) ? $user_info['fields'] : [];

        if ($fields) {
            $value = $this->extractTelegram($fields);

            if ($value !== '') {
                return $value;
            }

            foreach ($this->getTelegramFieldIds() as $field_id) {
                if (!isset($fields[$field_id])) {
                    continue;
                }

                $value = $fields[$field_id];

                if (is_array($value)) {
                    $value = reset($value);
                }

                if ($value === null) {
                    continue;
                }

                $value = trim((string) $value);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    public function normalizeChatId(string $chat_id): string
    {
        $chat_id = trim($chat_id);

        if ($chat_id === '') {
            return '';
        }

        if ($chat_id[0] === '@' || $chat_id[0] === '-' || ctype_digit($chat_id)) {
            return $chat_id;
        }

        return '@' . ltrim($chat_id, '@');
    }

    public function formatHandle(string $handle): array
    {
        $handle = trim($handle);

        if ($handle === '') {
            return [
                'display' => '',
                'html'    => '',
            ];
        }

        $normalized = '@' . ltrim($handle, '@');

        return [
            'display' => $normalized,
            'html'    => htmlspecialchars($normalized, ENT_QUOTES, 'UTF-8'),
        ];
    }

    public function buildVendorCommunicationMessages(array $context): array
    {
        $message_author_text = (string) ($context['message_author_text'] ?? '');
        $last_message_html = (string) ($context['last_message_html'] ?? '');
        $message_author_plain = (string) ($context['message_author_plain'] ?? '');
        $message_body_plain = (string) ($context['message_body_plain'] ?? '');
        $order_line_html = (string) ($context['order_line_html'] ?? '');
        $admin_url_html = (string) ($context['admin_url_html'] ?? '');
        $admin_url = (string) ($context['admin_url'] ?? '');
        $order_url = (string) ($context['order_url'] ?? '');
        $order_url_html = (string) ($context['order_url_html'] ?? '');
        $http_host = (string) ($context['http_host'] ?? '');
        $order_lang_code = (string) ($context['order_lang_code'] ?? (defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en'));
        $customer_telegram_display_html = (string) ($context['customer_telegram_display_html'] ?? '');

        $admin_message_intro_html = $message_author_text . ': ' . $last_message_html;
        $admin_details_html = [];

        if ($order_line_html !== '') {
            $admin_details_html[] = $order_line_html;
        }

        $admin_details_html[] = ($this->translator)('mwl_xlsx_vc_admin_reply_link', ['[url]' => $admin_url_html, '[host]' => $http_host], $order_lang_code);

        if ($customer_telegram_display_html !== '') {
            $admin_details_html[] = ($this->translator)('mwl_xlsx_vc_customer_telegram_label', ['[handle]' => $customer_telegram_display_html], $order_lang_code);
        }

        $admin_message_parts_html = [$admin_message_intro_html];

        if ($admin_details_html) {
            $admin_message_parts_html[] = implode("\n", $admin_details_html);
        }

        $admin_text = implode("\n\n", $admin_message_parts_html);

        $customer_message_intro_plain = $message_author_plain . ': ' . $message_body_plain;
        $customer_message_lines = [nl2br(htmlspecialchars($customer_message_intro_plain, ENT_QUOTES, 'UTF-8'), false)];

        $customer_details_lines = [];

        if ($order_url !== '') {
            $customer_details_lines[] = $order_line_html;
            $customer_details_lines[] = ($this->translator)('mwl_xlsx_vc_customer_reply_link', ['[url]' => $order_url_html, '[host]' => $http_host], $order_lang_code);
        }

        if ($customer_details_lines) {
            $customer_message_lines[] = implode("\n", $customer_details_lines);
        }

        $customer_text = implode("\n\n", array_values(array_filter($customer_message_lines, static function ($line) {
            return $line !== '';
        })));

        return [
            'admin_text'                => $admin_text,
            'customer_text'             => $customer_text,
            'admin_message_intro_html'  => $admin_message_intro_html,
        ];
    }

    public function sendVendorCommunicationNotifications(array $context): array
    {
        $is_admin = !empty($context['is_admin']);
        $token = trim((string) ($context['token'] ?? ''));
        $chat_id = $this->normalizeChatId((string) ($context['chat_id'] ?? ''));
        $admin_text = (string) ($context['admin_text'] ?? '');
        $customer_text = (string) ($context['customer_text'] ?? '');
        $admin_url = (string) ($context['admin_url'] ?? '');
        $order_url = (string) ($context['order_url'] ?? '');
        $order_lang_code = (string) ($context['order_lang_code'] ?? (defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en'));
        $message_author_telegram = (string) ($context['message_author_telegram'] ?? '');

        $customer_chat_id = '';

        if ($is_admin && $message_author_telegram !== '' && $token !== '') {
            $customer_chat_id = $this->getChatIdByUsername($token, $message_author_telegram);
        }

        $error_message = null;
        $request_url = '';

        if (!$is_admin) {
            if ($token !== '' && $chat_id !== '') {
                $request_url = $this->buildSendMessageUrl($token);
                $message_payload = [
                    'chat_id'    => $chat_id,
                    'text'       => $admin_text,
                    'parse_mode' => 'HTML',
                ];

                if ($admin_url !== '') {
                    $message_payload['reply_markup'] = json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => ($this->translator)('mwl_xlsx_vc_button_reply', [], $order_lang_code),
                                    'url'  => $admin_url,
                                ],
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $resp_raw = ($this->http_post)($request_url, $message_payload, [
                    'timeout'    => 10,
                    'headers'    => [
                        'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                    ],
                    'log_pre'    => 'mwl_xlsx.telegram_vc_request',
                    'log_result' => true,
                ]);

                $resp = $resp_raw ? json_decode($resp_raw, true) : null;

                if (empty($resp['ok'])) {
                    $error_message = 'Failed to send Telegram notification for VC event: ' . print_r($resp, true);
                    error_log($error_message);
                }
            } else {
                $error_message = 'Telegram bot token or chat_id not configured for VC notifications';
                error_log($error_message);
            }
        } elseif ($token !== '') {
            $request_url = $this->buildSendMessageUrl($token);
        }

        if ($error_message === null && $token !== '' && $customer_chat_id !== '' && $customer_chat_id !== $chat_id) {
            $customer_payload = [
                'chat_id'    => $customer_chat_id,
                'text'       => $customer_text !== '' ? $customer_text : $admin_text,
                'parse_mode' => 'HTML',
            ];

            if ($order_url !== '') {
                $customer_payload['reply_markup'] = json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => ($this->translator)('mwl_xlsx_vc_button_reply', [], $order_lang_code),
                                'url'  => $order_url,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $customer_resp_raw = ($this->http_post)($request_url, $customer_payload, [
                'timeout'    => 10,
                'headers'    => [
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                ],
                'log_pre'    => 'mwl_xlsx.telegram_vc_customer',
                'log_result' => true,
            ]);

            $customer_resp = $customer_resp_raw ? json_decode($customer_resp_raw, true) : null;

            if (empty($customer_resp['ok'])) {
                error_log('Failed to send Telegram notification to customer: ' . print_r($customer_resp, true));
            }
        }

        return [
            'error_message'    => $error_message,
            'customer_chat_id' => $customer_chat_id,
        ];
    }

    public function getChatIdByUsername(string $token, string $username): string
    {
        $token = trim($token);
        $username = trim($username);

        if ($token === '' || $username === '') {
            return '';
        }

        $normalized_username = ltrim($username, '@');

        if ($normalized_username === '') {
            return '';
        }

        $username_with_at = '@' . $normalized_username;
        $base_url = "https://api.telegram.org/bot{$token}";
        $options_base = [
            'timeout'    => 10,
            'log_result' => true,
        ];

        $get_chat_response = ($this->http_get)(
            $base_url . '/getChat',
            ['chat_id' => $username_with_at],
            array_merge($options_base, ['log_pre' => 'mwl_xlsx.telegram_get_chat'])
        );

        if ($get_chat_response) {
            $decoded = json_decode($get_chat_response, true);

            if (!empty($decoded['ok']) && isset($decoded['result']['id'])) {
                return (string) $decoded['result']['id'];
            }
        }

        $updates_response = ($this->http_get)(
            $base_url . '/getUpdates',
            [],
            array_merge($options_base, ['log_pre' => 'mwl_xlsx.telegram_get_updates'])
        );

        if (!$updates_response) {
            return '';
        }

        $updates = json_decode($updates_response, true);

        if (empty($updates['ok']) || empty($updates['result']) || !is_array($updates['result'])) {
            return '';
        }

        $needle = $this->lowercase($normalized_username);

        foreach ($updates['result'] as $update) {
            if (!is_array($update)) {
                continue;
            }

            $messages = [];

            foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
                if (isset($update[$key]) && is_array($update[$key])) {
                    $messages[] = $update[$key];
                }
            }

            if (isset($update['callback_query']) && is_array($update['callback_query'])) {
                $callback_query = $update['callback_query'];

                if (isset($callback_query['message']) && is_array($callback_query['message'])) {
                    $messages[] = $callback_query['message'];
                }

                if (isset($callback_query['from']) && is_array($callback_query['from'])) {
                    $messages[] = ['from' => $callback_query['from']];
                }
            }

            foreach ($messages as $message) {
                if (!is_array($message)) {
                    continue;
                }

                if (isset($message['from']) && is_array($message['from'])) {
                    $from = $message['from'];

                    if (isset($from['username']) && $this->lowercase((string) $from['username']) === $needle && isset($from['id'])) {
                        return (string) $from['id'];
                    }
                }

                if (isset($message['chat']) && is_array($message['chat'])) {
                    $chat = $message['chat'];

                    if (isset($chat['username']) && $this->lowercase((string) $chat['username']) === $needle && isset($chat['id'])) {
                        return (string) $chat['id'];
                    }
                }
            }
        }

        return '';
    }

    private function extractTelegram(array $data): string
    {
        foreach (['telegram', 'telegram_id', 'telegram_handle', 'tg', 'telegram_username'] as $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $value = $data[$key];

            if (is_array($value)) {
                $value = reset($value);
            }

            if ($value === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function getTelegramFieldIds(): array
    {
        if ($this->telegram_field_ids !== null) {
            return $this->telegram_field_ids;
        }

        $profile_fields = ($this->profile_fields_getter)('ALL');
        $ids = [];

        foreach ($profile_fields as $section_fields) {
            if (!is_array($section_fields)) {
                continue;
            }

            foreach ($section_fields as $field) {
                if (empty($field['field_id'])) {
                    continue;
                }

                $field_id = (int) $field['field_id'];
                $field_name = isset($field['field_name']) ? $this->lowercase(trim((string) $field['field_name'])) : '';
                $description = isset($field['description']) ? $this->lowercase(trim((string) $field['description'])) : '';

                if (in_array($field_name, ['telegram', 'telegram_id', 'telegram_handle', 'tg', 'telegram_username'], true)) {
                    $ids[] = $field_id;
                    continue;
                }

                if ($description !== '' && strpos($description, 'telegram') !== false) {
                    $ids[] = $field_id;
                }
            }
        }

        $this->telegram_field_ids = array_values(array_unique($ids));

        return $this->telegram_field_ids;
    }

    private function buildSendMessageUrl(string $token): string
    {
        return "https://api.telegram.org/bot{$token}/sendMessage";
    }

    private function lowercase(string $value): string
    {
        if (function_exists('fn_strtolower')) {
            return fn_strtolower($value);
        }

        return strtolower($value);
    }
}
