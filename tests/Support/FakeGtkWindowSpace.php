<?php

namespace Venusian\GTK\Tests\Support;

use Jovian\Venusian\GTK\Input\GtkWindowSpace;

/** The live windows are whatever the test says. */
final class FakeGtkWindowSpace implements GtkWindowSpace
{
    /** @param array<string, int> $windows window name → GtkWindow handle */
    public function __construct(public array $windows = []) {}

    public function windows(): array
    {
        return $this->windows;
    }
}
