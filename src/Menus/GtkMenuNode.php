<?php

namespace Jovian\Toolkits\GTK\Menus;

use GMenu;
use GMenuItem;
use GtkShortcut;
use GSimpleAction;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Toolkits\Menus\AboutPanel;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;
use Surface\Contracts\Toolkits\Menus\StandardKind;

/**
 * What a GtkMenuBar made for one node.
 *
 * @internal
 */
final class GtkMenuNode
{
    public readonly BarNodeKind $kind;

    public readonly ?StandardKind $standard;

    /** A standard item the application answers: GTK does none of its work. */
    public readonly bool $replaced;

    /** The menu macOS shows first, under the application's name. */
    public readonly bool $application;

    public readonly ?AboutPanel $panel;

    /** The node's action, and the name its items point to (with the scope). */
    public ?GSimpleAction $action = null;

    public ?string $detailed = null;

    /**
     * The items it puts in its menu: one, a choice's one per option, or none (a line, which
     * splits the menu's sections, or a kind GTK puts in the macOS application menu itself).
     * GMenu keeps a copy of each: these are kept to put back.
     *
     * @var list<GMenuItem>
     */
    public array $items = [];

    /** Whether its items are in its menu now. */
    public bool $placed = false;

    /** A menu's own submenu, holding its sections. */
    public ?GMenu $submenu = null;

    /** @var list<GMenu> a menu's sections, in order: its lines split them */
    public array $sections = [];

    /** @var list<string> a menu's nodes, in order */
    public array $children = [];

    public string $label;

    public ?Shortcut $shortcut;

    public bool $enabled;

    /** Shown, and never enabled: a kind GTK has no call for where it was put. */
    public bool $inert = false;

    /** The macOS application menu's own action GTK names (app.about, app.preferences, app.quit). */
    public ?string $special = null;

    /** A bare key's shortcut on its window, after the focused widget. */
    public ?GtkShortcut $late = null;

    public function __construct(BarNode $node, public ?string $parent)
    {
        $this->kind = $node->kind;
        $this->standard = $node->standard;
        $this->replaced = $node->replaced;
        $this->application = $node->application;
        $this->panel = $node->panel;
        $this->label = $node->label;
        $this->shortcut = $node->shortcut;
        $this->enabled = $node->enabled;
    }
}
