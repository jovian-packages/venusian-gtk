<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkProgressBar as GtkProgressBarWidget;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\ProgressBar;
use Surface\NativeWindows\Windowable;

/**
 * A Surface progress bar over a GtkProgressBar — its fraction is already
 * 0..1, exactly Surface's promise.
 */
class GTKProgressBar extends ProgressBar
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        float $progress,
        public readonly GtkProgressBarWidget $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $progress);
    }

    protected function widget(): GtkWidget
    {
        return $this->native;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyProgress(float $progress): void
    {
        $this->native->setFraction($progress);
    }

    protected function applyBackground(Color $color): void
    {
        $this->css('background-color', $color->toCss());
    }

    /** Removal also drops this view's rule block from the stylesheet. */
    protected function destroyNative(): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->forget($this->name);
        $this->fixed()->remove($this->widget());
    }

    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->native, $this->name, $property, $value);
    }
}
