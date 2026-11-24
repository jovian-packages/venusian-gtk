<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkOrientation;
use Surface\Toolkits\UI\TKRow;
use Jovian\Toolkits\GTK\UI\Concerns\GtkBoxChildren;

/**
 * A horizontal GtkBox.
 */
class GtkRow extends TKRow
{
    use GtkBoxChildren;

    protected function orientation(): GtkOrientation
    {
        return GtkOrientation::HORIZONTAL;
    }
}
