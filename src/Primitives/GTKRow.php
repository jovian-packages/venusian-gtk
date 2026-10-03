<?php

namespace Jovian\Toolkits\GTK\Primitives;

use GtkBox;
use GtkOrientation;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKBoxContainer;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKRow;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A row over a horizontal GtkBox.
 */
class GTKRow extends TKRow implements GTKContainer
{
    use GTKPrimitive;
    use GTKBoxContainer;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param int $spacing
     * @param int $padding
     * @throws WindowException When the name is not valid, or spacing or padding is negative.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, int $spacing, int $padding)
    {
        parent::__construct($name, $window, $parent, $placement, $spacing, $padding);
        $this->adoptNative(GtkBox::new(GtkOrientation::HORIZONTAL, $spacing));
        $this->restyle();
    }
}
