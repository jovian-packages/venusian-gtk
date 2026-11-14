<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Toolkits\Primitives\TKSpinner;

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
     * @throws ToolkitRuntimeException When the name is not valid.
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
