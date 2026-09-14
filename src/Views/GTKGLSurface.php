<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkGLArea;
use Surface\Contracts\Drawing\GLSurface;

/**
 * The context lender over a GtkGLArea. makeCurrent() is harmless inside
 * `render` (GTK already did it) and is what release() needs outside it.
 * present() is a no-op: GTK swaps when the render signal returns.
 */
final class GTKGLSurface implements GLSurface
{
    public function __construct(public readonly GtkGLArea $area) {}

    public function makeCurrent(): void
    {
        $this->area->makeCurrent();
    }

    public function present(): void {}

    public function drawableSize(): array
    {
        $scale = max(1, $this->area->getScaleFactor());

        return [max(1, $this->area->getWidth() * $scale), max(1, $this->area->getHeight() * $scale)];
    }
}
