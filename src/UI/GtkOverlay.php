<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GtkOrientation;
use GtkOverlay as NativeOverlay;
use Surface\Toolkits\UI\TKOverlay;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkOverlay. Its main child is an empty box of its own; every child is an overlay on it,
 * placed by GTK from its halign, valign and margin, later siblings drawn above earlier ones.
 * A child that fills both ways is left out of the overlay's measure (GTK's measure-overlay),
 * so it takes the overlay's size without giving it one. Padding is CSS padding: GTK places
 * the children inside it.
 */
class GtkOverlay extends TKOverlay
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $overlay = NativeOverlay::new();
        $overlay->setChild(GtkBox::new(GtkOrientation::HORIZONTAL, 0));
        $this->adoptNative($overlay);
        $this->applyPadding($this->padding());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->overlayNative()->addOverlay($child->native());
        $this->measure($child);
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $this->overlayNative()->removeOverlay($child->native());
    }

    /**
     * GTK draws overlays in the order they were added: from the first child out of place on,
     * each goes out and back in, in the children's order.
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $overlay = $this->overlayNative();
        $main = $overlay->getChild();

        $native = [];
        for ($at = $overlay->getFirstChild(); ! is_null($at); $at = $at->getNextSibling()) {
            if ($at !== $main) {
                $native[] = $at;
            }
        }

        $children = $this->children();
        $from = 0;
        while ($from < count($children) && ($native[$from] ?? null) === $children[$from]->native()) {
            $from++;
        }

        $moving = array_slice($children, $from);
        foreach ($moving as $moved) {
            $overlay->removeOverlay($moved->native());
        }
        foreach ($moving as $moved) {
            $overlay->addOverlay($moved->native());
            $this->measure($moved);
        }
    }

    /**
     * A child's fill decides whether it sizes the overlay.
     */
    protected function childLayoutChanged(TKPrimitive $child): void
    {
        $this->measure($child);
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->declare('padding', $padding->isZero() ? null : self::cssSides($padding));
    }

    private function measure(TKPrimitive $child): void
    {
        $this->overlayNative()->setMeasureOverlay($child->native(), $this->sizesOverlay($child));
    }

    private function overlayNative(): NativeOverlay
    {
        /** @var NativeOverlay */
        return $this->native();
    }
}
