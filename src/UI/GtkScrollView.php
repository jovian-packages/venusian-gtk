<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkPolicyType;
use GtkScrolledWindow;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkScrolledWindow, which puts what cannot scroll itself in a viewport.
 */
class GtkScrollView extends TKScrollView
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(GtkScrolledWindow::new());
        $this->applyScrollbars($this->scrollsAcross(), $this->scrollsDown());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->scrolledNative()->setChild($child->native());
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $this->scrolledNative()->setChild(null);
    }

    protected function applyScrollbars(bool $horizontal, bool $vertical): void
    {
        $this->scrolledNative()->setPolicy(
            $horizontal ? GtkPolicyType::AUTOMATIC : GtkPolicyType::NEVER,
            $vertical ? GtkPolicyType::AUTOMATIC : GtkPolicyType::NEVER,
        );
    }

    private function scrolledNative(): GtkScrolledWindow
    {
        /** @var GtkScrolledWindow */
        return $this->native();
    }
}
