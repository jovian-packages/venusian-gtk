<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKProgressBar;

/**
 * A progress bar over GtkProgressBar. GTK has no indeterminate mode, only pulse(): a null
 * fraction pulses the bar from a GLib timeout every PULSE_MS on the main context, the same
 * context every pump iterates, until a fraction is set or the bar is removed.
 */
class GTKProgressBar extends TKProgressBar implements GTKView
{
    use GTKPrimitive;

    public const int PULSE_MS = 100;

    public const float PULSE_STEP = 0.1;

    protected ?int $pulse_source = null;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param float|null $fraction
     * @throws WindowException When the name is not valid or the fraction is outside 0..1.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?float $fraction)
    {
        parent::__construct($name, $window, $parent, $placement, $fraction);
        $this->adoptNative(\GtkProgressBar::new());
        $this->widgetBar()->setPulseStep(self::PULSE_STEP);
        $this->applyFraction($fraction);
    }

    /**
     * Whether the bar is pulsing (fraction null).
     * @return bool
     */
    public function isPulsing(): bool
    {
        return ! is_null($this->pulse_source);
    }

    protected function applyFraction(?float $fraction): void
    {
        if (is_null($fraction)) {
            $this->pulse_source ??= g_timeout_add(self::PULSE_MS, function (): bool {
                $this->widgetBar()->pulse();

                return G_SOURCE_CONTINUE;
            });

            return;
        }

        $this->stopPulsing();
        $this->widgetBar()->setFraction($fraction);
    }

    protected function releaseNative(): void
    {
        $this->stopPulsing();
    }

    protected function stopPulsing(): void
    {
        if (! is_null($this->pulse_source)) {
            g_source_remove($this->pulse_source);
            $this->pulse_source = null;
        }
    }

    protected function widgetBar(): \GtkProgressBar
    {
        /** @var \GtkProgressBar */
        return $this->native;
    }
}
