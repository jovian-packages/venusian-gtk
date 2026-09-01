<?php

namespace Jovian\Venusian\GTK\Exceptions;

use Surface\Contracts\NativeWindows\WindowableException;

/**
 * Raised when the GTK bridge cannot be stood up.
 */
class GTKWindowException extends WindowableException
{
    /**
     * A widget asked for styling before its window reached a display.
     */
    public static function noDisplayForStyles(string $window): static
    {
        return new static("Window '{$window}' has no display to attach styles to yet.");
    }
}
