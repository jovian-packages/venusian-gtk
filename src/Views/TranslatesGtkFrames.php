<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Enums\GtkOrientation;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;

/**
 * Shared frame mechanics for widgets in the scaffold's GtkFixed: top-left
 * frames pass straight through, natural size comes from measure() before
 * any layout, and removal drops the container's ref — terminal.
 */
trait TranslatesGtkFrames
{
    /** The native widget this view fronts. */
    abstract protected function widget(): GtkWidget;

    /** The GtkFixed the widget sits in. */
    abstract protected function fixed(): GtkFixed;

    protected function applyFrame(int $x, int $y, int $width, int $height): void
    {
        $this->fixed()->move($this->widget(), (float) $x, (float) $y);
        $this->widget()->setSizeRequest($width, $height);
    }

    protected function measure(): array
    {
        // A size request floors measure(), so lift it before asking.
        $this->widget()->setSizeRequest(-1, -1);

        $horizontal = $this->widget()->measure(GtkOrientation::HORIZONTAL, -1);
        $vertical = $this->widget()->measure(GtkOrientation::VERTICAL, -1);

        return [(int) $horizontal['natural'], (int) $vertical['natural']];
    }

    protected function destroyNative(): void
    {
        $this->fixed()->remove($this->widget());
    }

    protected function applyVisible(bool $visible): void
    {
        $this->widget()->setVisible($visible);
    }
}
