<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKButtonImage;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Contracts\Windows\Primitives\ImagePosition;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKButton;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A button over GtkButton: clicked posts ButtonClicked.
 */
class GTKButton extends TKButton implements GTKView
{
    use GTKButtonImage;
    use GTKPrimitive;
    use GTKTextStyle;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $label
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label)
    {
        parent::__construct($name, $window, $parent, $placement, $label);
        $this->adoptNative(\GtkButton::newWithLabel($label));
        $this->connect($this->native, 'clicked', fn () => $this->post(new ButtonClicked($this->window->name(), $this->path(), $this->uuid)));
        // A hidden group or a page that is not selected unmaps the button: the look follows.
        $this->connect($this->native, 'map', fn () => $this->applyRole($this->role));
        $this->connect($this->native, 'unmap', fn () => $this->applyRole($this->role));
    }

    protected function applyLabel(string $label): void
    {
        if (is_null($this->image)) {
            $this->widgetButton()->setLabel($label);
        } else {
            $this->applyImage($this->image, $this->image_position, $this->image_scale);
        }
    }

    /** The image replaces the button's label child with a box of the image and a label; none puts the label back. */
    protected function applyImage(?Framebuffer $image, ImagePosition $position, float $scale): void
    {
        if (is_null($image)) {
            $this->widgetButton()->setLabel($this->label);

            return;
        }
        $this->widgetButton()->setChild(self::imageChild($image, $position, $scale, $this->label));
    }

    protected function applyBordered(bool $on): void
    {
        $this->widgetButton()->setHasFrame($on);
    }

    /**
     * The default holder is the window's default widget while it is mapped, for the look. GtkWindow
     * binds Return to activate-default, which checks sensitivity but not mapped: a hidden holder
     * that stayed the default would be clicked. A button that loses the role, or unmaps, stops
     * being the default only when it still is.
     */
    protected function applyRole(?ButtonRole $role): void
    {
        $window = $this->window;
        if (! $window instanceof GTKWindow || ! $window->isOpen()) {
            return;
        }
        $native = $window->native();
        if ($role === ButtonRole::DEFAULT && $this->native->getMapped()) {
            $native->setDefaultWidget($this->native);
        } elseif ($native->getDefaultWidget() === $this->native) {
            $native->setDefaultWidget(null);
        }
    }

    /**
     * GTK keeps a destroyed default widget as the window's default, and a later read or teardown of
     * the window touches freed memory: the default holder stops being the default first.
     */
    protected function releaseNative(): void
    {
        if ($this->role === ButtonRole::DEFAULT) {
            $this->applyRole(null);
        }
    }

    protected function widgetButton(): \GtkButton
    {
        /** @var \GtkButton */
        return $this->native;
    }
}
