<?php

namespace Jovian\Toolkits\GTK\UI;

use WeakReference;
use GtkButton as NativeButton;
use Surface\Toolkits\UI\TKButton;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkButton. It takes keyboard focus by default; its clicked signal posts ButtonClicked.
 */
class GtkButton extends TKButton
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NativeButton::newWithLabel($this->label()));

        $self = WeakReference::create($this);
        $this->connect($this->native, 'clicked', static function () use ($self): void {
            $self->get()?->clicked();
        });
    }

    protected function applyLabel(string $label): void
    {
        /** @var NativeButton $button */
        $button = $this->native();
        $button->setLabel($label);
    }
}
