<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkSeparator as GtkSeparatorWidget;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Separator;
use Surface\NativeWindows\Windowable;

/**
 * A Surface separator over a GtkSeparator minted in the orientation the
 * conjure-time aspect decided — GTK cannot flip one after.
 */
class GTKSeparator extends Separator
{
    use TranslatesGtkFrames;

    public function __construct(
        string $name,
        Windowable $window,
        bool $horizontal,
        public readonly GtkSeparatorWidget $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $horizontal);
    }

    protected function widget(): GtkWidget
    {
        return $this->native;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    /** A separator's natural size is a hairline along its axis. */
    protected function measure(): array
    {
        return $this->horizontal ? [$this->width, 1] : [1, $this->height];
    }

    /**
     * The line's colour is the theme's — GTK has no honest per-separator
     * tint worth faking, so the colour is ignored.
     */
    protected function applyBackground(Color $color): void {}
}
