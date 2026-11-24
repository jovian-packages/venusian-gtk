<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkOrientation;
use GtkSeparator as NativeSeparator;
use Surface\Toolkits\UI\TKSeparator;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkSeparator.
 */
class GtkSeparator extends TKSeparator
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NativeSeparator::new($this->isHorizontal() ? GtkOrientation::HORIZONTAL : GtkOrientation::VERTICAL));
    }
}
