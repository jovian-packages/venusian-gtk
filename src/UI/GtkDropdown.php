<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkDropDown as NativeDropDown;
use GtkStringList;
use WeakReference;
use Surface\Toolkits\UI\TKDropdown;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkDropDown over a GtkStringList. notify::selected tells topside the chosen index. GTK
 * also emits it when the model is replaced; that comes while $rebuilding and is not the
 * user's, so it is not passed on.
 */
class GtkDropdown extends TKDropdown
{
    use GtkNative;

    private bool $rebuilding = false;

    protected function bootstrapNative(): void
    {
        $dropdown = NativeDropDown::newFromStrings($this->options());
        $dropdown->setSelected(self::position($this->selected()));
        $this->adoptNative($dropdown);

        $self = WeakReference::create($this);
        $this->connect($dropdown, 'notify::selected', static function () use ($self): void {
            $dropdown = $self->get();
            if (! is_null($dropdown) && ! $dropdown->rebuilding) {
                $selected = $dropdown->dropdownNative()->getSelected();
                $dropdown->chosen($selected === GTK_INVALID_LIST_POSITION ? -1 : $selected);
            }
        });
    }

    protected function applyOptions(array $options, int $selected): void
    {
        $dropdown = $this->dropdownNative();

        $this->rebuilding = true;
        try {
            $dropdown->setModel(GtkStringList::new($options));
            $dropdown->setSelected(self::position($selected));
        } finally {
            $this->rebuilding = false;
        }
    }

    protected function applySelected(int $index): void
    {
        $this->dropdownNative()->setSelected($index);
    }

    private function dropdownNative(): NativeDropDown
    {
        /** @var NativeDropDown */
        return $this->native();
    }

    private static function position(int $index): int
    {
        return $index < 0 ? GTK_INVALID_LIST_POSITION : $index;
    }
}
