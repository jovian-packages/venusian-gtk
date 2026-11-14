<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Mail\Views\Toggled;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Toolkits\Primitives\TKToggle;

/**
 * A switch over GtkSwitch: notify::active posts Toggled with the new state.
 */
class GTKToggle extends TKToggle implements GTKView
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param bool $on
     * @throws ToolkitRuntimeException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $on)
    {
        parent::__construct($name, $window, $parent, $placement, $on);
        $this->adoptNative(\GtkSwitch::new());
        $this->applyOn($on);
        $this->connect($this->native, 'notify::active', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeToggled($this->widgetSwitch()->getActive());
            $this->post(new Toggled($this->window->name(), $this->path(), $this->uuid, $this->on));
        });
    }

    protected function applyOn(bool $on): void
    {
        $this->quietly(fn () => $this->widgetSwitch()->setActive($on));
    }

    protected function widgetSwitch(): \GtkSwitch
    {
        /** @var \GtkSwitch */
        return $this->native;
    }
}
