<?php

namespace Jovian\Toolkits\GTK\Windows;

use GMenu;
use GMenuItem;
use GSimpleAction;
use GSimpleActionGroup;
use GtkPopoverMenu;
use GtkWidget;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuStates;

/**
 * A primitive's context menu, built for one right click: a GtkPopoverMenu over a GMenu model whose
 * items name "ctx" actions in a group on the popover itself, so the actions resolve wherever it is
 * parented. Separators become section boundaries, as in the menu bar. It points at the click, has
 * no arrow, and takes itself off its parent once it closes (after GTK has run the chosen action).
 * Each folder's item names its own action, so a node that is not effectively enabled shows disabled.
 */
final class GTKContextMenu
{
    private readonly GtkPopoverMenu $popover;

    private readonly GSimpleActionGroup $actions;

    public function __construct(
        ContextMenu $menu,
        private readonly string $window,
        private readonly GTKSession $session,
    ) {
        $actions = $this->actions = GSimpleActionGroup::new();
        $model = $this->section($menu->items, $actions);
        $states = new MenuStates($menu);
        foreach (array_keys($menu->nodes()) as $id) {
            $this->action($id)->setEnabled($states->effective($id));
        }
        $this->popover = GtkPopoverMenu::newFromModel($model);
        $this->popover->insertActionGroup('ctx', $actions);
        $this->popover->setHasArrow(false);
        $popover = $this->popover;
        g_signal_connect($popover, 'closed', static function () use ($popover): void {
            g_timeout_add(0, static function () use ($popover): bool {
                if (! is_null($popover->getParent())) {
                    $popover->unparent();
                }

                return false;
            });
        });
    }

    public function popover(): GtkPopoverMenu
    {
        return $this->popover;
    }

    /** The action of a node, by id. @throws WindowException */
    public function action(string $id): GSimpleAction
    {
        return $this->actions->lookupAction($id) ?? throw new WindowException("No node '{$id}' in this context menu.");
    }

    /** Show it over $parent, pointing at ($x, $y) in $parent's coordinates. */
    public function popupAt(GtkWidget $parent, float $x, float $y): void
    {
        $this->popover->setParent($parent);
        $this->popover->setPointingTo(new \GdkRectangle((int) round($x), (int) round($y), 1, 1));
        $this->popover->popup();
    }

    /** @param list<MenuItem> $items */
    private function section(array $items, GSimpleActionGroup $actions): GMenu
    {
        $menu = GMenu::new();
        $section = GMenu::new();

        foreach ($items as $item) {
            if ($item->separator) {
                $menu->appendSection(null, $section);
                $section = GMenu::new();
                continue;
            }

            if ($item->isFolder()) {
                $folder = GMenuItem::new($item->label, "ctx.{$item->id}");
                $folder->setSubmenu($this->section($item->items, $actions));
                $section->appendItem($folder);
                $actions->addAction(GSimpleAction::new($item->id, null));
                continue;
            }

            $section->append($item->label, "ctx.{$item->id}");
            $action = GSimpleAction::new($item->id, null);
            g_signal_connect($action, 'activate', fn () => $this->session->post(new MenuActivated($this->window, $item->id)));
            $actions->addAction($action);
        }

        $menu->appendSection(null, $section);

        return $menu;
    }
}
