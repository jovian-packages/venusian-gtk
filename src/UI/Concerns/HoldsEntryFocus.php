<?php

namespace Jovian\Toolkits\GTK\UI\Concerns;

use GtkEntry;

/**
 * For a primitive with a GtkEntry: the GtkText inside the entry is the window's focus widget,
 * so focus is looked for from there up.
 */
trait HoldsEntryFocus
{
    abstract protected function entryNative(): GtkEntry;

    public function hasFocus(): bool
    {
        if (is_null($this->native)) {
            return false;
        }

        $entry = $this->entryNative();
        for ($at = $this->native->getRoot()?->getFocus(); ! is_null($at); $at = $at->getParent()) {
            if ($at === $entry) {
                return true;
            }
        }

        return false;
    }

    protected function nativeFocus(): bool
    {
        return $this->entryNative()->grabFocus();
    }
}
