<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkFixed as NativeFixed;
use Surface\Toolkits\ToolkitException;
use Surface\Toolkits\UI\TKFixed;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Placement;
use Surface\Contracts\Toolkits\UI\TKPrimitive as ChildContract;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkFixed: each child put at its frame's position and sized to its frame (or its minimum
 * size, when larger).
 */
class GtkFixed extends TKFixed
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NativeFixed::new());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $frame = $child->placement();
        $this->fixedNative()->put($child->native(), (float) $frame->x, (float) $frame->y);
        $this->sizeChild($child, $frame);
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $this->fixedNative()->remove($child->native());
    }

    protected function applyFrame(ChildContract $child, Placement $frame): void
    {
        $this->fixedNative()->move($child->native(), (float) $frame->x, (float) $frame->y);
        $this->sizeChild($child, $frame);
    }

    /**
     * Never reached: only a column or row lets a child move (TKPrimitive::moveTo refuses here).
     *
     * @throws ToolkitException
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        throw new ToolkitException("'{$this->path()}' keeps no order: its children are placed by frame.");
    }

    private function sizeChild(TKPrimitive $child, Placement $frame): void
    {
        [$width, $height] = $child->minimumSize();
        $child->native()->setSizeRequest(max($width, $frame->width), max($height, $frame->height));
    }

    private function fixedNative(): NativeFixed
    {
        /** @var NativeFixed */
        return $this->native();
    }
}
