<?php

namespace Venusian\GTK\Tests\Support;

use Closure;

/** The two container calls this package makes: bound() and resolving an abstract. */
final class FakeApp
{
    /** @param array<string, Closure(): object> $bindings abstract → factory */
    public function __construct(public array $bindings = []) {}

    public function bound(string $abstract): bool
    {
        return array_key_exists($abstract, $this->bindings);
    }

    public function make(string $abstract): object
    {
        return ($this->bindings[$abstract])();
    }
}
