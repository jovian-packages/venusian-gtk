<?php

namespace Jovian\Toolkits\GTK\UI;

use WeakReference;
use GtkToggleButton as NativeToggleButton;
use Surface\Toolkits\UI\TKToggleButton;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkToggleButton. toggled comes for setActive() from code too, which topside recognises
 * as the state it already holds.
 */
class GtkToggleButton extends TKToggleButton
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $button = NativeToggleButton::newWithLabel($this->label());
        $button->setActive($this->isPressed());
        $this->adoptNative($button);

        $self = WeakReference::create($this);
        $this->connect($button, 'toggled', static function () use ($self): void {
            $primitive = $self->get();
            $primitive?->toggled($primitive->buttonNative()->getActive());
        });
    }

    protected function applyLabel(string $label): void
    {
        $this->buttonNative()->setLabel($label);
    }

    protected function applyPressed(bool $pressed): void
    {
        $this->buttonNative()->setActive($pressed);
    }

    private function buttonNative(): NativeToggleButton
    {
        /** @var NativeToggleButton */
        return $this->native();
    }
}
