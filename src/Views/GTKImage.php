<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkPicture;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Image;
use Surface\NativeWindows\Windowable;

/**
 * A Surface image over a GtkPicture in the window's GtkFixed content,
 * content-fit CONTAIN — the frame is layout, the aspect ratio is the
 * picture's. The delegate mints it able to shrink, or a big picture
 * would floor measure() at its full size and refuse the frame.
 */
class GTKImage extends Image
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        ?string $path,
        public readonly GtkPicture $widget,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $path);
    }

    protected function widget(): GtkWidget
    {
        return $this->widget;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyPath(string $path): void
    {
        $this->widget->setFilename($path);
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
