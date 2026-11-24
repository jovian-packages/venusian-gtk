<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkSwitch;
use WeakReference;
use Surface\Toolkits\UI\TKToggle;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkSwitch. notify::active tells topside the new state; GTK also emits it for
 * setActive() from code, which topside recognises as the state it already holds.
 */
class GtkToggle extends TKToggle
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $switch = GtkSwitch::new();
        $switch->setActive($this->isOn());
        $this->adoptNative($switch);

        $self = WeakReference::create($this);
        $this->connect($switch, 'notify::active', static function () use ($self): void {
            $switch = $self->get();
            $switch?->toggled($switch->switchNative()->getActive());
        });
    }

    protected function applyOn(bool $on): void
    {
        $this->switchNative()->setActive($on);
    }

    private function switchNative(): GtkSwitch
    {
        /** @var GtkSwitch */
        return $this->native();
    }
}
