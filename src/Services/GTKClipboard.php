<?php

namespace Jovian\Toolkits\GTK\Services;

use GdkDisplay;
use Surface\Contracts\Windows\Services\Clipboard;

final class GTKClipboard implements Clipboard
{
    public function setText(string $text): void
    {
        GdkDisplay::getDefault()->getClipboard()->setText($text);
    }
}
