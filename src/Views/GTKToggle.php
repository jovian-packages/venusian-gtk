<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkSwitch;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Bindings\Gtk\Runtime\Bridge;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Toggle;
use Surface\NativeWindows\Windowable;

/**
 * A Surface toggle over a GtkSwitch, listening on notify::active so the
 * state is settled when read. The applying flag keeps Surface's own
 * setOn() from echoing back as mail.
 */
class GTKToggle extends Toggle
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        bool $on,
        public readonly GtkSwitch $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $on);

        Bridge::connect($native->handle, 'notify::active', function (mixed ...$args): void {
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

    protected function applyOn(bool $on): void
    {
        $this->applying = true;
        $this->native->setActive($on);
        $this->applying = false;
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
    }

    /**
     * A switch draws no fill of its own — GTK owns the track's look, the
     * colour is ignored.
     */
    protected function applyBackground(Color $color): void {}
}
