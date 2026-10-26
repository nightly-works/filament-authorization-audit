<?php

namespace NightlyWorks\FilamentAuthorizationAudit;

final class Finding
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const NOTICE = 'notice';

    public function __construct(
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $file,
        public readonly int $line,
        public readonly string $message,
    ) {}

    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity,
            'file' => $this->file,
            'line' => $this->line,
            'message' => $this->message,
        ];
    }
}
