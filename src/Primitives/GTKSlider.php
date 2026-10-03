<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSlider;

/**
 * A horizontal slider over GtkScale with its value label hidden. Values are continuous;
 * an arrow key moves a hundredth of the range and Page Up/Down a tenth, rescaled with every
 * range change. value-changed posts ValueChanged.
 */
class GTKSlider extends TKSlider implements GTKView
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param float $min
     * @param float $max
     * @param float $value
     * @throws WindowException When the name is not valid, the range is not finite with min < max, or the value is not finite.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, float $min, float $max, float $value)
    {
        parent::__construct($name, $window, $parent, $placement, $min, $max, $value);
        $this->adoptNative(\GtkScale::newWithRange(\GtkOrientation::HORIZONTAL, $this->min, $this->max, ($this->max - $this->min) / 100));
        $this->widgetScale()->setDrawValue(false);
        $this->applyRange($this->min, $this->max);
        $this->applyValue($this->value);
        $this->connect($this->native, 'value-changed', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeValueChanged($this->widgetScale()->getValue());
            $this->post(new ValueChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
        });
    }

    protected function applyValue(float $value): void
    {
        $this->quietly(fn () => $this->widgetScale()->setValue($value));
    }

    /**
     * GtkRange clamps its value into the new range itself; the abstract then writes its own
     * clamped value, so the echo is suppressed here too.
     *
     * @param float $min
     * @param float $max
     * @return void
     */
    protected function applyRange(float $min, float $max): void
    {
        $this->quietly(function () use ($min, $max): void {
            $this->widgetScale()->setRange($min, $max);
            $this->widgetScale()->setIncrements(($max - $min) / 100, ($max - $min) / 10);
        });
    }

    protected function widgetScale(): \GtkScale
    {
        /** @var \GtkScale */
        return $this->native;
    }
}
