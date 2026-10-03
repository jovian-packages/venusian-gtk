<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKCheckbox;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A checkbox over GtkCheckButton: toggled posts Toggled with the new state.
 */
class GTKCheckbox extends TKCheckbox implements GTKView
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $label
     * @param bool $checked
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, bool $checked)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $checked);
        $this->adoptNative(\GtkCheckButton::newWithLabel($label));
        $this->applyChecked($checked);
        $this->connect($this->native, 'toggled', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeToggled($this->widgetCheck()->getActive());
            $this->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $this->checked));
        });
    }

    protected function applyLabel(string $label): void
    {
        $this->widgetCheck()->setLabel($label);
    }

    protected function applyChecked(bool $checked): void
    {
        $this->quietly(fn () => $this->widgetCheck()->setActive($checked));
    }

    protected function widgetCheck(): \GtkCheckButton
    {
        /** @var \GtkCheckButton */
        return $this->native;
    }
}
