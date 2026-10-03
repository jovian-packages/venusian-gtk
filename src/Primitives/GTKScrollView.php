<?php

namespace Jovian\Toolkits\GTK\Primitives;

use GtkPolicyType;
use GtkScrolledWindow;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKScrollView;

/**
 * A scroll view over GtkScrolledWindow. Its one container becomes the child; GTK wraps a
 * child that does not scroll itself in a GtkViewport. A scrollbar shows on demand on each
 * axis that scrolls, and never on one that does not.
 */
class GTKScrollView extends TKScrollView implements GTKContainer
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
        $this->adoptNative(GtkScrolledWindow::new());
        $this->applyScrollbars($this->scroll_horizontal, $this->scroll_vertical);
    }

    public function removeNative(TKPrimitive $child): void
    {
        $this->widgetScrolled()->setChild(null);
    }

    protected function insertNative(TKPrimitive $child): void
    {
        $this->widgetScrolled()->setChild(self::nativeOf($child));
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $this->widgetScrolled()->setPolicy(
            $horizontal ? GtkPolicyType::AUTOMATIC : GtkPolicyType::NEVER,
            $vertical ? GtkPolicyType::AUTOMATIC : GtkPolicyType::NEVER,
        );
    }

    protected function widgetScrolled(): GtkScrolledWindow
    {
        /** @var GtkScrolledWindow */
        return $this->native;
    }
}
