<?php

namespace Jovian\Toolkits\GTK\UI\Concerns;

use GtkBox;
use GtkOrientation;
use Surface\Contracts\Toolkits\UI\Insets;
use Surface\Toolkits\UI\TKPrimitive;

/**
 * What a GTK column and row share: a GtkBox, built with the spacing; padding as CSS padding,
 * each side its own, which sits inside the background; children appended, removed and
 * reordered in the box; homogeneous as GTK's own.
 */
trait GtkBoxChildren
{
    use GtkNative;

    abstract protected function orientation(): GtkOrientation;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(GtkBox::new($this->orientation(), $this->spacing()));

        $this->applyPadding($this->padding());
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->declare('padding', $padding->isZero() ? null : self::cssSides($padding));
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

    /**
     * GTK's homogeneous box: every shown child gets the same length along the axis (margin
     * included), the largest minimum times the count as the box's own minimum; a hidden child
     * takes no share.
     */
    protected function applyHomogeneous(bool $on): void
    {
        $this->boxNative()->setHomogeneous($on);
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
