<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKButtonImage;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKContextMenu;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Toolkits\Menus\ContextMenu as ContextMenuContract;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Menus\ContextMenu;
use Surface\Toolkits\Primitives\TKMenuButton;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A GtkMenuButton over a context menu's model, its "ctx" actions inserted on the button, so a
 * chosen item's action posts MenuActivated as the context menu's do. The button's child is a box
 * of the image (when there is one) and the label.
 */
class GTKMenuButton extends TKMenuButton implements GTKView
{
    use GTKButtonImage;
    use GTKPrimitive;

    protected ?GTKContextMenu $items = null;

    /**
     * @throws ToolkitRuntimeException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $label, ContextMenuContract $menu)
    {
        parent::__construct($name, $window, $parent, $placement, $label, $menu);
        $this->adoptNative(\GtkMenuButton::new());
        $this->applyChild();
        $this->applyMenu($menu);
    }

    /** The model and actions the button shows; null for a menu that is not Surface's. */
    public function menuItems(): ?GTKContextMenu
    {
        return $this->items;
    }

    protected function applyLabel(string $label): void
    {
        $this->applyChild();
    }

    protected function applyImage(?Framebuffer $image, float $scale): void
    {
        $this->applyChild();
    }

    protected function applyMenu(ContextMenuContract $menu): void
    {
        $this->items = $menu instanceof ContextMenu ? new GTKContextMenu($menu, $this->window->name(), $this->session(), $this) : null;
        $this->widgetMenuButton()->setMenuModel($this->items?->model());
        $this->native->insertActionGroup('ctx', $this->items?->actions());
    }

    protected function applyBordered(bool $on): void
    {
        $this->widgetMenuButton()->setHasFrame($on);
    }

    protected function releaseNative(): void
    {
        $this->widgetMenuButton()->setMenuModel(null);
        $this->native->insertActionGroup('ctx', null);
        $this->items = null;
    }

    /** @throws ToolkitRuntimeException When GDK makes no texture of the image. */
    private function applyChild(): void
    {
        $box = \GtkBox::new(\GtkOrientation::HORIZONTAL, 4);
        if (! is_null($this->image)) {
            $box->append(self::imageFrame($this->image, $this->image_scale));
        }
        $box->append(\GtkLabel::new($this->label));
        $this->widgetMenuButton()->setChild($box);
    }

    protected function widgetMenuButton(): \GtkMenuButton
    {
        /** @var \GtkMenuButton */
        return $this->native;
    }
}
