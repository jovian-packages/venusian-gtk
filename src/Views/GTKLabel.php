<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Enums\GtkJustification;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkLabel as GtkLabelWidget;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Surface\Contracts\NativeWindows\Views\TextAlignment;
use Surface\NativeWindows\Views\Label;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Windowable;

/**
 * A Surface label over a GtkLabel widget sitting in the window's GtkFixed content.
 *
 * GtkFixed is top-left already, so frames pass straight through: move() for
 * the origin, a size request for the extent. Natural size comes from
 * measure(), which answers before the first layout — no waiting on a pump.
 */
class GTKLabel extends Label
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        string $text,
        public readonly GtkLabelWidget $widget,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $text);
    }



    protected function widget(): GtkWidget
    {
        return $this->widget;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyText(string $text): void
    {
        $this->widget->setText($text);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        [$justify, $xalign] = match ($alignment) {
            TextAlignment::LEFT => [GtkJustification::LEFT, 0.0],
            TextAlignment::CENTER => [GtkJustification::CENTER, 0.5],
            TextAlignment::RIGHT => [GtkJustification::RIGHT, 1.0],
        };

        // Justify covers multi-line; xalign is what centres a single line
        // inside a frame wider than the text.
        $this->widget->setJustify($justify);
        $this->widget->setXalign($xalign);
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

    protected function applyWrap(int $width): void
    {
        $this->widget->setWrap(true);
    }

    /**
     * GTK's own layout answer: the natural height measured for that width.
     */
    protected function measureWrappedHeight(int $width): int
    {
        // A size request floors measure(), so lift it before asking;
        // relayout() reinstates it through applyFrame() right after.
        $this->widget->setSizeRequest(-1, -1);

        $vertical = $this->widget->measure(\Jovian\Bindings\Gtk\Enums\GtkOrientation::VERTICAL, $width);

        return (int) $vertical['natural'];
    }

    /** One declaration into this window's stylesheet, keyed to this view. */
    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->widget, $this->name, $property, $value);
    }
}
