<?php

namespace Tygh\Addons\MwlXlsx\Tests\Planfix;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\MwlXlsx\Planfix\IntegrationSettings;
use Tygh\Addons\MwlXlsx\Planfix\LinkRepository;
use Tygh\Addons\MwlXlsx\Planfix\McpClient;
use Tygh\Addons\MwlXlsx\Planfix\PlanfixService;
use Tygh\Addons\MwlXlsx\Telegram\TelegramService;
use Tygh\Registry;

class PlanfixServiceTest extends TestCase
{
    public function testCreateTaskForOrderSuccess(): void
    {
        Registry::set('addons.mwl_xlsx.planfix_origin', 'https://planfix.test');

        $settings = IntegrationSettings::fromArray([
            'mcp_endpoint'       => 'https://mcp.example.com',
            'mcp_auth_token'     => 'token',
            'direction_default'  => 'default',
        ]);

        $linkRepository = $this->createMock(LinkRepository::class);
        $client = $this->createMock(McpClient::class);
        $telegram = $this->createMock(TelegramService::class);

        $order_id = 100;
        $company_id = 7;
        $order_info = [
            'order_id'  => $order_id,
            'company_id'=> $company_id,
            'email'     => 'customer@example.com',
            'status'    => 'P',
            'total'     => 150.50,
            'currency'  => 'USD',
            'products'  => [
                ['product' => 'Product A', 'amount' => 2, 'subtotal' => 99.99],
            ],
            'user_id'   => 5,
            'firstname' => 'John',
            'lastname'  => 'Doe',
            'planfix_meta' => ['source' => 'test'],
        ];

        $user_data = [
            'company'   => 'Acme',
            'firstname' => 'John',
            'lastname'  => 'Doe',
        ];

        $linkRepository
            ->expects($this->once())
            ->method('upsert')
            ->with(
                $company_id,
                'order',
                $order_id,
                'task',
                'PF-1',
                $this->callback(static function ($extra) {
                    return isset($extra['planfix_meta']['direction']) && $extra['created_via'] === 'planfix_create_sell_task';
                })
            );

        $linkInitial = [
            'link_id' => 42,
            'planfix_object_id' => 'PF-1',
            'planfix_object_type' => 'task',
            'company_id' => $company_id,
            'entity_id' => $order_id,
            'extra' => null,
        ];

        $linkFinal = [
            'link_id' => 42,
            'planfix_object_id' => 'PF-1',
            'planfix_object_type' => 'task',
            'company_id' => $company_id,
            'entity_id' => $order_id,
        ];

        $findCalls = 0;
        $testCase = $this;
        $linkRepository
            ->expects($this->exactly(2))
            ->method('findByEntity')
            ->willReturnCallback(function ($company, $type, $entity) use (&$findCalls, $company_id, $order_id, $linkInitial, $linkFinal, $testCase) {
                $testCase->assertSame($company_id, $company);
                $testCase->assertSame('order', $type);
                $testCase->assertSame($order_id, $entity);

                $findCalls++;

                return $findCalls === 1 ? $linkInitial : $linkFinal;
            });

        $linkRepository
            ->expects($this->once())
            ->method('update')
            ->with(
                42,
                $this->callback(function (array $data) {
                    $this->assertArrayHasKey('last_payload_out', $data);
                    $payloads = json_decode($data['last_payload_out'], true);
                    $this->assertArrayHasKey('planfix_create_sell_task', $payloads);
                    return isset($data['extra']) && is_array($data['extra']);
                })
            );

        $client
            ->expects($this->once())
            ->method('createTask')
            ->with($this->callback(static function (array $payload) {
                return $payload['direction'] === 'default' && $payload['email'] === 'customer@example.com';
            }))
            ->willReturn([
                'success' => true,
                'data' => [
                    'taskId' => 'PF-1',
                    'planfix_object_type' => 'task',
                    'url' => 'https://planfix.example.com/task/PF-1',
                    'status_id' => 'STATUS',
                ],
            ]);

        $telegram
            ->expects($this->once())
            ->method('resolveUserTelegram')
            ->with(5, $user_data)
            ->willReturn('@john');

        $service = new PlanfixService(
            $linkRepository,
            $client,
            $settings,
            $telegram,
            null,
            static function (int $user_id) use ($user_data) {
                return $user_data;
            },
            static function (int $order_id): string {
                return 'https://example.com/orders/' . $order_id;
            }
        );

        $result = $service->createTaskForOrder($order_id, $order_info);

        $this->assertTrue($result['success']);
        $this->assertSame('mwl_xlsx.planfix_task_created', $result['message']);
        $this->assertSame('https://planfix.test/task/PF-1', $result['link']['planfix_url']);
        $this->assertSame('PF-1', $result['link']['planfix_object_id']);
    }

    public function testSyncOrderStatusSendsPayloads(): void
    {
        $settings = IntegrationSettings::fromArray([
            'mcp_endpoint'      => 'https://mcp.example.com',
            'mcp_auth_token'    => 'token',
            'direction_default' => 'default',
            'sync_comments'     => 'Y',
            'sync_payments'     => 'Y',
        ]);

        $link = [
            'link_id' => 10,
            'planfix_object_id' => 'PF-2',
            'planfix_object_type' => 'task',
            'company_id' => 3,
            'entity_id' => 77,
            'extra' => null,
        ];

        $linkRepository = $this->createMock(LinkRepository::class);
        $linkRepository
            ->expects($this->once())
            ->method('findByEntity')
            ->with(3, 'order', 77)
            ->willReturn($link);

        $linkRepository
            ->expects($this->once())
            ->method('update')
            ->with(
                10,
                $this->callback(function (array $data) {
                    $payloads = json_decode($data['last_payload_out'], true);
                    $this->assertArrayHasKey('update_sale_status', $payloads);
                    $this->assertArrayHasKey('append_sale_comment', $payloads);
                    $this->assertArrayHasKey('register_sale_payment', $payloads);
                    return true;
                })
            );

        $client = $this->createMock(McpClient::class);
        $client->expects($this->once())->method('updateSaleStatus');
        $client->expects($this->once())->method('appendSaleComment');
        $client->expects($this->once())->method('registerSalePayment');

        $telegram = $this->createMock(TelegramService::class);

        $service = new PlanfixService(
            $linkRepository,
            $client,
            $settings,
            $telegram
        );

        $order_info = [
            'company_id' => 3,
            'details' => 'Test comment',
            'total_paid' => 200.0,
            'secondary_currency' => 'USD',
        ];

        $service->syncOrderStatus(77, 'C', 'O', $order_info);
    }

    public function testRecordAndConsumeStatusSkip(): void
    {
        $settings = IntegrationSettings::fromArray([]);
        $linkRepository = $this->createMock(LinkRepository::class);
        $client = $this->createMock(McpClient::class);
        $telegram = $this->createMock(TelegramService::class);

        $service = new PlanfixService($linkRepository, $client, $settings, $telegram);

        $order_id = 501;

        $service->recordStatusSkip($order_id);
        $this->assertTrue($service->consumeStatusSkip($order_id));
        $this->assertFalse($service->consumeStatusSkip($order_id));
    }
}
