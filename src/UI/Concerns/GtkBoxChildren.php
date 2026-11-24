<?php

namespace Jovian\Toolkits\GTK\UI\Concerns;

use GtkBox;
use GtkOrientation;
use Surface\Toolkits\UI\TKPrimitive;

/**
 * What a GTK column and row share: a GtkBox, built with the spacing; padding as CSS padding,
 * which sits inside the background; children appended, removed and reordered in the box.
 */
trait GtkBoxChildren
{
    use GtkNative;

    abstract protected function orientation(): GtkOrientation;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(GtkBox::new($this->orientation(), $this->spacing()));

        if ($this->padding > 0) {
            $this->declare('padding', "{$this->padding}px");
        }
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->boxNative()->append($child->native());
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $this->boxNative()->remove($child->native());
    }

    /**
     * The children are already in their new order: the native goes after the one now before it.
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $before = $index === 0 ? null : $this->children()[$index - 1]->native();
        $this->boxNative()->reorderChildAfter($child->native(), $before);
    }

    protected function applySpacing(int $spacing): void
    {
        $this->boxNative()->setSpacing($spacing);
    }

    private function boxNative(): GtkBox
    {
        /** @var GtkBox */
        return $this->native();
    }
}
