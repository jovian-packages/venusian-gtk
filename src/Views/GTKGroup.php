<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Group;
use Surface\NativeWindows\Windowable;

/**
 * A Surface group over its own GtkFixed inside the parent's fixed.
 * Children put into childFixed() carry group-relative coordinates
 * natively, and overflow is hidden so the group clips its subtree.
 */
class GTKGroup extends Group implements HostsGtkChildren
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        public readonly GtkFixed $surface,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window);
    }

    public function childFixed(): GtkFixed
    {
        return $this->surface;
    }

    protected function widget(): GtkWidget
    {
        return $this->surface;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    /** A container's natural size is whatever it was framed to. */
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

    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->surface, $this->name, $property, $value);
    }
}
