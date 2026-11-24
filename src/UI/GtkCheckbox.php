<?php

namespace Jovian\Toolkits\GTK\UI;

use WeakReference;
use GtkCheckButton;
use Surface\Toolkits\UI\TKCheckbox;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkCheckButton. Its toggled signal tells topside the new state; GTK also emits it for
 * setActive() from code, which topside recognises as the state it already holds.
 */
class GtkCheckbox extends TKCheckbox
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $box = GtkCheckButton::newWithLabel($this->label());
        $box->setActive($this->isChecked());
        $this->adoptNative($box);

        $self = WeakReference::create($this);
        $this->connect($box, 'toggled', static function () use ($self): void {
            $box = $self->get();
            $box?->toggled($box->boxNative()->getActive());
        });
    }

    protected function applyLabel(string $label): void
    {
        $this->boxNative()->setLabel($label);
    }

    protected function applyChecked(bool $checked): void
    {
        $this->boxNative()->setActive($checked);
    }

    private function boxNative(): GtkCheckButton
    {
        /** @var GtkCheckButton */
        return $this->native();
    }
}
