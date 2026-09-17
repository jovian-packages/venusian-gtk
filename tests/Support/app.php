<?php

/*
| Surface's Pest binary does not load the framework's System helpers, so
| app() does not exist there. This stand-in resolves from a FakeApp the test
| installs in $GLOBALS['venusian_gtk_app']; with none installed nothing is bound.
*/

use Venusian\GTK\Tests\Support\FakeApp;

if (! function_exists('app')) {
    function app(?string $abstract = null): object
    {
        $app = $GLOBALS['venusian_gtk_app'] ?? new FakeApp();

        return is_null($abstract) ? $app : $app->make($abstract);
    }
}
