<?php

namespace Jovian\Venusian\GTK\Providers;

use Jovian\Venusian\GTK\Input\EvdevPadScanner;
use Jovian\Venusian\GTK\Input\GTKInputEngine;
use Jovian\Venusian\GTK\Input\GtkControllerFactory;
use Jovian\Venusian\GTK\Input\NativeGtkWindowSpace;
use Jovian\Venusian\GTK\Sessions\BridgedLinuxOSSession;
use Microscrap\ScrapyardEvdev\EvdevScanner;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Publishes the GTK session and the GTK input engine under the aliases
 * Surface looks for on Linux.
 */
class VenusianGTKServiceProvider extends ServiceProvider
{
    /**
     * Bind the session as a singleton behind 'linux.bridge', and the input
     * engine, which reads what that session's pump delivers plus evdev
     * gamepads, behind 'input.gtk'.
     *
     * Surface resolves those strings and nothing else, so installing this
     * package is the whole of what makes Linux windowing and GTK input available.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(BridgedLinuxOSSession::class);
        $this->app->alias(BridgedLinuxOSSession::class, 'linux.bridge');

        $this->app->singleton(GTKInputEngine::class, fn () => new GTKInputEngine(new GtkControllerFactory(), new NativeGtkWindowSpace(), new EvdevPadScanner(new EvdevScanner())));
        $this->app->alias(GTKInputEngine::class, 'input.gtk');
    }

    /**
     * Nothing to boot. The session initialises GTK when it is first resolved.
     * @return void
     */
    public function boot(): void {}
}
