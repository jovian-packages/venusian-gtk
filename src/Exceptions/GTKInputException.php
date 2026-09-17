<?php

namespace Jovian\Venusian\GTK\Exceptions;

use Surface\Contracts\HumanInput\HumanInputException;

/**
 * Raised when GTK input cannot be wired.
 */
class GTKInputException extends HumanInputException
{
    /**
     * A window handle did not box to a GtkWidget, so no controller can be added to it.
     */
    public static function notAWidget(string $window, int $handle): static
    {
        return new static("Window '{$window}' (handle {$handle}) is not a live GTK widget; its input controllers cannot be attached.");
    }
}
