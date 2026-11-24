<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkOrientation;
use Surface\Toolkits\UI\TKColumn;
use Jovian\Toolkits\GTK\UI\Concerns\GtkBoxChildren;

/**
 * A vertical GtkBox.
 */
class GtkColumn extends TKColumn
{
    use GtkBoxChildren;

    protected function orientation(): GtkOrientation
    {
        return GtkOrientation::VERTICAL;
    }
}
