<?php

namespace Jovian\Venusian\GTK\Providers;

use Jovian\Venusian\GTK\Sessions\BridgedLinuxOSSession;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Publishes the GTK session under the alias Surface looks for on Linux.
 */
class VenusianGTKServiceProvider extends ServiceProvider
{
    /**
     * Bind the session as a singleton behind 'linux.bridge'.
     *
     * Surface's build action resolves that string and nothing else, so installing
     * this package is the whole of what makes Linux windowing available.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(BridgedLinuxOSSession::class);
        $this->app->alias(BridgedLinuxOSSession::class, 'linux.bridge');
    }

    /**
     * Nothing to boot. The session initialises GTK when it is first resolved.
     * @return void
     */
    public function boot(): void {}
}
