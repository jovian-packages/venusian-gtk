<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkToggleButton as GtkToggleButtonWidget;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Views\ToggleButton;
use Surface\NativeWindows\Windowable;

/**
 * A Surface toggle button over a GtkToggleButton. `toggled` fires after
 * the state settles; the applying flag keeps Surface's own setPressed()
 * from echoing back as mail.
 */
class GTKToggleButton extends ToggleButton
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        string $label,
        bool $pressed,
        public readonly GtkToggleButtonWidget $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $label, $pressed);

        $native->onToggled(function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireToggled($this->native->getActive());
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

    protected function applyLabel(string $label): void
    {
        $this->native->setLabel($label);
    }

    protected function applyPressed(bool $pressed): void
    {
        $this->applying = true;
        $this->native->setActive($pressed);
        $this->applying = false;
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
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

    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->native, $this->name, $property, $value);
    }
}
