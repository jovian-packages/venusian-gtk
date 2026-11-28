<?php

namespace Jovian\Toolkits\GTK\Video;

use RuntimeException;

/**
 * What a driver throws when it is made on a system it has no player for. GtkVideo catches it:
 * a view whose driver cannot be made here has no player.
 */
class VideoDriverUnsupported extends RuntimeException
{
}
