<?php

declare(strict_types=1);

namespace Tarrou\Model;

final class DnsRecord
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $value,
        public readonly ?int $ttl = null,
        public readonly ?int $priority = null
    ) {
    }

    public static function fromArray(array $record): self
    {
        return new self(
            self::normalizeName((string) $record['name']),
            self::normalizeType((string) $record['type']),
            self::normalizeValue((string) $record['value']),
            isset($record['ttl']) ? (int) $record['ttl'] : null,
            isset($record['priority']) ? (int) $record['priority'] : null
        );
    }

    public function key(): string
    {
        return implode('|', [
            $this->name,
            $this->type,
            $this->value,
            (string) ($this->ttl ?? 0),
            (string) ($this->priority ?? 0),
        ]);
    }

    public function recordSetKey(): string
    {
        return $this->name . '|' . $this->type;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
            'ttl' => $this->ttl,
            'priority' => $this->priority,
        ];
    }

    private static function normalizeName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '@';
        }

        return strtolower(rtrim($trimmed, '.'));
    }

    private static function normalizeType(string $type): string
    {
        return strtoupper(trim($type));
    }

    private static function normalizeValue(string $value): string
    {
        return trim($value);
    }
}
