<?php

namespace Jovian\Venusian\GTK\Input;

use Surface\Contracts\HumanInput\Circuits\GameController;

/** Finds, opens and closes gamepad nodes. */
interface PadScanner
{
    /** @return list<string> gamepad node paths */
    public function gamepads(): array;

    public function open(string $path): ?GameController;

    /** Release a pad this scanner opened. */
    public function close(GameController $pad): void;
}
