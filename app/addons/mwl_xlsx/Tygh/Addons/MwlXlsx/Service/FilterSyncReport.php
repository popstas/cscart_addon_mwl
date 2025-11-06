<?php

namespace Tygh\Addons\MwlXlsx\Service;

class FilterSyncReport
{
    /** @var array<int, array{name: string, filter_id: int|null}> */
    private $created = [];

    /** @var array<int, array{name: string, filter_id: int|null}> */
    private $updated = [];

    /** @var array<int, string> */
    private $skipped = [];

    /** @var array<int, string> */
    private $errors = [];

    public function addCreated(string $name, ?int $filter_id = null): void
    {
        $this->created[] = [
            'name' => $name,
            'filter_id' => $filter_id,
        ];
    }

    public function addUpdated(string $name, ?int $filter_id = null): void
    {
        $this->updated[] = [
            'name' => $name,
            'filter_id' => $filter_id,
        ];
    }

    public function addSkipped(string $message): void
    {
        $this->skipped[] = $message;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * @return array<int, array{name: string, filter_id: int|null}>
     */
    public function getCreated(): array
    {
        return $this->created;
    }

    /**
     * @return array<int, array{name: string, filter_id: int|null}>
     */
    public function getUpdated(): array
    {
        return $this->updated;
    }

    /**
     * @return array<int, string>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getSummary(): array
    {
        return [
            'created' => count($this->created),
            'updated' => count($this->updated),
            'skipped' => count($this->skipped),
            'errors' => count($this->errors),
        ];
    }

    public function toArray(): array
    {
        return [
            'summary' => $this->getSummary(),
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
