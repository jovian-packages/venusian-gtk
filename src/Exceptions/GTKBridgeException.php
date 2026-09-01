<?php

namespace Jovian\Venusian\GTK\Exceptions;

use Throwable;
use Surface\Contracts\Bridge\BridgeException;

/**
 * Raised when the GTK bridge cannot be stood up.
 */
class GTKBridgeException extends BridgeException
{
    /**
     * gtk_init failed, which in practice means there is no display seat to attach to.
     * @param Throwable $previous The runtime failure jovian/gtk reported.
     * @return static
     */
    public static function gtkFailedToInitialize(Throwable $previous): static
    {
        return new static(
            "GTK did not initialise. No display seat? Check DISPLAY / WAYLAND_DISPLAY and a logged-in session.",
            previous: $previous,
        );
    }
}
