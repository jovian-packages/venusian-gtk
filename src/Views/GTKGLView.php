<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Surface\Contracts\Drawing\Executor;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\NativeWindows\Views\GPUView;
use Surface\NativeWindows\Windowable;

/**
 * A Surface GPU region over a GtkGLArea. This twin drives its own frames:
 * the tick only queues a render, and GTK's frame clock calls back into
 * handleRender() — so the draw hook runs inside the pump, the way onClick
 * does. This package never imports an OpenGL symbol.
 */
class GTKGLView extends GPUView
{
    use TranslatesGtkFrames {
        applyFrame as protected gtkApplyFrame;
        measure as protected gtkMeasure;
    }

    public function __construct(
        string $name,
        Windowable $window,
        GPUEngine $engine,
        Executor $executor,
        float $scale,
        public readonly GTKGLSurface $surface,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $engine, $executor, $scale);

        $this->surface->area->onRender(fn (int $sender, int $context): bool => $this->handleRender());
    }

    protected function widget(): GtkWidget
    {
        return $this->surface->area;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    public function drivesOwnFrames(): bool
    {
        return true;
    }

    protected function queueNativeFrame(): void
    {
        $this->surface->area->queueRender();
    }

    /**
     * GTK's `render`: one Surface frame — or, when Surface skips (hidden,
     * hookless, nothing pending), the clear colour alone, because GTK will
     * show whatever is in the buffer. Always true: we drew.
     */
    public function handleRender(): bool
    {
        if (! $this->renderFrame() && $this->executor->beginFrame($this->clear_color)) {
            $this->executor->endFrame();
        }

        return true;
    }

    /** The trait's move + size request, then the executor in pixels. */
    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        $this->gtkApplyFrame($x, $y, $width, $height);

        $scale = (float) max(1, $this->surface->area->getScaleFactor());
        $this->setScale($scale);
        $this->executor->resize((int) round($width * $scale), (int) round($height * $scale));
    }

    /** A GPU region has no natural size; the trait's measure() must not win. */
    protected function measure(): array
    {
        return [$this->width, $this->height];
    }
}
