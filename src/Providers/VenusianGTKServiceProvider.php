<?php

namespace Jovian\Toolkits\GTK\Providers;

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver as Driver;
use Jovian\Toolkits\GTK\Contracts\Bridge\GTKBridgeDriver as DriverContract;
use Jovian\Toolkits\GTK\Input\GTKInputEngine;
use ReflectionException;
use Surface\Bridge\ToolkitManager;
use Surface\HumanInput\HumanInputManager;
use Surface\HumanInput\InputFrame;
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
        $this->app->registerSingleton(DriverContract::class, fn (FrameworkCore $app) => $app->get('toolkit-bridge')->driver('gtk'));
        $this->app->alias('gtk-bridge', DriverContract::class);
    }

    /**
     * The toolkit's driver, registered on the bridge by this package: Surface names no toolkit.
     * With Surface's HumanInput bound, the 'gtk' input engine too.
     */
    public function boot(): void
    {
        $toolkits = $this->app->get('toolkit-bridge');
        $toolkits->extend('gtk', fn ($app): Driver => new Driver($app));
        if ($this->app->has('human-input')) {
            self::input($this->app->get('human-input'), $toolkits);
        }
    }

    /** The 'gtk' input engine for the GTK session on $toolkits: Surface names no engine. */
    public static function input(HumanInputManager $input, ToolkitManager $toolkits): void
    {
        $input->extend('gtk', fn (InputFrame $frame): GTKInputEngine => new GTKInputEngine($frame, $toolkits->driver('gtk')));
    }
}
