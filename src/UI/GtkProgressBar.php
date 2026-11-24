<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkAlign;
use WeakReference;
use GtkProgressBar as NativeProgressBar;
use Surface\Toolkits\UI\TKProgressBar;
use Surface\Contracts\Toolkits\UI\Align;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkProgressBar. GTK moves an indeterminate one only when pulsed, so a timeout does.
 */
class GtkProgressBar extends TKProgressBar
{
    use GtkNative {
        releaseNative as private releaseWidget;
        applyAlign as private alignWidget;
    }

    private const int PULSE_MS = 100;

    private ?int $pulsing = null;

    protected function bootstrapNative(): void
    {
        $bar = NativeProgressBar::new();
        $bar->setPulseStep(0.1);
        $this->adoptNative($bar);
        $bar->setValign(GtkAlign::CENTER);
        $this->applyFraction($this->fraction());
    }

    protected function applyFraction(?float $fraction): void
    {
        if (! is_null($fraction)) {
            $this->stopPulsing();
            $this->barNative()->setFraction($fraction);

            return;
        }
        if (! is_null($this->pulsing)) {
            return;
        }

        $self = WeakReference::create($this);
        $this->pulsing = g_timeout_add(self::PULSE_MS, static function () use ($self): bool {
            $bar = $self->get();
            if (is_null($bar) || $bar->isRemoved()) {
                return G_SOURCE_REMOVE;
            }
            $bar->barNative()->pulse();

            return G_SOURCE_CONTINUE;
        });
    }

    /** GTK draws the bar at the top of a taller slot, so FILL down it is the middle of it. */
    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        $this->alignWidget($horizontal, $vertical === Align::FILL ? Align::CENTER : $vertical);
    }

    protected function releaseNative(): void
    {
        $this->stopPulsing();
        $this->releaseWidget();
    }

    private function stopPulsing(): void
    {
        if (! is_null($this->pulsing)) {
            g_source_remove($this->pulsing);
            $this->pulsing = null;
        }
    }

    private function barNative(): NativeProgressBar
    {
        /** @var NativeProgressBar */
        return $this->native();
    }
}
