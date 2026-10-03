<?php

namespace Jovian\Toolkits\GTK\Contracts\Primitives;

use Surface\Contracts\Windows\Primitives\TKPrimitive;

/**
 * A GTK container primitive: takes a removed child's widget out of its own.
 */
interface GTKContainer extends GTKView
{
    /**
     * Take $child's widget out of this container's widget. Called from the child's removal.
     *
     * @param TKPrimitive $child
     * @return void
     */
    public function removeNative(TKPrimitive $child): void;
}
