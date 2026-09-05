<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkScale;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Slider;
use Surface\NativeWindows\Windowable;

/**
 * A Surface slider over a horizontal GtkScale. value-changed streams while
 * the thumb drags; the applying flag keeps Surface's own writes from
 * echoing back as mail.
 */
class GTKSlider extends Slider
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        float $min,
        float $max,
        float $value,
        public readonly GtkScale $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $min, $max, $value);

        $native->onValueChanged(function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireChanged($this->native->getValue());
            }
        });
    }

    protected function widget(): GtkWidget
    {
        return $this->native;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyValue(float $value): void
    {
        $this->applying = true;
        $this->native->setValue($value);
        $this->applying = false;
    }

    protected function applyRange(float $min, float $max): void
    {
        $this->applying = true;
        $this->native->setRange($min, $max);
        $this->applying = false;
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
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
