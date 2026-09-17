<?php

namespace Jovian\Venusian\GTK\Input;

/** The four input controllers attached to one window, by registry handle, and the handlers connected on the window itself. */
final class WindowControllers
{
    /**
     * @param array<string, int> $handles controller kind (key, motion, click, scroll) → registry handle
     * @param array<string, int> $signals window signal (notify::is-active) → handler id
     */
    public function __construct(
        public readonly string $window,
        public readonly int $window_handle,
        public readonly array $handles,
        public readonly array $signals = [],
    ) {}
}
