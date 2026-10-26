<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\ScrollChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKScrollBar;

/**
 * A GtkScrollbar over a GtkAdjustment: lower 0, upper max(maximum, page), page size and page
 * increment the page, step increment the step, written whole with configure() under the
 * applying guard. value-changed outside the guard is the user's (a drag, a track click, a key):
 * the value rounded posts ScrollChanged (latest-wins). GtkRange scrolls by its own amount on a
 * wheel, so a capture-phase scroll controller on the bar moves step per line instead (a
 * precise wheel one line per 10 points, the remainder kept) and stops the event.
 */
class GTKScrollBar extends TKScrollBar implements GTKView
{
    use GTKPrimitive;

    /** A precise wheel's points per line. */
    private const float LINE_POINTS = 10.0;

    protected \GtkAdjustment $adjustment;

    protected \GtkEventControllerScroll $wheel;

    /** A precise wheel's points not yet a whole line. */
    private float $wheel_rest = 0.0;

    /**
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $vertical)
    {
        parent::__construct($name, $window, $parent, $placement, $vertical);
        $this->adjustment = \GtkAdjustment::new(0.0, 0.0, 0.0, 1.0, 0.0, 0.0);
        $this->adoptNative(\GtkScrollbar::new($vertical ? \GtkOrientation::VERTICAL : \GtkOrientation::HORIZONTAL, $this->adjustment));
        $this->connect($this->adjustment, 'value-changed', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeMoved((int) round($this->adjustment->getValue()));
            $this->post(new ScrollChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
        });

        $this->wheel = \GtkEventControllerScroll::new(GTK_EVENT_CONTROLLER_SCROLL_BOTH_AXES);
        $this->wheel->setPropagationPhase(\GtkPropagationPhase::CAPTURE);
        $this->connect($this->wheel, 'scroll', function (\GtkEventControllerScroll $controller, float $dx, float $dy): bool {
            return $this->wheeled($this->vertical || $dx === 0.0 ? $dy : $dx, $controller->getUnit() === \GdkScrollUnit::SURFACE);
        });
        $this->native->addController($this->wheel);
    }

    public function native(): \GtkScrollbar
    {
        /** @var \GtkScrollbar */
        return $this->native;
    }

    /**
     * A wheel over the bar: step per line, the value written as the user's (it posts).
     *
     * @param float $delta lines, or points when precise; positive toward the end of the range
     * @return bool whether the bar took it
     */
    public function wheeled(float $delta, bool $precise): bool
    {
        if (! $this->native->isSensitive()) {
            return false;
        }
        if ($precise) {
            $points = $this->wheel_rest + $delta;
            $lines = (int) ($points / self::LINE_POINTS);
            $this->wheel_rest = $points - $lines * self::LINE_POINTS;
        } else {
            $lines = (int) round($delta);
        }
        if ($lines !== 0) {
            $this->adjustment->setValue((float) max(0, min(max(0, $this->maximum - $this->page), $this->value + $lines * $this->step)));
        }

        return true;
    }

    /** The posting goes latest-wins: a drag posts on every step. */
    protected function post(object $mail): void
    {
        if (! $this->applying && $this->native->isSensitive()) {
            $this->session()->postLatest("view.scroll-changed.{$this->window->name()}.{$this->path()}", $mail);
        }
    }

    protected function applyRange(int $maximum, int $page, int $value): void
    {
        $this->configure($value);
    }

    protected function applyStep(int $line): void
    {
        $this->configure($this->value);
    }

    protected function applyValue(int $value): void
    {
        $this->configure($value);
    }

    private function configure(int $value): void
    {
        $this->quietly(fn () => $this->adjustment->configure((float) $value, 0.0, (float) max($this->maximum, $this->page), (float) $this->step, (float) $this->page, (float) $this->page));
    }
}
