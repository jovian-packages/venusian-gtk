<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKFixed;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A fixed over \GtkFixed: each child put at its frame's origin, its frame size written as the
 * child's size request (the larger of frame and minimum size). GTK never allocates a widget
 * below its own minimum, so a child whose content needs more than its frame gets that.
 */
class GTKFixed extends TKFixed implements GTKContainer
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
        $this->adoptNative(\GtkFixed::new());
    }

    public function removeNative(TKPrimitive $child): void
    {
        $this->widgetFixed()->remove(self::nativeOf($child));
    }

    protected function insertNative(TKPrimitive $child): void
    {
        $frame = $this->frameOf($child);
        $this->widgetFixed()->put(self::nativeOf($child), (float) $frame->x, (float) $frame->y);
        $this->syncChild($child);
    }

    protected function applyMove(TKPrimitive $child, int $x, int $y): void
    {
        $this->widgetFixed()->move(self::nativeOf($child), (float) $x, (float) $y);
    }

    protected function applyResize(TKPrimitive $child, int $width, int $height): void
    {
        $this->syncChild($child);
    }

    protected function syncChild(TKPrimitive $child): void
    {
        if ($child instanceof GTKView) {
            $child->syncSizeRequest();
        }
    }

    protected function widgetFixed(): \GtkFixed
    {
        /** @var \GtkFixed */
        return $this->native;
    }
}
