<?php

namespace Jovian\Toolkits\GTK\Services;

use GFile;
use GtkFileLauncher;
use InvalidArgumentException;
use Surface\Contracts\Windows\Services\Desktop;

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
}
