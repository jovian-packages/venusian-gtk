<?php

namespace Jovian\Venusian\GTK\Sessions;

use Jovian\Bindings\Gtk\Enums\GtkOrientation;
use Jovian\Bindings\Gtk\Gtk\GtkBox;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkWindow;
use Jovian\Bindings\Gtk\Runtime\Bridge;
use Jovian\Bindings\Gtk\Runtime\Lifetime;
use Jovian\Venusian\GTK\Exceptions\GTKBridgeException;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use RuntimeException;
use Surface\Bridge\BridgedOSSession;
use Surface\Contracts\Bridge\LinuxOSBridge;
use Surface\NativeWindows\Windowable;

/**
 * Surface's bridge to GTK4.
 *
 * Initialisation is gtk_init and nothing more. Connection and disconnection are
 * honest no-ops: GTK has no counterpart to gtk_init, and on Linux there is
 * nothing for the desktop to show until a window is actually mapped — unlike
 * macOS, where connecting alone raises a Dock icon.
 *
 * GtkApplication is deliberately untouched. Its run() segfaults through a remote
 * instance, and PHP drives this loop by pumping the default main context instead.
 */
class BridgedLinuxOSSession extends BridgedOSSession implements LinuxOSBridge
{
    /**
     * Initialise GTK against the current display seat.
     * @return void
     * @throws GTKBridgeException When there is no usable display to initialise against.
     */
    protected function initializeEngine(): void
    {
        try {
            Lifetime::boot();
        }
        catch (RuntimeException $e) {
            throw GTKBridgeException::gtkFailedToInitialize($e);
        }
    }

    /**
     * Nothing to do. GTK shows nothing until a window is presented.
     * @return void
     */
    protected function connectToEngine(): void {}

    /**
     * Nothing to do. GTK has no un-init, and there is no presence to withdraw.
     * @return void
     */
    protected function disconnectEngine(): void {}

    /**
     * Iterate GTK's default main context, which already speaks milliseconds.
     *
     * A non-zero budget may block for its full length when the context has nothing
     * queued, which is where the contract's may-block promise comes from.
     *
     * @param int $budget_ms Milliseconds the engine may spend. Zero drains without waiting.
     * @return int Iterations that dispatched.
     */
    protected function pumpEngine(int $budget_ms): int
    {
        return Bridge::pump($budget_ms);
    }

    public function provisionNewWindow(string $name, int $width, int $height): GTKWindowDelegate
    {
        $window = GtkWindow::new();
        $window->setDefaultSize($width, $height);

        return new GTKWindowDelegate($name, $window);
    }


}
