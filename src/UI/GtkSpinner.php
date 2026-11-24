<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkSpinner as NativeSpinner;
use Surface\Toolkits\UI\TKSpinner;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkSpinner.
 */
class GtkSpinner extends TKSpinner
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NativeSpinner::new());
        $this->declare('min-width', '20px');
        $this->declare('min-height', '20px');
        $this->applySpinning($this->isSpinning());
    }

    protected function applySpinning(bool $spinning): void
    {
        $this->spinnerNative()->setSpinning($spinning);
    }

    private function spinnerNative(): NativeSpinner
    {
        /** @var NativeSpinner */
        return $this->native();
    }
}
