<?php

namespace App\Services\ConfigurationImport;

/**
 * What an import would do (preview) or did (import): one row per element,
 * the errors that block it, the warnings that do not, and what the operator
 * still has to complete.
 */
final class ImportReport
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const NEW_VERSION = 'new_version';

    public const UNCHANGED = 'unchanged';

    public const SKIPPED = 'skipped';

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @var list<array{element: string, action: string, note: ?string, changes: array<string, array{old: mixed, new: mixed}>}>
     */
    public array $rows = [];

    /** @var list<string> */
    public array $pending = [];

    /** Whether the changes were written (an import that passed), not just simulated. */
    public bool $applied = false;

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function row(string $element, string $action, array $changes = [], ?string $note = null): void
    {
        $this->rows[] = ['element' => $element, 'action' => $action, 'note' => $note, 'changes' => $changes];
    }

    public function pending(string $item): void
    {
        $this->pending[] = $item;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasChanges(): bool
    {
        return collect($this->rows)->contains(fn (array $row) => in_array($row['action'], [self::CREATE, self::UPDATE, self::NEW_VERSION], true));
    }

    /** Rows without their texts, for the log. */
    public function summary(): array
    {
        return collect($this->rows)->map(fn (array $row) => $row['element'].': '.$row['action'])->all();
    }
}
