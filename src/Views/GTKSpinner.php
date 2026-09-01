<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkSpinner as GtkSpinnerWidget;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Spinner;
use Surface\NativeWindows\Windowable;

/**
 * A Surface spinner over a GtkSpinner in the window's GtkFixed content.
 * GTK only animates it while it is mapped and spinning — start() and
 * stop() map one to one.
 */
class GTKSpinner extends Spinner
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        public readonly GtkSpinnerWidget $widget,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window);
    }

    protected function widget(): GtkWidget
    {
        return $this->widget;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applySpinning(bool $spinning): void
    {
        if ($spinning) {
            $this->widget->start();
        } else {
            $this->widget->stop();
        }
    }

    protected function applyBackground(Color $color): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->widget, $this->name, 'background-color', $color->toCss());
    }

    /** Removal also drops this view's rule block from the stylesheet. */
    protected function destroyNative(): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->forget($this->name);
        $this->fixed()->remove($this->widget());
    }
}
