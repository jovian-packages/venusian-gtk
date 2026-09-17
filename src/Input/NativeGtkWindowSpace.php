<?php

namespace Jovian\Venusian\GTK\Input;

use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;

/**
 * Surface's native windows that are GTK windows and still presenting. A
 * closed delegate's GtkWindow is destroyed and its handle recycled, so
 * isPresenting() (which checks the delegate's closed flag first) gates it out.
 * No native-window binding, no windows.
 */
final class NativeGtkWindowSpace implements GtkWindowSpace
{
    public function windows(): array
    {
        $windows = [];

        if (! app()->bound('native-window')) {
            return $windows;
        }

        foreach (app('native-window')->driver()->all() as $delegate) {
            if ($delegate instanceof GTKWindowDelegate && $delegate->isPresenting()) {
                $windows[$delegate->name()] = $delegate->window->handle;
            }
        }

        return $windows;
    }
}
