<?php

namespace Tygh\Addons\MwlXlsx\Tests\Telegram;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\MwlXlsx\Telegram\TelegramService;

class TelegramServiceTest extends TestCase
{
    public function testResolveUserTelegramReturnsValueFromUserInfo(): void
    {
        $service = $this->createService();

        $value = $service->resolveUserTelegram(123, ['telegram' => '@user']);

        $this->assertSame('@user', $value);
    }

    public function testResolveUserTelegramReadsProfileFieldById(): void
    {
        $service = $this->createService([
            'profile_fields_getter' => static function (string $section) {
                return [
                    [
                        [
                            'field_id'    => 100,
                            'description' => 'Telegram handle',
                        ],
                    ],
                ];
            },
        ]);

        $value = $service->resolveUserTelegram(1, ['fields' => [100 => [' @handle ']]]);

        $this->assertSame('@handle', $value);
    }

    public function testNormalizeChatId(): void
    {
        $service = $this->createService();

        $this->assertSame('@user', $service->normalizeChatId('user'));
        $this->assertSame('@user', $service->normalizeChatId('@user'));
        $this->assertSame('-12345', $service->normalizeChatId('-12345'));
        $this->assertSame('12345', $service->normalizeChatId('12345'));
        $this->assertSame('', $service->normalizeChatId('   '));
    }

    public function testGetChatIdByUsernameUsesDirectResponse(): void
    {
        $calls = [];
        $service = $this->createService([
            'http_get' => static function (string $url, array $params = [], array $options = []) use (&$calls) {
                $calls[] = [$url, $params, $options];

                return json_encode(['ok' => true, 'result' => ['id' => 987654321]]);
            },
        ]);

        $chat_id = $service->getChatIdByUsername('TOKEN', '@demo');

        $this->assertSame('987654321', $chat_id);
        $this->assertCount(1, $calls, 'Expected getUpdates to be skipped');
        $this->assertStringEndsWith('/getChat', $calls[0][0]);
    }

    public function testGetChatIdByUsernameFallsBackToUpdates(): void
    {
        $responses = [
            '/getChat'    => json_encode(['ok' => false]),
            '/getUpdates' => json_encode([
                'ok'     => true,
                'result' => [
                    [
                        'message' => [
                            'from' => ['username' => 'Demo', 'id' => 42],
                        ],
                    ],
                ],
            ]),
        ];

        $service = $this->createService([
            'http_get' => static function (string $url, array $params = [], array $options = []) use (&$responses) {
                foreach ($responses as $suffix => $response) {
                    if (substr($url, -strlen($suffix)) === $suffix) {
                        return $response;
                    }
                }

                return null;
            },
        ]);

        $chat_id = $service->getChatIdByUsername('TOKEN', 'demo');

        $this->assertSame('42', $chat_id);
    }

    public function testBuildVendorCommunicationMessages(): void
    {
        $translator = static function (string $key, array $params = [], ?string $lang_code = null) {
            return $key . ':' . json_encode($params);
        };

        $service = $this->createService(['translator' => $translator]);

        $messages = $service->buildVendorCommunicationMessages([
            'message_author_text'           => 'Author',
            'last_message_html'             => 'Body',
            'message_author_plain'          => 'Author',
            'message_body_plain'            => "Line 1\nLine 2",
            'order_line_html'               => 'Order #1',
            'admin_url'                     => 'https://example.com/admin',
            'admin_url_html'                => 'https://example.com/admin',
            'order_url'                     => 'https://example.com/order',
            'order_url_html'                => 'https://example.com/order',
            'http_host'                     => 'example.com',
            'order_lang_code'               => 'ru',
            'customer_telegram_display_html'=> '@demo',
        ]);

        $expected_admin = "Author: Body\n\n"
            . "Order #1\n"
            . 'mwl_xlsx_vc_admin_reply_link:{"[url]":"https:\/\/example.com\/admin","[host]":"example.com"}' . "\n"
            . 'mwl_xlsx_vc_customer_telegram_label:{"[handle]":"@demo"}';
        $expected_customer = "Author: Line 1\nLine 2\n\n"
            . "Order #1\n"
            . 'mwl_xlsx_vc_customer_reply_link:{"[url]":"https:\/\/example.com\/order","[host]":"example.com"}';

        $this->assertSame($expected_admin, $messages['admin_text']);
        $this->assertSame($expected_customer, $messages['customer_text']);
        $this->assertSame('Author: Body', $messages['admin_message_intro_html']);
    }

    public function testSendVendorCommunicationNotificationsSendsAdminMessage(): void
    {
        $posts = [];
        $translator = static function () {
            return 'Reply';
        };

        $service = $this->createService([
            'http_post'  => static function (string $url, array $data = [], array $options = []) use (&$posts) {
                $posts[] = [$url, $data, $options];

                return json_encode(['ok' => true]);
            },
            'translator' => $translator,
        ]);

        $result = $service->sendVendorCommunicationNotifications([
            'is_admin'      => false,
            'token'         => 'TOKEN',
            'chat_id'       => '@channel',
            'admin_text'    => 'Admin text',
            'customer_text' => 'Customer text',
            'admin_url'     => 'https://example.com/admin',
            'order_url'     => 'https://example.com/order',
            'order_lang_code' => 'en',
        ]);

        $this->assertNull($result['error_message']);
        $this->assertCount(1, $posts);
        [$url, $payload, $options] = $posts[0];
        $this->assertSame('https://api.telegram.org/botTOKEN/sendMessage', $url);
        $this->assertSame('@channel', $payload['chat_id']);
        $this->assertSame('Admin text', $payload['text']);
        $this->assertSame('HTML', $payload['parse_mode']);
        $this->assertArrayHasKey('reply_markup', $payload);
        $this->assertSame('mwl_xlsx.telegram_vc_request', $options['log_pre']);
    }

    public function testSendVendorCommunicationNotificationsSendsCustomerMessageForAdmin(): void
    {
        $posts = [];
        $translator = static function () {
            return 'Reply';
        };

        $service = $this->createService([
            'http_get'   => static function (string $url, array $params = [], array $options = []) {
                return json_encode(['ok' => true, 'result' => ['id' => 555]]);
            },
            'http_post'  => static function (string $url, array $data = [], array $options = []) use (&$posts) {
                $posts[] = [$url, $data, $options];

                return json_encode(['ok' => true]);
            },
            'translator' => $translator,
        ]);

        $result = $service->sendVendorCommunicationNotifications([
            'is_admin'                => true,
            'token'                   => 'TOKEN',
            'chat_id'                 => '@channel',
            'admin_text'              => 'Admin text',
            'customer_text'           => 'Customer text',
            'admin_url'               => 'https://example.com/admin',
            'order_url'               => 'https://example.com/order',
            'order_lang_code'         => 'en',
            'message_author_telegram' => '@customer',
        ]);

        $this->assertNull($result['error_message']);
        $this->assertCount(1, $posts, 'Only customer notification should be sent for admin messages');
        [$url, $payload] = $posts[0];
        $this->assertSame('https://api.telegram.org/botTOKEN/sendMessage', $url);
        $this->assertSame('555', $payload['chat_id']);
        $this->assertSame('Customer text', $payload['text']);
    }

    private function createService(array $overrides = []): TelegramService
    {
        $defaults = [
            'user_info_getter'      => static function (int $user_id): array {
                return [];
            },
            'profile_fields_getter' => static function (string $section): array {
                return [];
            },
            'http_get'              => static function (string $url, array $params = [], array $options = []) {
                return null;
            },
            'http_post'             => static function (string $url, array $data = [], array $options = []) {
                return null;
            },
            'translator'            => static function (string $key, array $params = [], ?string $lang_code = null) {
                return $key;
            },
        ];

        $config = array_merge($defaults, $overrides);

        return new TelegramService(
            $config['user_info_getter'],
            $config['profile_fields_getter'],
            $config['http_get'],
            $config['http_post'],
            $config['translator']
        );
    }
}
