<?php

namespace Venusian\GTK\Tests\Support;

use RuntimeException;

/** Records warnings, or throws on every one. */
final class FakeLog
{
    /** @var list<string> */
    public array $warnings = [];

    public function __construct(private readonly bool $broken = false) {}

    public function warning(string $message): void
    {
        if ($this->broken) {
            throw new RuntimeException('log down');
        }

        $this->warnings[] = $message;
    }
}
