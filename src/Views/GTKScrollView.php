<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkScrolledWindow;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\ScrollView;
use Surface\NativeWindows\Windowable;

/**
 * A Surface scroll view over a GtkScrolledWindow whose child is a GtkFixed
 * sized to the content extent. Children put into the inner fixed carry
 * content-relative coordinates; GTK owns the scrollbars and starts at the
 * top on its own — no pinning owed here.
 */
class GTKScrollView extends ScrollView implements HostsGtkChildren
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        public readonly GtkScrolledWindow $scrolled,
        public readonly GtkFixed $inner,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window);
    }

    public function childFixed(): GtkFixed
    {
        return $this->inner;
    }

    protected function widget(): GtkWidget
    {
        return $this->scrolled;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyContentSize(int $width, int $height): void
    {
        $this->inner->setSizeRequest($width, $height);
    }

    /** A viewport has no natural size worth trusting; hug keeps the frame. */
    protected function measure(): array
    {
        return [$this->width, $this->height];
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

    /** Styling lands on the inner fixed — that is the visible sheet. */
    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->inner, $this->name, $property, $value);
    }
}
