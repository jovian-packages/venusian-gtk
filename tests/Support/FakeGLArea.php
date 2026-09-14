<?php

namespace Venusian\GTK\Tests\Support;

use Closure;
use Jovian\Bindings\Gtk\Gtk\GtkGLArea;

/** A GtkGLArea double: no GObject constructor, no registry, records what the twin asks of it. */
final class FakeGLArea extends GtkGLArea
{
    public int $renders_queued = 0;

    public int $made_current = 0;

    public ?Closure $render = null;

    /** @var list<array{int, int}> */
    public array $size_requests = [];

    /** @var list<bool> */
    public array $visibility = [];

    public function __construct(public int $width = 100, public int $height = 50, public int $scale = 1) {}

    public function __destruct() {}

    public function onRender(callable $callback): int
    {
        $this->render = $callback(...);

        return 1;
    }

    public function queueRender(): static
    {
        $this->renders_queued++;

        return $this;
    }

    public function makeCurrent(): static
    {
        $this->made_current++;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getScaleFactor(): int
    {
        return $this->scale;
    }

    public function setSizeRequest(int $width, int $height): static
    {
        $this->size_requests[] = [$width, $height];

        return $this;
    }

    public function setVisible(bool $visible): static
    {
        $this->visibility[] = $visible;

        return $this;
    }

    /** Test door: GTK's frame clock firing `render`. */
    public function fireRender(): bool
    {
        return ($this->render)(7, 9);
    }
}
