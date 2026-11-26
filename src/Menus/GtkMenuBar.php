<?php

namespace Jovian\Toolkits\GTK\Menus;

use GMenu;
use GVariant;
use GMenuItem;
use GtkWidget;
use GtkShortcut;
use GActionMap;
use GSimpleAction;
use GtkApplication;
use GtkCallbackAction;
use GtkPopoverMenuBar;
use GtkShortcutTrigger;
use GtkPropagationPhase;
use GtkShortcutController;
use WeakReference;
use RuntimeException;
use Jovian\Toolkits\GTK\GtkWindow;
use Jovian\Toolkits\GTK\GtkSession;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Toolkits\Menus\Runtime\ToolkitMenuBar;
use Surface\Contracts\Toolkits\ToolkitPoweredWindow;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;
use Surface\Contracts\Toolkits\Menus\StandardKind;

/**
 * A GMenu model per window, and one for when none is focused, with the GActions its items
 * point to. GTK shows the model itself: on Linux a GtkPopoverMenuBar inside the window, on
 * macOS the application's menubar, which show() sets to the focused window's model.
 *
 * A window's actions are the window's own ("win."): GTK resolves them in that window only,
 * and on macOS through the active window. The bar for no window has the application's ("app.").
 *
 * GTK draws a line between a menu's sections, so a line splits its menu into sections.
 *
 * The macOS application menu is GTK's: About, Settings, Services, Hide, Hide Others, Show All
 * and Quit, in GTK's order and with GTK's labels. Its About, Settings and Quit run the actions
 * app.about, app.preferences and app.quit; the shown bar's About, Preferences and Quit are those
 * actions, and GTK shows the item disabled while there is none. Any other node of the
 * application's own menu goes in an ordinary menu under the application's name, after GTK's.
 *
 * Shortcuts are GTK's application accelerators, which GTK runs before the focused widget. A
 * shortcut that yields to the focus is a shortcut on the window after the focused widget
 * instead, and the menu only shows it; so is an edit item's, which the widgets' own keys do.
 */
class GtkMenuBar extends ToolkitMenuBar
{
    /** The macOS application menu's actions GTK names, by the kind that is each. */
    private const array SPECIAL = [
        'about' => 'about',
        'preferences' => 'preferences',
        'quit' => 'quit',
    ];

    /** GTK's own actions on a focused widget for each edit kind, tried in order. */
    public const array EDIT_ACTIONS = [
        'undo' => ['text.undo'],
        'redo' => ['text.redo'],
        'cut' => ['clipboard.cut'],
        'copy' => ['clipboard.copy'],
        'paste' => ['clipboard.paste'],
        // GTK's text widgets have one paste, and it reads plain text.
        'paste-and-match-style' => ['clipboard.paste'],
        'select-all' => ['selection.select-all', 'list.select-all'],
    ];

    /** The bar GTK shows on macOS, of all the bars: show() makes it this one. */
    private static ?WeakReference $shown = null;

    protected GMenu $model;

    /** @var array<string, GtkMenuNode> */
    protected array $nodes = [];

    /** @var list<string> the bar's menus, in order */
    protected array $top = [];

    protected readonly bool $mac;

    /** "win" for a window's bar, "app" for the bar for no window. */
    protected readonly string $scope;

    /** Where its actions are: the window, or the application. */
    protected readonly GActionMap $actions;

    /** Linux: the bar in the window. */
    protected ?GtkPopoverMenuBar $widget = null;

    /** The window's shortcuts that run after the focused widget. */
    protected ?GtkShortcutController $late = null;

    public function __construct(GtkSession $session, ?ToolkitPoweredWindow $window = null, ?bool $mac = null)
    {
        parent::__construct($session, $window);

        $this->mac = $mac ?? device_os_family() === 'mac';
        $this->model = GMenu::new();

        if ($window instanceof GtkWindow) {
            $this->scope = 'win';
            $this->actions = $window->native();
            $this->late = GtkShortcutController::new();
            $this->late->setPropagationPhase(GtkPropagationPhase::BUBBLE);
            $window->native()->addController($this->late);
            if (! $this->mac) {
                $this->widget = GtkPopoverMenuBar::newFromModel($this->model);
                $window->attachMenuBar($this->widget);
            }
        } else {
            $this->scope = 'app';
            $this->actions = $this->application();
        }
    }

    public function model(): GMenu
    {
        return $this->model;
    }

    public function widget(): ?GtkPopoverMenuBar
    {
        return $this->widget;
    }

    /**
     * The action a node's items point to.
     */
    public function action(string $uuid): ?GSimpleAction
    {
        return $this->node($uuid)->action;
    }

    /**
     * The name a node's items point to, with its scope; or for a kind of the macOS application
     * menu, the one GTK's own item points to.
     */
    public function detailed(string $uuid): ?string
    {
        $node = $this->node($uuid);

        return is_null($node->special) ? $node->detailed : "app.{$node->special}";
    }

    public function isShown(): bool
    {
        return self::$shown?->get() === $this;
    }

    public function insert(?string $parent, int $index, BarNode $node): void
    {
        if (! is_null($parent) && $this->node($parent)->kind !== BarNodeKind::MENU) {
            throw new RuntimeException("Menu node '{$parent}' is not a menu: nothing goes inside it.");
        }

        $made = new GtkMenuNode($node, $parent);
        $this->nodes[$node->uuid] = $made;
        $siblings = &$this->siblings($parent);
        array_splice($siblings, $index, 0, [$node->uuid]);
        unset($siblings);

        match ($node->kind) {
            BarNodeKind::SEPARATOR => null,
            BarNodeKind::MENU => $this->makeMenu($made, $node),
            BarNodeKind::CHOICE => $this->makeChoice($made, $node),
            BarNodeKind::STANDARD => $this->makeStandard($made, $node),
            BarNodeKind::ITEM, BarNodeKind::TOGGLE => $this->makeItem($made, $node),
        };

        $this->applyEnabled($made, $node->enabled);
        $this->applyShortcut($made);

        if ($made->kind === BarNodeKind::SEPARATOR) {
            $this->lay($parent);
        } else {
            $this->put($made);
        }
        $this->showApplicationMenu();
    }

    public function remove(string $uuid): void
    {
        $node = $this->node($uuid);
        $this->takeOut($node);

        $siblings = &$this->siblings($node->parent);
        array_splice($siblings, (int) array_search($uuid, $siblings, true), 1);
        unset($siblings);

        if ($node->kind === BarNodeKind::SEPARATOR) {
            $this->lay($node->parent);
        }
        $this->forget($uuid);
        $this->showApplicationMenu();
    }

    public function move(string $uuid, ?string $parent, int $index): void
    {
        $node = $this->node($uuid);
        $this->takeOut($node);

        $from = &$this->siblings($node->parent);
        array_splice($from, (int) array_search($uuid, $from, true), 1);
        unset($from);
        $left = $node->parent;

        $node->parent = $parent;
        $to = &$this->siblings($parent);
        array_splice($to, $index, 0, [$uuid]);
        unset($to);

        if ($node->kind === BarNodeKind::SEPARATOR) {
            $this->lay($left);
            $this->lay($parent);
        } else {
            $this->put($node);
        }
        $this->showApplicationMenu();
    }

    public function change(string $uuid, array $changes): void
    {
        $node = $this->node($uuid);
        $relabel = false;

        if (array_key_exists('label', $changes) && $node->kind !== BarNodeKind::CHOICE && $node->kind !== BarNodeKind::SEPARATOR) {
            $node->label = $changes['label'];
            foreach ($node->items as $item) {
                $item->setLabel(self::literal($node->label));
            }
            $relabel = true;
        }
        if (array_key_exists('enabled', $changes)) {
            $this->applyEnabled($node, $changes['enabled']);
        }
        if (array_key_exists('on', $changes) && $node->kind === BarNodeKind::TOGGLE) {
            $node->action?->setState(GVariant::newBoolean($changes['on'] === true));
        }
        if (array_key_exists('chosen', $changes) && $node->kind === BarNodeKind::CHOICE) {
            $node->action?->setState(GVariant::newString((string) $changes['chosen']));
        }
        if (array_key_exists('shortcut', $changes)) {
            $this->dropShortcut($node);
            $node->shortcut = $changes['shortcut'];
            $relabel = $this->applyShortcut($node) || $relabel;
        }

        if ($relabel && $node->items !== []) {
            // GMenu keeps copies: the node's items are put in again.
            $this->takeOut($node);
            $this->put($node);
        }
    }

    /**
     * Linux: GTK shows each window's bar in the window, and this does nothing. macOS: the
     * application's menubar becomes this model, and the macOS application menu's actions and
     * the bar for no window's shortcuts become this bar's.
     */
    public function show(): void
    {
        if (! $this->mac) {
            return;
        }

        $last = self::$shown?->get();
        if ($last !== $this) {
            $last?->hidden();
            self::$shown = WeakReference::create($this);
            $this->shownNow();
        }

        $this->application()->setMenubar($this->model);
    }

    public function release(): void
    {
        if ($this->isShown()) {
            $this->hidden();
            self::$shown = null;
            $this->application()->setMenubar(null);
        }

        foreach ($this->top as $uuid) {
            $this->forget($uuid);
        }
        $this->model->removeAll();

        if ($this->window instanceof GtkWindow && $this->window->isOpen()) {
            $this->window->native()->removeController($this->late);
            if (! is_null($this->widget)) {
                $this->window->detachMenuBar($this->widget);
            }
        }

        [$this->nodes, $this->top, $this->widget, $this->late] = [[], [], null, null];
    }

    /**
     * ext-gtk binds no call that minimizes a window or makes it full screen.
     */
    public function supports(StandardKind $kind): bool
    {
        return ! in_array($kind, [StandardKind::MINIMIZE, StandardKind::FULLSCREEN], true);
    }

    /**
     * Run an edit kind on the focused widget of $window: the widget's own action. Whether one took it.
     */
    public static function edit(?\GtkWindow $window, StandardKind $kind): bool
    {
        return self::editWidget($window?->getFocus(), $kind);
    }

    /**
     * Run an edit kind on a widget: its own action. Whether it took it.
     */
    public static function editWidget(?GtkWidget $focus, StandardKind $kind): bool
    {
        if (is_null($focus) || ! $kind->isEdit()) {
            return false;
        }

        foreach (self::EDIT_ACTIONS[$kind->value] as $action) {
            if ($focus->activateActionVariant($action, null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Menu text as GTK shows it literally: it reads "_" as the mark of a mnemonic.
     */
    public static function literal(string $text): string
    {
        return str_replace('_', '__', $text);
    }

    /**
     * A node's action ran. A toggle's and a choice's state is topside's to set, through change().
     *
     * @internal
     */
    public function chose(string $uuid, bool|string|null $value = null): void
    {
        if (is_null($node = $this->nodes[$uuid] ?? null) || $node->inert) {
            return;
        }

        if ($node->kind === BarNodeKind::STANDARD && ! $node->replaced && $this->does($node)) {
            return;
        }

        $this->activated($uuid, $value);
    }

    /**
     * What GTK does by itself for a standard item. False when it is the application's to answer.
     */
    protected function does(GtkMenuNode $node): bool
    {
        $window = $this->window instanceof GtkWindow && $this->window->isOpen() ? $this->window->native() : null;

        if ($node->standard->isEdit()) {
            self::edit($window ?? $this->application()->getActiveWindow(), $node->standard);

            return true;
        }

        if ($node->standard === StandardKind::CLOSE_WINDOW) {
            // As the window's own close button: through close-request and its confirm. GTK sends
            // a window it never realized no close request, so that one takes the window's close.
            $window?->getRealized() ? $window->close() : $this->window?->close();

            return true;
        }

        if ($node->standard === StandardKind::ABOUT && ! is_null($node->panel)) {
            $this->gtk()->showAbout($node->panel, $window);

            return true;
        }

        return false;
    }

    protected function makeMenu(GtkMenuNode $made, BarNode $node): void
    {
        // An action with no handler: GTK reads a submenu's sensitivity from it.
        $made->action = $this->addAction(GSimpleAction::new($this->name($node->uuid), null), $made);
        $made->submenu = GMenu::new();
        $made->sections = [GMenu::new()];
        $made->submenu->appendSection(null, $made->sections[0]);

        $item = GMenuItem::new(self::literal($node->label), $made->detailed);
        $item->setSubmenu($made->submenu);
        $made->items = [$item];
    }

    protected function makeChoice(GtkMenuNode $made, BarNode $node): void
    {
        // One action, its state the option chosen; GTK marks the item whose target it is.
        $action = GSimpleAction::newStateful($this->name($node->uuid), 's', GVariant::newString((string) $node->chosen));
        $this->onChange($action, $node->uuid, static fn (GVariant $value): string => (string) $value->getString());
        $this->addAction($action, $made);

        foreach ($node->options as $value => $label) {
            $item = GMenuItem::new(self::literal((string) $label), null);
            $item->setActionAndTargetValue($made->detailed, GVariant::newString((string) $value));
            $made->items[] = $item;
        }
    }

    protected function makeStandard(GtkMenuNode $made, BarNode $node): void
    {
        if ($this->merges($made->parent)) {
            if (! $node->replaced && $node->standard->isMacOnly()) {
                return;    // GTK puts its own in the application menu
            }
            if (array_key_exists($node->standard->value, self::SPECIAL)) {
                // GTK's own item runs the action of this name.
                $made->special = self::SPECIAL[$node->standard->value];
                $made->action = GSimpleAction::new($made->special, null);
                $this->onActivate($made->action, $node->uuid);

                return;
            }
        }

        if (! $node->replaced && $node->standard->isMacOnly()) {
            $made->inert = true;    // GTK has no call for it anywhere else
        }

        $this->makeItem($made, $node);
    }

    protected function makeItem(GtkMenuNode $made, BarNode $node): void
    {
        if ($node->kind === BarNodeKind::TOGGLE) {
            // A boolean state: activating it asks to change the state, which topside sets.
            $action = GSimpleAction::newStateful($this->name($node->uuid), null, GVariant::newBoolean($node->on === true));
            $this->onChange($action, $node->uuid, static fn (GVariant $value): bool => $value->getBoolean());
        } else {
            $action = GSimpleAction::new($this->name($node->uuid), null);
            $this->onActivate($action, $node->uuid);
        }

        $this->addAction($action, $made);
        $made->items = [GMenuItem::new(self::literal($node->label), $made->detailed)];
    }

    /**
     * Whether what is put under $parent goes to GTK's macOS application menu.
     */
    protected function merges(?string $parent): bool
    {
        return $this->mac && ! is_null($parent) && $this->node($parent)->application;
    }

    protected function name(string $uuid): string
    {
        return "menu-{$uuid}";
    }

    protected function addAction(GSimpleAction $action, GtkMenuNode $node): GSimpleAction
    {
        $this->actions->addAction($action);
        $node->action = $action;
        $node->detailed = "{$this->scope}.{$action->getName()}";

        return $action;
    }

    /**
     * The handlers hold the bar weakly: the bar holds the action.
     */
    protected function onActivate(GSimpleAction $action, string $uuid): void
    {
        $bar = WeakReference::create($this);
        g_signal_connect($action, 'activate', static function () use ($bar, $uuid): void {
            $bar->get()?->chose($uuid);
        });
    }

    protected function onChange(GSimpleAction $action, string $uuid, callable $read): void
    {
        $bar = WeakReference::create($this);
        g_signal_connect($action, 'change-state', static function (GSimpleAction $action, GVariant $value) use ($bar, $uuid, $read): void {
            $bar->get()?->chose($uuid, $read($value));
        });
    }

    protected function applyEnabled(GtkMenuNode $node, bool $enabled): void
    {
        $node->enabled = $enabled;
        $node->action?->setEnabled($enabled && ! $node->inert);

        // GtkPopoverMenuBar does not read a top-level menu's sensitivity from its action.
        if (! is_null($this->widget) && is_null($node->parent) && $node->kind === BarNodeKind::MENU) {
            $this->topLevelWidget($node)?->setSensitive($enabled);
        }
    }

    /**
     * Whether the menu shows the shortcut in an item attribute: it is put in again then.
     */
    protected function applyShortcut(GtkMenuNode $node): bool
    {
        if (is_null($node->shortcut) || is_null($node->action) || in_array($node->kind, [BarNodeKind::MENU, BarNodeKind::CHOICE, BarNodeKind::SEPARATOR], true)) {
            return false;    // a kind GTK has its own item for has no action here
        }

        $accelerator = GtkAccelerator::accelerator($node->shortcut, $this->mac);
        $edit = $node->kind === BarNodeKind::STANDARD && ! $node->replaced && $node->standard->isEdit();

        if (! is_null($node->special)) {
            if ($this->isShown()) {
                $this->application()->setAccelsForAction("app.{$node->special}", [$accelerator]);
            }

            return false;
        }

        if (! $edit && ! $node->shortcut->yieldsToFocus()) {
            if ($this->scope === 'win' || $this->isShown()) {
                $this->application()->setAccelsForAction($node->detailed, [$accelerator]);
            }

            return false;
        }

        // Shown only. An edit item's keys are the focused widget's own; a bare key goes to the
        // focused widget first and to this window's shortcut after it.
        foreach ($node->items as $item) {
            $item->setAttributeValue('accel', GVariant::newString($accelerator));
        }
        if (! $edit && ! is_null($this->late) && ! is_null($trigger = GtkShortcutTrigger::parseString($accelerator))) {
            $bar = WeakReference::create($this);
            $uuid = array_search($node, $this->nodes, true);
            $node->late = GtkShortcut::new($trigger, GtkCallbackAction::new(static function () use ($bar, $uuid): bool {
                return $bar->get()?->lateKey((string) $uuid) ?? false;
            }));
            $this->late->addShortcut($node->late);
        }

        return true;
    }

    /**
     * @internal
     */
    public function lateKey(string $uuid): bool
    {
        $node = $this->nodes[$uuid] ?? null;
        if (is_null($node?->action) || ! $node->action->getEnabled()) {
            return false;
        }
        $node->action->activate(null);

        return true;
    }

    protected function dropShortcut(GtkMenuNode $node): void
    {
        if (! is_null($node->late)) {
            $this->late?->removeShortcut($node->late);
            $node->late = null;
        }
        foreach ($node->items as $item) {
            $item->setAttributeValue('accel', null);
        }
        if (! is_null($node->detailed) && is_null($node->special)) {
            $this->application()->setAccelsForAction($node->detailed, []);
        }
    }

    /**
     * Put a node's items in its menu at its place.
     */
    protected function put(GtkMenuNode $node): void
    {
        if ($node->items === [] || (is_null($node->parent) && $this->hiddenApplicationMenu($node))) {
            return;
        }

        [$section, $at] = $this->placeOf($node);
        foreach ($node->items as $item) {
            $section->insertItem($at++, $item);
        }
        $node->placed = true;

        if (! is_null($this->widget) && is_null($node->parent) && $node->kind === BarNodeKind::MENU) {
            $this->topLevelWidget($node)?->setSensitive($node->enabled);
        }
    }

    /**
     * Take a node's items out of its menu, if they are in.
     */
    protected function takeOut(GtkMenuNode $node): void
    {
        if (! $node->placed) {
            return;
        }

        [$section, $at] = $this->placeOf($node);
        foreach ($node->items as $ignored) {
            $section->remove($at);
        }
        $node->placed = false;
    }

    /**
     * The section a node's items go in and the position of the first: after the items of the
     * siblings before it since the last line.
     *
     * @return array{GMenu, int}
     */
    protected function placeOf(GtkMenuNode $node): array
    {
        $siblings = $this->siblings($node->parent);
        $section = 0;
        $at = 0;
        foreach ($siblings as $uuid) {
            $sibling = $this->nodes[$uuid];
            if ($sibling === $node) {
                break;
            }
            if ($sibling->kind === BarNodeKind::SEPARATOR && ! is_null($node->parent)) {
                [$section, $at] = [$section + 1, 0];
                continue;
            }
            if ($sibling->placed) {
                $at += count($sibling->items);
            }
        }

        $host = is_null($node->parent) ? $this->model : $this->nodes[$node->parent]->sections[$section];

        return [$host, $at];
    }


    /**
     * Lay a menu's sections out again from its nodes: a line came, went or moved.
     */
    protected function lay(?string $menu): void
    {
        if (is_null($menu)) {
            return;    // the bar has no sections
        }

        $node = $this->nodes[$menu];
        $node->submenu->removeAll();
        $node->sections = [GMenu::new()];
        foreach ($node->children as $uuid) {
            $child = $this->nodes[$uuid];
            if ($child->kind === BarNodeKind::SEPARATOR) {
                $node->sections[] = GMenu::new();
                continue;
            }
            foreach ($child->items as $item) {
                end($node->sections)->appendItem($item);
            }
            $child->placed = $child->items !== [];
        }
        foreach ($node->sections as $section) {
            $node->submenu->appendSection(null, $section);
        }
    }

    /**
     * macOS: the application's own menu is in the bar only while something in it is not
     * GTK's: GTK's own items are in GTK's application menu.
     */
    protected function hiddenApplicationMenu(GtkMenuNode $node): bool
    {
        if (! $this->mac || ! $node->application) {
            return false;
        }

        foreach ($node->children as $uuid) {
            $child = $this->nodes[$uuid];
            if ($child->kind !== BarNodeKind::SEPARATOR && $child->items !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * After a change in the application's own menu: it comes into or goes from the bar.
     */
    protected function showApplicationMenu(): void
    {
        if (! $this->mac) {
            return;
        }

        foreach ($this->top as $uuid) {
            $node = $this->nodes[$uuid];
            if (! $node->application) {
                continue;
            }

            $hidden = $this->hiddenApplicationMenu($node);
            if ($hidden && $node->placed) {
                $this->takeOut($node);
            } elseif (! $hidden && ! $node->placed) {
                $this->put($node);
            }
        }
    }

    protected function modelIndex(GtkMenuNode $node): int
    {
        $at = 0;
        foreach ($this->top as $uuid) {
            if ($this->nodes[$uuid] === $node) {
                return $at;
            }
            $at += $this->nodes[$uuid]->placed ? 1 : 0;
        }

        return $at;
    }


    /**
     * Linux: the bar widget's button for a top-level menu, by its place.
     */
    protected function topLevelWidget(GtkMenuNode $node): ?GtkWidget
    {
        $child = $this->widget?->getFirstChild();
        for ($at = $this->modelIndex($node); $at > 0 && ! is_null($child); $at--) {
            $child = $child->getNextSibling();
        }

        return $child;
    }

    /**
     * Drop a node and everything under it, and its actions and shortcuts.
     */
    protected function forget(string $uuid): void
    {
        $node = $this->node($uuid);
        foreach ($node->children as $child) {
            $this->forget($child);
        }

        $this->dropShortcut($node);
        if (! is_null($node->special)) {
            if ($this->isShown()) {
                $this->application()->removeAction($node->special);
            }
        } elseif (! is_null($node->action)) {
            $this->actions->removeAction($node->action->getName());
        }
        unset($this->nodes[$uuid]);
    }

    /**
     * macOS: this bar is shown from now. Its About, Preferences and Quit are GTK's actions, and
     * the bar for no window's shortcuts are on.
     */
    protected function shownNow(): void
    {
        foreach ($this->nodes as $node) {
            if (! is_null($node->special)) {
                $this->application()->addAction($node->action);
                $this->applyShortcut($node);
            } elseif ($this->scope === 'app') {
                $this->applyShortcut($node);
            }
        }
    }

    /**
     * macOS: another bar is shown from now.
     */
    protected function hidden(): void
    {
        foreach ($this->nodes as $node) {
            if (! is_null($node->special)) {
                $this->application()->removeAction($node->special);
                $this->application()->setAccelsForAction("app.{$node->special}", []);
            } elseif ($this->scope === 'app' && ! is_null($node->detailed)) {
                $this->application()->setAccelsForAction($node->detailed, []);
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function &siblings(?string $parent): array
    {
        if (is_null($parent)) {
            return $this->top;
        }

        $node = $this->node($parent);

        return $node->children;
    }

    protected function node(string $uuid): ?GtkMenuNode
    {
        if ($uuid === '') {
            return null;
        }

        return $this->nodes[$uuid] ?? throw new RuntimeException("This bar has no menu node '{$uuid}'.");
    }

    protected function application(): GtkApplication
    {
        return $this->gtk()->application()->get();
    }

    protected function gtk(): GtkSession
    {
        /** @var GtkSession */
        return $this->session;
    }
}
