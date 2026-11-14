<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use GtkBox;
use GtkOrientation;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKBoxContainer;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKColumn;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A column over a vertical GtkBox.
 */
class GTKColumn extends TKColumn implements GTKContainer
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
     * @throws ToolkitRuntimeException When the name is not valid, or spacing or padding is negative.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, int $spacing, int $padding)
    {
        parent::__construct($name, $window, $parent, $placement, $spacing, $padding);
        $this->adoptNative(GtkBox::new(GtkOrientation::VERTICAL, $spacing));
        $this->restyle();
    }
}
