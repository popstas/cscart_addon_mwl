<?php

namespace Tygh\Addons\MwlXlsx\Planfix;

class WebhookHandler
{
    /** @var IntegrationSettings */
    private $settings;

    /** @var LinkRepository */
    private $linkRepository;

    /** @var StatusMapRepository */
    private $statusMapRepository;

    /** @var PlanfixService */
    private $planfixService;

    public function __construct(
        IntegrationSettings $settings,
        LinkRepository $linkRepository,
        StatusMapRepository $statusMapRepository,
        PlanfixService $planfixService
    ) {
        $this->settings = $settings;
        $this->linkRepository = $linkRepository;
        $this->statusMapRepository = $statusMapRepository;
        $this->planfixService = $planfixService;
    }

    public function handleStatusWebhook(array $server, string $rawBody, array $fallbackPayload = []): WebhookResponse
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? ''));
        if ($method !== 'POST') {
            return new WebhookResponse(405, [
                'success' => false,
                'message' => 'Method Not Allowed',
            ]);
        }

        if (!$this->isIpAllowlisted((string) ($server['REMOTE_ADDR'] ?? ''))) {
            return new WebhookResponse(403, [
                'success' => false,
                'message' => 'IP is not allowed',
            ]);
        }

        if (!$this->validateBasicAuth($server)) {
            return new WebhookResponse(401, [
                'success' => false,
                'message' => 'Unauthorized',
            ], ['WWW-Authenticate' => 'Basic realm="Planfix webhook"']);
        }

        $payload = $this->parsePayload($rawBody, $fallbackPayload);
        $planfix_task_id = $this->extractPlanfixTaskId($payload);

        if ($planfix_task_id === '') {
            return new WebhookResponse(400, [
                'success' => false,
                'message' => 'planfix_task_id is required',
            ]);
        }

        $link = $this->linkRepository->findByPlanfix('task', $planfix_task_id);
        if (!$link) {
            return new WebhookResponse(404, [
                'success' => false,
                'message' => 'Link not found',
            ]);
        }

        $status_id = isset($payload['status_id']) ? (string) $payload['status_id'] : '';
        $extra_updates = [
            'planfix_meta' => [
                'status_id'   => $status_id,
                'updated_at'  => TIME,
            ],
            'last_incoming_status' => [
                'status_id'   => $status_id,
                'received_at' => TIME,
            ],
            'last_planfix_payload_in' => [
                'received_at' => TIME,
                'payload'     => $payload,
            ],
        ];

        $target_status = null;
        if ($status_id !== '') {
            $status_map = $this->statusMapRepository->findLocalStatus(
                (int) $link['company_id'],
                (string) $link['entity_type'],
                $status_id
            );

            if ($status_map && !empty($status_map['entity_status'])) {
                $target_status = (string) $status_map['entity_status'];
                $extra_updates['planfix_meta']['mapped_status'] = $target_status;
            }
        }

        $message_details = [];

        if ($status_id !== '') {
            $planfix_status_details = [];
            $planfix_status_details[] = 'id ' . $status_id;

            $planfix_status_message = 'Planfix status ' . implode(', ', $planfix_status_details);

            if ($target_status !== null && $status_id !== '') {
                $planfix_status_message .= ' -> entity status ' . $target_status;
            }

            $message_details[] = $planfix_status_message;
        }

        if ($target_status !== null && $status_id === '') {
            $message_details[] = 'Mapped entity status ' . $target_status;
        }

        $message_details[] = 'status_id: ' . $status_id;
        $message_details[] = 'target_status: ' . (string) $target_status;

        $result = $this->planfixService->processIncomingOrderStatus($link, (string) ($target_status ?? ''));
        $message = $result['message'] ?? 'Link metadata updated';
        $success = (bool) ($result['success'] ?? true);

        if ($message_details) {
            $message .= ': ' . implode('; ', $message_details);
        }

        $this->planfixService->updateLinkExtra($link, $extra_updates);

        return new WebhookResponse($success ? 200 : 500, [
            'success' => $success,
            'message' => $message,
        ]);
    }

    private function isIpAllowlisted(string $remote_ip): bool
    {
        $allowlist = $this->settings->getWebhookAllowlistIps();
        if (!$allowlist) {
            return true;
        }

        $remote_ip = trim($remote_ip);
        if ($remote_ip === '') {
            return false;
        }

        $normalized = array_map(static function ($ip) {
            return trim((string) $ip);
        }, $allowlist);

        return in_array($remote_ip, $normalized, true);
    }

    private function validateBasicAuth(array $server): bool
    {
        [$expected_login, $expected_password] = $this->settings->getWebhookBasicAuthCredentials();

        $expected_login = (string) $expected_login;
        $expected_password = (string) $expected_password;

        if ($expected_login === '' && $expected_password === '') {
            return true;
        }

        $credentials = $this->extractCredentials($server);
        if ($credentials === null) {
            return false;
        }

        [$login, $password] = $credentials;

        if (!function_exists('hash_equals')) {
            return $expected_login === $login && $expected_password === $password;
        }

        return hash_equals($expected_login, $login) && hash_equals($expected_password, $password);
    }

    private function parsePayload(string $rawBody, array $fallbackPayload): array
    {
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($fallbackPayload) ? $fallbackPayload : [];
    }

    private function extractPlanfixTaskId(array $payload): string
    {
        foreach (['planfix_task_id', 'task_id', 'id'] as $key) {
            if (!empty($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        return '';
    }

    private function extractCredentials(array $server): ?array
    {
        if (isset($server['PHP_AUTH_USER'])) {
            $login = (string) $server['PHP_AUTH_USER'];
            $password = isset($server['PHP_AUTH_PW']) ? (string) $server['PHP_AUTH_PW'] : '';

            return [$login, $password];
        }

        $headers = [];

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $header) {
            if (!empty($server[$header])) {
                $headers[] = (string) $server[$header];
            }
        }

        foreach ($headers as $header) {
            if (stripos($header, 'Basic ') === 0) {
                $decoded = base64_decode(substr($header, 6), true);
                if ($decoded !== false) {
                    $parts = explode(':', $decoded, 2);
                    if (count($parts) === 2) {
                        return [(string) $parts[0], (string) $parts[1]];
                    }
                }
            }
        }

        return null;
    }
}

class WebhookResponse
{
    /** @var int */
    private $statusCode;

    /** @var array */
    private $payload;

    /** @var array */
    private $headers;

    public function __construct(int $statusCode, array $payload, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->payload = $payload;
        $this->headers = $headers;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
