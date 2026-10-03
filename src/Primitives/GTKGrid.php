<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKGrid;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A grid over \GtkGrid: each child attached at its cell and spans; spacing on both axes,
 * padding as CSS padding inside the grid.
 */
class GTKGrid extends TKGrid implements GTKContainer
{
    use GTKPrimitive;

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
        $this->adoptNative(\GtkGrid::new());
        $this->applySpacing($spacing);
        $this->restyle();
    }

    public function removeNative(TKPrimitive $child): void
    {
        $this->widgetGrid()->remove(self::nativeOf($child));
    }

    protected function insertNative(TKPrimitive $child): void
    {
        $cell = $child->placement();
        $this->widgetGrid()->attach(self::nativeOf($child), $cell->column, $cell->row, $cell->columnSpan, $cell->rowSpan);
    }

    protected function applySpacing(int $spacing): void
    {
        $this->widgetGrid()->setRowSpacing($spacing);
        $this->widgetGrid()->setColumnSpacing($spacing);
    }

    protected function widgetGrid(): \GtkGrid
    {
        /** @var \GtkGrid */
        return $this->native;
    }
}
