<?php

namespace Jovian\Toolkits\GTK\Contracts\Primitives;

use GtkWidget;

/**
 * Every GTK primitive: its native widget, and the size request its container and its
 * own minimum size share.
 */
interface GTKView
{
    /**
     * The widget the primitive's container holds.
     * @return GtkWidget
     */
    public function native(): GtkWidget;

    /**
     * Write the widget's size request from its minimum size and, inside a fixed, its frame:
     * the larger of the two on each axis.
     * @return void
     */
    public function syncSizeRequest(): void;
}
