<?php

namespace Venusian\GTK\Tests\Support;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;

/** A GtkFixed double that records placements. */
final class FakeFixed extends GtkFixed
{
    /** @var list<array{string, float, float}> */
    public array $log = [];

    public function __construct() {}

    public function __destruct() {}

    public function put(GtkWidget $widget, float $x, float $y): static
    {
        $this->log[] = ['put', $x, $y];

        return $this;
    }

    public function move(GtkWidget $widget, float $x, float $y): static
    {
        $this->log[] = ['move', $x, $y];

        return $this;
    }

    public function remove(GtkWidget $widget): static
    {
        $this->log[] = ['remove', 0.0, 0.0];

        return $this;
    }
}
