<?php

namespace Jovian\Toolkits\GTK\Input;

/**
 * One controller signal's arguments, for the session's taps: GTK hands signals scalars, and
 * a tap sees objects. $window is the Surface window name the controller is on.
 */
final readonly class SeenEvent
{
    public function __construct(
        public SeenKind $kind,
        public string $window,
        public int $keyval = 0,
        public int $keycode = 0,
        public int $state = 0,
        public float $x = 0.0,
        public float $y = 0.0,
        public int $button = 0,
        public bool $down = false,
        public bool $pixels = false,
        public bool $inverted = false,
    ) {}
}
