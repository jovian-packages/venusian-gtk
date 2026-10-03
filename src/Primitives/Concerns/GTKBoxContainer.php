<?php

namespace Jovian\Toolkits\GTK\Primitives\Concerns;

use GtkBox;
use Surface\Contracts\Windows\Primitives\TKPrimitive;

/**
 * Column and row over GtkBox: children appended in creation order, reordered after their
 * new predecessor. Padding is CSS padding inside the box, so the box's own size includes it.
 */
trait GTKBoxContainer
{
    public function removeNative(TKPrimitive $child): void
    {
        $this->widgetBox()->remove(self::nativeOf($child));
    }

    protected function insertNative(TKPrimitive $child): void
    {
        $this->widgetBox()->append(self::nativeOf($child));
    }

    protected function applySpacing(int $spacing): void
    {
        $this->widgetBox()->setSpacing($spacing);
    }

    /**
     * The abstract has already moved $child to $index in children(), so its new predecessor
     * is children()[$index - 1]; position 0 has none.
     *
     * @param TKPrimitive $child
     * @param int $index
     * @return void
     */
    protected function applyOrder(TKPrimitive $child, int $index): void
    {
        $after = $index === 0 ? null : self::nativeOf($this->children()[$index - 1]);
        $this->widgetBox()->reorderChildAfter(self::nativeOf($child), $after);
    }

    protected function widgetBox(): GtkBox
    {
        /** @var GtkBox */
        return $this->native;
    }
}
