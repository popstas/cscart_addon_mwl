<?php

namespace Tygh\Addons\MwlXlsx\Planfix;

use Tygh\Database\Connection;

class EventRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    public const EVENT_VENDOR_COMMUNICATION_MESSAGE = 'vendor_communication.message';

    /** @var \Tygh\Database\Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Registers raw event data.
     */
    public function logEvent(string $event_type, array $payload, int $company_id = 0, string $status = self::STATUS_PENDING, ?int $link_id = null, ?string $error = null): int
    {
        $data = [
            'company_id'   => $company_id,
            'event_type'   => $event_type,
            'status'       => $status,
            'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error'        => $error,
            'attempts'     => 0,
            'created_at'   => TIME,
            'updated_at'   => TIME,
            'processed_at' => null,
        ];

        if ($link_id !== null) {
            $data['link_id'] = $link_id;
        }

        return (int) $this->db->query('INSERT INTO ?:mwl_planfix_events ?e', $data);
    }

    public function logVendorCommunicationEvent($schema, array $receiver_search_conditions, ?int $link_id = null): int
    {
        $data = $schema->data ?? [];

        $payload = [
            'schema'                     => $data,
            'receiver_search_conditions' => $receiver_search_conditions,
        ];

        $company_id = (int) ($data['company_id'] ?? 0);

        return $this->logEvent(self::EVENT_VENDOR_COMMUNICATION_MESSAGE, $payload, $company_id, self::STATUS_PENDING, $link_id);
    }

    public function markProcessed(int $event_id, string $status = self::STATUS_PROCESSED, ?string $error = null): void
    {
        $data = [
            'status'       => $status,
            'updated_at'   => TIME,
            'processed_at' => TIME,
            'error'        => $error,
        ];

        if ($status !== self::STATUS_FAILED) {
            $data['error'] = null;
        }

        $this->db->query('UPDATE ?:mwl_planfix_events SET ?u WHERE event_id = ?i', $data, $event_id);
    }

    public function incrementAttempts(int $event_id): void
    {
        $this->db->query('UPDATE ?:mwl_planfix_events SET attempts = attempts + 1, updated_at = ?i WHERE event_id = ?i', TIME, $event_id);
    }
}
