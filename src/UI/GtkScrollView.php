<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkPolicyType;
use GtkScrolledWindow;
use WeakReference;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkScrolledWindow, which puts what cannot scroll itself in a viewport.
 *
 * Along an axis it scrolls, the viewport scrolls over the child's natural size, not its
 * minimum (GtkScrollable's default, GTK_SCROLL_MINIMUM): at the minimum a child taller than
 * the view was laid out at its minimum height, and a fitted picture, whose minimum height is
 * 0, got none of it (measured: a 3709x2770 picture at 820 wide was 158 high under a wrapping
 * label at the minimum, 613 at the natural size). Across an axis it does not scroll, the
 * minimum stays: the natural width there is the picture's full 3709, and the text stopped
 * wrapping (measured).
 */
class GtkScrollView extends TKScrollView
{
    use GtkNative;

    /** GtkScrollablePolicy's values; g_object_set_property takes an enum's int. */
    private const int SCROLL_MINIMUM = 0;

    private const int SCROLL_NATURAL = 1;

    protected function bootstrapNative(): void
    {
        $scrolled = GtkScrolledWindow::new();
        $this->adoptNative($scrolled);
        $self = WeakReference::create($this);
        foreach ([$scrolled->getVadjustment(), $scrolled->getHadjustment()] as $adjustment) {
            $this->connect($adjustment, 'value-changed', static function () use ($self): void {
                $self->get()?->scrolled();
            });
        }
        $this->applyScrollbars($this->scrollsAcross(), $this->scrollsDown());
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->scrolledNative()->setChild($child->native());
        $this->applyPolicies($this->scrollsAcross(), $this->scrollsDown());
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
        $this->applyPolicies($horizontal, $vertical);
    }

    /** Each adjustment's value, page size and upper end. */
    protected function nativeScrollPosition(): ScrollPosition
    {
        $horizontal = $this->scrolledNative()->getHadjustment();
        $vertical = $this->scrolledNative()->getVadjustment();

        return ScrollPosition::of(
            $horizontal->getValue(), $horizontal->getPageSize(), $horizontal->getUpper(),
            $vertical->getValue(), $vertical->getPageSize(), $vertical->getUpper(),
        );
    }

    /**
     * Natural along each axis it scrolls, minimum across the others, on what setChild() put
     * around the child (or the child itself when it scrolls itself).
     */
    private function applyPolicies(bool $horizontal, bool $vertical): void
    {
        $scrollable = $this->scrolledNative()->getChild();
        if (is_null($scrollable)) {
            return;
        }
        g_object_set_property($scrollable, 'hscroll-policy', $horizontal ? self::SCROLL_NATURAL : self::SCROLL_MINIMUM);
        g_object_set_property($scrollable, 'vscroll-policy', $vertical ? self::SCROLL_NATURAL : self::SCROLL_MINIMUM);
    }

    private function scrolledNative(): GtkScrolledWindow
    {
        /** @var GtkScrolledWindow */
        return $this->native();
    }
}
