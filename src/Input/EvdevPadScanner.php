<?php

namespace Jovian\Venusian\GTK\Input;

use Microscrap\ScrapyardEvdev\EvdevGamepad;
use Microscrap\ScrapyardEvdev\EvdevGamepadException;
use Microscrap\ScrapyardEvdev\EvdevScanner;
use Surface\Contracts\HumanInput\Circuits\GameController;

/** EvdevScanner as a PadScanner. A node that opens but answers no probe is not a pad. */
final class EvdevPadScanner implements PadScanner
{
    public function __construct(private readonly EvdevScanner $scanner) {}

    public function gamepads(): array
    {
        return $this->scanner->gamepads();
    }

    public function open(string $path): ?GameController
    {
        try {
            return $this->scanner->open($path);
        } catch (EvdevGamepadException) {
            return null;
        }
    }

    public function close(GameController $pad): void
    {
        if ($pad instanceof EvdevGamepad) {
            $pad->close();
        }
    }
}
