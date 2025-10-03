<?php

namespace Tygh\Addons\MwlXlsx\Planfix;

use Tygh\Http;

class McpClient
{
    /** @var string */
    private $endpoint;

    /** @var string */
    private $authToken;

    /** @var int|null */
    private $lastStatusCode = null;

    public function __construct(string $endpoint, string $authToken)
    {
        $this->endpoint = rtrim($endpoint, '/');
        $this->authToken = $authToken;
    }

    public function getLastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    public function updateSaleStatus(array $payload): array
    {
        return $this->request('update_sale_status', $payload);
    }

    public function appendSaleComment(array $payload): array
    {
        return $this->request('append_sale_comment', $payload);
    }

    public function registerSalePayment(array $payload): array
    {
        return $this->request('register_sale_payment', $payload);
    }

    public function createTask(array $payload): array
    {
        return $this->request('planfix_create_sell_task', $payload);
    }

    public function bindTask(array $payload): array
    {
        return $this->request('bind_task', $payload);
    }

    public function createComment(array $payload): array
    {
        return $this->request('planfix_create_comment', $payload);
    }

    private function request(string $path, array $payload): array
    {
        if ($this->endpoint === '') {
            $this->lastStatusCode = null;

            return [
                'success' => false,
                'http_code' => 0,
                'body' => null,
                'data' => null,
                'error' => 'Empty MCP endpoint',
            ];
        }

        $url = $this->endpoint . '/' . ltrim($path, '/');

        $headers = [
            'Content-Type: application/json; charset=utf-8',
        ];

        if ($this->authToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        $options = [
            'headers' => $headers,
            'timeout' => 15,
        ];

        $response = Http::post(
            $url,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $options
        );

        $status = Http::getStatus();
        $this->lastStatusCode = is_int($status) ? $status : null;

        $decoded = json_decode((string) $response, true);

        return [
            'success' => $status >= 200 && $status < 300,
            'http_code' => $status,
            'body' => $response,
            'data' => is_array($decoded) ? $decoded : null,
        ];
    }
}
