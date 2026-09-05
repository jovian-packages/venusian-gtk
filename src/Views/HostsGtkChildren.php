<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;

/**
 * A container twin whose native can hold children. The delegate's mints
 * put widgets into childFixed() instead of the window content when a view
 * is conjured into the container.
 */
interface HostsGtkChildren
{
    public function childFixed(): GtkFixed;
}
