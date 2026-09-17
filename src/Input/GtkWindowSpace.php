<?php

namespace Jovian\Venusian\GTK\Input;

/** The GTK windows input can arrive on. */
interface GtkWindowSpace
{
    /** @return array<string, int> window name → GtkWindow registry handle, live windows only */
    public function windows(): array;
}
