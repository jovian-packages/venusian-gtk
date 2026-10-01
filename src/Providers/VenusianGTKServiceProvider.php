<?php

namespace Jovian\Toolkits\GTK\Providers;

use Jovian\Toolkits\GTK\Contracts\Bridge\GTKBridgeDriver;
use ReflectionException;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianGTKServiceProvider extends ServiceProvider
{
    /**
     * The GTK driver is the bridge's: resolving it by its contract asks the toolkit manager,
     * so there is one driver, one session and one set of windows.
     *
     * @return void
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton(GTKBridgeDriver::class, fn (FrameworkCore $app) => $app->get('toolkit-bridge')->driver('gtk'));
    }

    public function boot(): void
    {

    }
}
