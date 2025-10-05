<?php

namespace Tygh\Addons\MwlXlsx\Tests\Planfix;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\MwlXlsx\Planfix\IntegrationSettings;
use Tygh\Addons\MwlXlsx\Planfix\LinkRepository;
use Tygh\Addons\MwlXlsx\Planfix\PlanfixService;
use Tygh\Addons\MwlXlsx\Planfix\StatusMapRepository;
use Tygh\Addons\MwlXlsx\Planfix\WebhookHandler;
use Tygh\Addons\MwlXlsx\Planfix\WebhookResponse;

class WebhookHandlerTest extends TestCase
{
    public function testRejectsNonAllowlistedIp(): void
    {
        $settings = IntegrationSettings::fromArray([
            'webhook_allowlist_ips' => ['1.1.1.1'],
        ]);

        $linkRepository = $this->createMock(LinkRepository::class);
        $statusMapRepository = $this->createMock(StatusMapRepository::class);
        $planfixService = $this->createMock(PlanfixService::class);
        $planfixService->expects($this->never())->method('updateLinkExtra');

        $handler = new WebhookHandler($settings, $linkRepository, $statusMapRepository, $planfixService);

        $response = $handler->handleStatusWebhook(
            [
                'REQUEST_METHOD' => 'POST',
                'REMOTE_ADDR' => '2.2.2.2',
            ],
            '{}',
            []
        );

        $this->assertInstanceOf(WebhookResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getPayload()['success']);
    }

    public function testRequiresBasicAuthWhenCredentialsProvided(): void
    {
        $settings = IntegrationSettings::fromArray([
            'webhook_allowlist_ips' => ['1.1.1.1'],
            'webhook_basic_login' => 'user',
            'webhook_basic_password' => 'pass',
        ]);

        $handler = new WebhookHandler(
            $settings,
            $this->createMock(LinkRepository::class),
            $this->createMock(StatusMapRepository::class),
            $this->createMock(PlanfixService::class)
        );

        $response = $handler->handleStatusWebhook(
            [
                'REQUEST_METHOD' => 'POST',
                'REMOTE_ADDR' => '1.1.1.1',
            ],
            '{}',
            []
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayHasKey('WWW-Authenticate', $response->getHeaders());
    }

    public function testUpdatesMetadataAndProcessesStatus(): void
    {
        $settings = IntegrationSettings::fromArray([
            'webhook_allowlist_ips' => ['10.0.0.1'],
            'webhook_basic_login' => 'user',
            'webhook_basic_password' => 'pass',
        ]);

        $link = [
            'link_id' => 9,
            'entity_type' => 'order',
            'entity_id' => 55,
            'company_id' => 3,
        ];

        $linkRepository = $this->createMock(LinkRepository::class);
        $linkRepository
            ->expects($this->once())
            ->method('findByPlanfix')
            ->with('task', 'PF-5')
            ->willReturn($link);

        $statusMapRepository = $this->createMock(StatusMapRepository::class);
        $statusMapRepository
            ->expects($this->once())
            ->method('findLocalStatus')
            ->with(3, 'order', 'S1')
            ->willReturn(['entity_status' => 'C']);

        $planfixService = $this->createMock(PlanfixService::class);
        $planfixService
            ->expects($this->once())
            ->method('processIncomingOrderStatus')
            ->with($link, 'C')
            ->willReturn([
                'success' => true,
                'message' => 'Order status updated',
            ]);

        $planfixService
            ->expects($this->once())
            ->method('updateLinkExtra')
            ->with(
                $link,
                $this->callback(function (array $updates) {
                    $this->assertSame('S1', $updates['planfix_meta']['status_id']);
                    $this->assertSame('C', $updates['planfix_meta']['mapped_status']);
                    $this->assertSame('S1', $updates['last_incoming_status']['status_id']);
                    return true;
                })
            );

        $handler = new WebhookHandler($settings, $linkRepository, $statusMapRepository, $planfixService);

        $response = $handler->handleStatusWebhook(
            [
                'REQUEST_METHOD' => 'POST',
                'REMOTE_ADDR' => '10.0.0.1',
                'PHP_AUTH_USER' => 'user',
                'PHP_AUTH_PW' => 'pass',
            ],
            json_encode(['planfix_task_id' => 'PF-5', 'status_id' => 'S1']),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getPayload()['success']);
        $this->assertStringContainsString('Order status updated', $response->getPayload()['message']);
        $this->assertStringContainsString('status_id: S1', $response->getPayload()['message']);
    }
}
