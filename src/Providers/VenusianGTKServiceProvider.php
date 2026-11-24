<?php

namespace Jovian\Toolkits\GTK\Providers;

use Jovian\Toolkits\GTK\GtkAdapterDriver;
use Surface\Toolkits\ToolkitAdapterServiceProvider;

class VenusianGTKServiceProvider extends ToolkitAdapterServiceProvider
{
    protected string $toolkit = 'gtk';

    protected string $driver = GtkAdapterDriver::class;

    protected string $config = __DIR__.'/../../config/gtk.php';
}
