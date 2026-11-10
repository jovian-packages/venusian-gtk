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
 *
 * Each child sits in a holder: a \GtkScrolledWindow with both policies NEVER. GtkFixed measures
 * itself by its children's natural sizes, so a picture would claim its file's size whatever its
 * frame; the holder's natural size is its child's minimum, which is the frame (or more).
 */
class GTKFixed extends TKFixed implements GTKContainer
{
    use GTKPrimitive;

    /** @var array<string, \GtkScrolledWindow> child uuid => its holder */
    private array $holders = [];

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

    /** The widget GtkFixed places for $child: its holder. */
    public function holderOf(TKPrimitive $child): \GtkScrolledWindow
    {
        return $this->holders[$child->uuid()]
            ?? throw new WindowException("'{$child->path()}' is not in fixed '{$this->path()}'.");
    }

    public function removeNative(TKPrimitive $child): void
    {
        $holder = $this->holderOf($child);
        $holder->setChild(null);
        $this->widgetFixed()->remove($holder);
        unset($this->holders[$child->uuid()]);
    }

    protected function insertNative(TKPrimitive $child): void
    {
        $holder = \GtkScrolledWindow::new();
        $holder->setPolicy(\GtkPolicyType::NEVER, \GtkPolicyType::NEVER);
        $holder->setChild(self::nativeOf($child));
        $this->holders[$child->uuid()] = $holder;

        $frame = $this->frameOf($child);
        $this->widgetFixed()->put($holder, (float) $frame->x, (float) $frame->y);
        $this->syncChild($child);
    }

    protected function applyMove(TKPrimitive $child, int $x, int $y): void
    {
        $this->widgetFixed()->move($this->holderOf($child), (float) $x, (float) $y);
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
