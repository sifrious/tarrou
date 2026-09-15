<?php

declare(strict_types=1);

namespace Tarrou\Reconciliation;

final class LinearIssueRef
{
    public function __construct(
        public readonly string $id,
        public readonly string $identifier,
        public readonly string $title,
        public readonly ?string $projectId,
        public readonly string $workDedupeKey
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            identifier: (string) $data['identifier'],
            title: (string) $data['title'],
            projectId: isset($data['project_id']) ? (string) $data['project_id'] : null,
            workDedupeKey: (string) $data['work_dedupe_key']
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'title' => $this->title,
            'project_id' => $this->projectId,
            'work_dedupe_key' => $this->workDedupeKey,
        ];
    }
}
