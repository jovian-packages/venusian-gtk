<?php

namespace Jovian\Toolkits\GTK\Primitives\Concerns;

use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\NutsAndBolts\Color;

/**
 * Font and text colour for the kinds that show text: both are declarations in the
 * primitive's stylesheet, rebuilt with the rest of it.
 */
trait GTKTextStyle
{
    protected function applyFont(FontSpec $font): void
    {
        $this->restyle();
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->restyle();
    }
}
