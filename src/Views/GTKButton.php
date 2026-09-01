<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkButton as GtkButtonWidget;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Surface\NativeWindows\Views\Button;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Windowable;

/**
 * A Surface button over a GtkButton in the scaffold's GtkFixed. The
 * `clicked` signal lands in fireClick(), so the sketch's hook runs inside
 * the pump that delivered the click.
 */
class GTKButton extends Button
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        string $label,
        public readonly GtkButtonWidget $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $label);

        $native->onClicked(fn (mixed ...$args) => $this->fireClick());
    }

    protected function widget(): GtkWidget
    {
        return $this->native;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyLabel(string $label): void
    {
        $this->native->setLabel($label);
    }

    protected function applyTextColor(Color $color): void
    {
        $this->css('color', $color->toCss());
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->css('font-size', "{$font->size}px");
        $this->css('font-weight', (string) $font->weight->toCssWeight());
        if (! is_null($font->family)) {
            $this->css('font-family', $font->family);
        }
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

    /** One declaration into this window's stylesheet, keyed to this view. */
    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->native, $this->name, $property, $value);
    }
}
