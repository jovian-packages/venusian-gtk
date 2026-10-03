<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKButton;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A button over GtkButton: clicked posts ButtonClicked.
 */
class GTKButton extends TKButton implements GTKView
{
    use GTKPrimitive;
    use GTKTextStyle;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $label
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label)
    {
        parent::__construct($name, $window, $parent, $placement, $label);
        $this->adoptNative(\GtkButton::newWithLabel($label));
        $this->connect($this->native, 'clicked', fn () => $this->post(new ButtonClicked($this->window->name(), $this->path(), $this->uuid)));
    }

    protected function applyLabel(string $label): void
    {
        $this->widgetButton()->setLabel($label);
    }

    protected function widgetButton(): \GtkButton
    {
        /** @var \GtkButton */
        return $this->native;
    }
}
