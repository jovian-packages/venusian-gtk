<?php

namespace Jovian\Toolkits\GTK\Windows;

use GMenu;
use GMenuItem;
use GSimpleAction;
use GSimpleActionGroup;
use GtkPopoverMenu;
use GtkWidget;
use GVariant;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\ContextMenu;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuStates;
use WeakReference;

/**
 * A primitive's context menu, built for one right click: a GtkPopoverMenu over a GMenu model whose
 * items name "ctx" actions in a group on the popover itself, so the actions resolve wherever it is
 * parented. Separators become section boundaries, as in the menu bar. It points at the click, has
 * no arrow, and takes itself off its parent once it closes (after GTK has run the chosen action).
 * Each folder's item names its own action, so a node that is not effectively enabled shows disabled.
 * A menu button shows the same model and actions through its own popover: the context menu's
 * popover is made on first use.
 */
final class GTKContextMenu
{
    private ?GtkPopoverMenu $popover = null;

    private readonly GMenu $model;

    private readonly GSimpleActionGroup $actions;

    /** @var array<string, list<string>> group → item ids: each item has its own string action, its state the group's chosen id */
    private array $groups = [];

    /** @var array<string, string> group → the item on */
    private array $radios;

    /** @var WeakReference<object>|null the primitive or menu button whose menu this is: it keeps the user's choices */
    private readonly ?WeakReference $owner;

    /**
     * @param object|null $owner a TKPrimitive or TKMenuButton: told the user's toggle and radio choices
     *                           (nativeContextToggled(), nativeContextRadio()) before the mail; null keeps nothing (popupMenu)
     */
    public function __construct(
        ContextMenu $menu,
        private readonly string $window,
        private readonly GTKSession $session,
        ?object $owner = null,
    ) {
        $this->owner = is_null($owner) ? null : WeakReference::create($owner);
        $this->radios = $menu->radios();
        $actions = $this->actions = GSimpleActionGroup::new();
        $this->model = $this->section($menu->items, $actions);
        $states = new MenuStates($menu);
        foreach (array_keys($menu->nodes()) as $id) {
            $this->action($id)->setEnabled($states->effective($id));
        }
    }

    /** The items, each naming its "ctx" action. */
    public function model(): GMenu
    {
        return $this->model;
    }

    /** The actions the items name, to insert as "ctx". */
    public function actions(): GSimpleActionGroup
    {
        return $this->actions;
    }

    public function popover(): GtkPopoverMenu
    {
        if (! is_null($this->popover)) {
            return $this->popover;
        }
        $popover = $this->popover = GtkPopoverMenu::newFromModel($this->model);
        $popover->insertActionGroup('ctx', $this->actions);
        $popover->setHasArrow(false);
        g_signal_connect($popover, 'closed', static function () use ($popover): void {
            g_timeout_add(0, static function () use ($popover): bool {
                if (! is_null($popover->getParent())) {
                    $popover->unparent();
                }

                return false;
            });
        });

        return $popover;
    }

    /** The action of a node, by id. @throws WindowException */
    public function action(string $id): GSimpleAction
    {
        return $this->actions->lookupAction($id) ?? throw new WindowException("No node '{$id}' in this context menu.");
    }

    /** Show it over $parent, pointing at ($x, $y) in $parent's coordinates. */
    public function popupAt(GtkWidget $parent, float $x, float $y): void
    {
        $popover = $this->popover();
        $popover->setParent($parent);
        $popover->setPointingTo(new \GdkRectangle((int) round($x), (int) round($y), 1, 1));
        $popover->popup();
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

            $group = $item->radio();
            $section->append($item->label, is_null($group) ? "ctx.{$item->id}" : "ctx.{$item->id}::{$item->id}");
            if ($item->toggle) {
                $action = GSimpleAction::newStateful($item->id, null, GVariant::newBoolean($item->isOn()));
                // With no activate handler, activating a boolean stateful action asks to change its state.
                g_signal_connect($action, 'change-state', function (GSimpleAction $action, GVariant $value) use ($item): void {
                    $action->setState($value);
                    $this->owner?->get()?->nativeContextToggled($item->id, $value->getBoolean());
                    $this->session->post(new MenuToggled($this->window, $item->id, $value->getBoolean()));
                });
            } elseif (! is_null($group)) {
                $action = GSimpleAction::newStateful($item->id, 's', GVariant::newString($this->radios[$group]));
                g_signal_connect($action, 'change-state', function (GSimpleAction $action, GVariant $value) use ($group): void {
                    $chosen = (string) $value->getString();
                    if ($chosen === $this->radios[$group]) {
                        return;
                    }
                    $this->radios[$group] = $chosen;
                    foreach ($this->groups[$group] as $id) {
                        $this->actions->lookupAction($id)?->setState(GVariant::newString($chosen));
                    }
                    $this->owner?->get()?->nativeContextRadio($group, $chosen);
                    $this->session->post(new MenuRadioChosen($this->window, $group, $chosen));
                });
                $this->groups[$group][] = $item->id;
            } else {
                $action = GSimpleAction::new($item->id, null);
                g_signal_connect($action, 'activate', fn () => $this->session->post(new MenuActivated($this->window, $item->id)));
            }
            $actions->addAction($action);
        }

        $menu->appendSection(null, $section);

        return $menu;
    }
}
