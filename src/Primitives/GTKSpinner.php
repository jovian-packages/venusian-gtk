<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSpinner;

/**
 * A spinner over GtkSpinner, which animates on GTK's frame clock while spinning.
 */
class GTKSpinner extends TKSpinner implements GTKView
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $this->adoptNative(\GtkSpinner::new());
    }

    protected function applySpinning(bool $spinning): void
    {
        $this->widgetSpinner()->setSpinning($spinning);
    }

    protected function widgetSpinner(): \GtkSpinner
    {
        /** @var \GtkSpinner */
        return $this->native;
    }
}
