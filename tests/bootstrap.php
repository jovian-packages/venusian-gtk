<?php

/*
| Prefer the package's own vendor when installed. Without it (no path repos
| for jovian/gtk and surface/* here) run via another package's Pest binary,
| e.g. `../../venusian/surface/vendor/bin/pest`, and autoload this package's
| ext-free classes directly.
*/

$vendor = dirname(__DIR__).'/vendor/autoload.php';

if (is_file($vendor)) {
    require $vendor;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'Jovian\\Venusian\\GTK\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});

/*
| Ext-free doubles for the GLib signal helpers, so GtkSignals bookkeeping is
| testable on a machine without ext-gtk. Real helpers win when present.
*/
if (! function_exists('g_signal_connect')) {
    /** @var list<array{0: string, 1: int, 2: string|int}> */
    $GLOBALS['gtk_signal_log'] = [];

    /** @var array<int, callable> */
    $GLOBALS['gtk_signal_handlers'] = [];

    function g_signal_connect(int $instance, string $signal, ?callable $handler): int
    {
        if (! empty($GLOBALS['gtk_signal_fail_next'])) {
            $GLOBALS['gtk_signal_fail_next'] = false;

            return 0;
        }

        $GLOBALS['gtk_signal_log'][] = ['connect', $instance, $signal];

        $id = count($GLOBALS['gtk_signal_log']);

        $GLOBALS['gtk_signal_handlers'][$id] = $handler;
        $GLOBALS['gtk_signal_by_key'][$instance.':'.$signal] = $handler;

        return $id;
    }

    function g_signal_handler_disconnect(int $instance, int $handler_id): void
    {
        $GLOBALS['gtk_signal_log'][] = ['disconnect', $instance, $handler_id];
    }
}

if (! function_exists('gtk_text_set_on_change')) {
    function gtk_text_set_on_change(int $text, callable $onChange): void
    {
        $GLOBALS['gtk_signal_log'][] = ['text_on_change', $text];
        $GLOBALS['gtk_text_change'][$text][] = $onChange;
    }
}

if (! function_exists('gtk_popover_popdown')) {
    function gtk_popover_popdown(int $popover): void
    {
        $GLOBALS['gtk_signal_log'][] = ['popdown', $popover];
        $handler = $GLOBALS['gtk_signal_by_key'][$popover.':closed'] ?? null;

        if (is_callable($handler)) {
            $handler();
        }
    }
}

if (! function_exists('gtk_notebook_append_page')) {
    function gtk_notebook_append_page(int $notebook, int $child, string $title): int
    {
        $GLOBALS['gtk_signal_log'][] = ['append_page', $notebook, $child, $title];
        $handler = $GLOBALS['gtk_signal_by_key'][$notebook.':notify::page'] ?? null;

        if (is_callable($handler)) {
            $handler();
        }

        return 0;
    }
}
