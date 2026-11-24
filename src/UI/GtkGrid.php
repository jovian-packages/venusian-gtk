<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkGrid as NativeGrid;
use Surface\Toolkits\UI\TKGrid;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Placement;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkGrid; padding is CSS padding, inside its background.
 */
class GtkGrid extends TKGrid
{
    use GtkNative;

    /** @var array<string, Placement> where each child's native is, by its uuid */
    private array $attached = [];

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NativeGrid::new());
        $this->applySpacing($this->spacing());
        if ($this->padding > 0) {
            $this->declare('padding', "{$this->padding}px");
        }
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->put($child);
    }

    protected function detachNative(TKPrimitive $child): void
    {
        unset($this->attached[$child->uuid()]);
        $this->gridNative()->remove($child->native());
    }

    /** Only those that moved, so that the focus stays with the others. */
    protected function applyCells(): void
    {
        $moved = [];
        foreach ($this->children() as $child) {
            if ($this->cellOf($child) != ($this->attached[$child->uuid()] ?? null)) {
                $moved[] = $child;
            }
        }
        foreach ($moved as $child) {
            $this->gridNative()->remove($child->native());
        }
        foreach ($moved as $child) {
            $this->put($child);
        }
    }

    protected function applySpacing(int $spacing): void
    {
        $this->gridNative()->setRowSpacing($spacing);
        $this->gridNative()->setColumnSpacing($spacing);
    }

    private function put(TKPrimitive $child): void
    {
        $cell = $this->cellOf($child);
        $this->gridNative()->attach($child->native(), $cell->column, $cell->row, $cell->columnSpan, $cell->rowSpan);
        $this->attached[$child->uuid()] = $cell;
    }

    private function gridNative(): NativeGrid
    {
        /** @var NativeGrid */
        return $this->native();
    }
}
