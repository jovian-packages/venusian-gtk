<?php

namespace Jovian\Toolkits\GTK\Services;

use GFile;
use GtkFileLauncher;
use GtkUriLauncher;
use InvalidArgumentException;
use Surface\Contracts\Toolkits\Services\Desktop;
use Surface\Toolkits\Services\WebAddress;

final class GTKDesktop implements Desktop
{
    public function open(string $path): void
    {
        $full = realpath($path);
        if ($full === false) {
            throw new InvalidArgumentException("Nothing at {$path} to open.");
        }
        GtkFileLauncher::new(GFile::newForPath($full))->launch(null, null, null);
    }

    public function browse(string $url): void
    {
        GtkUriLauncher::new(WebAddress::checked($url))->launch(null, null, null);
    }
}
