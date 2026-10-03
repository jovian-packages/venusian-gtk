<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKToggleButton;

/**
 * A toggle button over GtkToggleButton: toggled posts Toggled with the new state.
 */
class GTKToggleButton extends TKToggleButton implements GTKView
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $label
     * @param bool $pressed
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, bool $pressed)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $pressed);
        $this->adoptNative(\GtkToggleButton::newWithLabel($label));
        $this->applyPressed($pressed);
        $this->connect($this->native, 'toggled', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeToggled($this->widgetToggle()->getActive());
            $this->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $this->pressed));
        });
    }

    protected function applyLabel(string $label): void
    {
        $this->widgetToggle()->setLabel($label);
    }

    protected function applyPressed(bool $pressed): void
    {
        $this->quietly(fn () => $this->widgetToggle()->setActive($pressed));
    }

    protected function widgetToggle(): \GtkToggleButton
    {
        /** @var \GtkToggleButton */
        return $this->native;
    }
}
