<?php

namespace Jovian\Toolkits\GTK\Windows;

use GMenu;
use GMenuItem;
use GSimpleAction;
use GSimpleActionGroup;
use GtkApplication;
use GtkPopoverMenuBar;
use GtkWindow;
use GVariant;
use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\Menus\MenuRole;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\Accelerator;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Menus\MenuStates;

/**
 * A menu profile built once into a GMenu model and the actions its items name: "win"
 * actions for a window's bar, "app" actions for the app's default bar (macOS), which
 * works with no window. Separators become section boundaries, which is how GTK draws
 * them; toggles are stateful boolean actions. Every item posts mail but the edit roles,
 * which run the focused widget's own action; About shows a non-modal dialog.
 */
class GTKMenuBar
{
    /** GTK's own actions on a focused widget for each edit role, tried in order: text widgets first, then lists. */
    public const array EDIT_ACTIONS = [
        'undo' => ['text.undo'],
        'redo' => ['text.redo'],
        'cut' => ['clipboard.cut'],
        'copy' => ['clipboard.copy'],
        'paste' => ['clipboard.paste'],
        'select-all' => ['selection.select-all', 'list.select-all'],
    ];

    /** Run an edit role on the widget with focus in $window; whether a widget took it. */
    public static function edit(?GtkWindow $window, MenuRole $role): bool
    {
        $focus = $window?->getFocus();
        if (is_null($focus)) {
            return false;
        }
        foreach (self::EDIT_ACTIONS[$role->value] as $action) {
            if ($focus->activateActionVariant($action, null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The action scope its items name: "app" for the default bar and for every bar on macOS (GTK's
     * macOS exporter reads item states through the application's actions only; the driver holds
     * the shown bar's actions in the app), "win" for a window's bar on Linux.
     * @var string
     */
    protected readonly string $scope;

    /**
     * Hotkeys by detailed action name, applied while the accelerators are on.
     * @var array<string, string>
     */
    protected array $accels = [];

    protected GMenu $model;

    protected GSimpleActionGroup $actions;

    /**
     * Toggle actions by item id.
     * @var array<string, GSimpleAction>
     */
    protected array $toggles = [];

    /**
     * Each radio group's item ids: every item has its own action, whose state is the group's chosen id.
     * @var array<string, list<string>>
     */
    protected array $groups = [];

    /**
     * Where each node's GMenuItem sits: its section (the model for a top-level folder) and index, for a relabel.
     * @var array<string, array{GMenu, int}>
     */
    protected array $places = [];

    /** @var array<string, GMenu> each folder's submenu */
    protected array $submenus = [];

    /** The own and effective state of each node, toggles, radios and labels. */
    protected readonly MenuStates $states;

    /** The bar widget, once a window asks for one (Linux). */
    protected ?GtkPopoverMenuBar $widget = null;

    /** While a child window is active: every item's action but the edit roles is disabled. */
    protected bool $blocked = false;

    /**
     * @param GTKSession $session
     * @param MenuProfile $profile
     * @param string|null $window the window it belongs to; null for the app's default bar, whose items post '' (Quit posts null)
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     * @param GtkWindow|null $parent the window About is shown over
     * @param MenuStates|null $states the states to build from and keep: the driver's for the default bar, which outlives a rebuild; null starts from the profile
     */
    public function __construct(
        protected readonly GTKSession $session,
        protected readonly MenuProfile $profile,
        protected readonly ?string $window,
        protected readonly array $about,
        protected readonly ?GtkWindow $parent,
        ?MenuStates $states = null,
    ) {
        $this->scope = is_null($window) || GTKBridgeDriver::appWideBar() ? 'app' : 'win';
        $this->model = GMenu::new();
        $this->actions = GSimpleActionGroup::new();
        $this->states = $states ?? new MenuStates($profile);

        foreach ($profile->folders as $folder) {
            $this->placeAt($folder->id, $this->model);
            $this->model->appendItem($this->folderItem($folder));
        }

        foreach (array_keys($profile->nodes()) as $id) {
            $this->apply($id);
        }
    }

    /**
     * Turn this bar's hotkeys on or off in the application's accelerator table. On Linux a window's
     * bar keeps them on: "win" actions resolve in the focused window only. On macOS every bar names
     * "app" actions, and a hotkey fires the action the application holds, the shown bar's; the
     * driver turns a bar's hotkeys off as its actions leave. The default bar has them on only while
     * it is the bar shown, so a hotkey never names both a window's and its action.
     * @param bool $on
     * @return void
     */
    public function setAccelerators(bool $on): void
    {
        foreach ($this->accels as $action => $accel) {
            $this->session->application()->setAccelsForAction($action, $on ? [$accel] : []);
        }
    }

    /**
     * Put the actions in the application's own map, where "app" actions are looked up.
     * @param GtkApplication $application
     * @return void
     */
    public function addTo(GtkApplication $application): void
    {
        foreach ($this->actions->listActions() as $name) {
            $application->addAction($this->actions->lookupAction($name));
        }
    }

    /**
     * Take the actions back out of the application's map and turn the hotkeys off.
     * @param GtkApplication $application
     * @return void
     */
    public function removeFrom(GtkApplication $application): void
    {
        $this->setAccelerators(false);

        foreach ($this->actions->listActions() as $name) {
            $application->removeAction($name);
        }
    }

    /** The GDK name of each accelerator key that types no character of its own name. */
    public const array GDK_NAMES = [
        'minus' => 'minus', 'equals' => 'equal', 'left_bracket' => 'bracketleft', 'right_bracket' => 'bracketright',
        'backslash' => 'backslash', 'semicolon' => 'semicolon', 'apostrophe' => 'apostrophe', 'grave' => 'grave',
        'comma' => 'comma', 'period' => 'period', 'slash' => 'slash',
        'up' => 'Up', 'down' => 'Down', 'left' => 'Left', 'right' => 'Right', 'home' => 'Home', 'end' => 'End',
        'page_up' => 'Page_Up', 'page_down' => 'Page_Down',
        'space' => 'space', 'enter' => 'Return', 'tab' => 'Tab', 'escape' => 'Escape', 'backspace' => 'BackSpace', 'delete' => 'Delete',
    ];

    /**
     * The GTK accelerator string: Command is GDK's Meta on macOS, Super is Super; GTK's <Primary>
     * is Control on every platform, so the driver resolves Primary itself.
     */
    public static function accelerator(Accelerator $keys, bool $macos = PHP_OS_FAMILY === 'Darwin'): string
    {
        $key = $keys->key;
        $name = self::GDK_NAMES[$key->value] ?? match (true) {
            str_starts_with($key->value, 'digit_') => substr($key->value, 6),
            str_starts_with($key->value, 'f') && strlen($key->value) > 1 => strtoupper($key->value),
            default => $key->value,
        };

        return ($keys->command($macos) ? '<Meta>' : '').($keys->control($macos) ? '<Control>' : '').($keys->super($macos) ? '<Super>' : '')
            .($keys->alt ? '<Alt>' : '').($keys->shift ? '<Shift>' : '').$name;
    }

    /**
     * Set the state of a node; the node and the nodes below it show their effective state. Posts nothing.
     * @throws WindowException
     */
    public function setItemEnabled(string $item, bool $enabled): void
    {
        $this->states->set($item, $enabled);
        foreach ($this->states->subtree($item) as $id) {
            $this->apply($id);
        }
    }

    /** @throws WindowException */
    public function isItemEnabled(string $item): bool
    {
        return $this->states->isEnabled($item);
    }

    /**
     * Block the bar while a child window is active, or unblock it: every item's action but the
     * edit roles is disabled, which also stops its accelerator; folders keep their state. The
     * states do not change. Posts nothing.
     */
    public function block(bool $blocked): void
    {
        if ($blocked === $this->blocked) {
            return;
        }
        $this->blocked = $blocked;
        foreach (array_keys($this->profile->nodes()) as $id) {
            $this->apply($id);
        }
    }

    public function model(): GMenu
    {
        return $this->model;
    }

    public function actions(): GSimpleActionGroup
    {
        return $this->actions;
    }

    /**
     * A bar widget showing the model, for windows that carry their own (Linux).
     * @return GtkPopoverMenuBar
     */
    public function widget(): GtkPopoverMenuBar
    {
        if (is_null($this->widget)) {
            $this->widget = GtkPopoverMenuBar::newFromModel($this->model);
            foreach ($this->profile->folders as $folder) {
                $this->apply($folder->id);
            }
        }

        return $this->widget;
    }

    /**
     * One item's action, by its profile id.
     * @param string $id
     * @return GSimpleAction
     * @throws WindowException
     */
    public function action(string $id): GSimpleAction
    {
        return $this->actions->lookupAction($id) ?? throw new WindowException("No item '{$id}' in menu profile '{$this->profile->name}'.");
    }

    public function setToggle(string $item, bool $on): void
    {
        $action = $this->toggle($item);
        $this->states->setToggle($item, $on);
        $action->setState(GVariant::newBoolean($on));
    }

    public function isToggled(string $item): bool
    {
        $this->toggle($item);

        return $this->states->toggle($item);
    }

    /** @throws WindowException When no radio group has the name, or the item is not in it. */
    public function setRadio(string $group, string $item): void
    {
        $this->states->setRadio($group, $item);
        $this->showRadio($group);
    }

    /** @throws WindowException When no radio group has the name. */
    public function radio(string $group): string
    {
        return $this->states->radio($group);
    }

    /**
     * GMenu items are immutable: the node's item is replaced at its index in its section, carrying
     * the new label and the same action, target and submenu. A top-level folder's sensitivity in the
     * bar widget is applied again, its button being made anew.
     *
     * @throws WindowException When no node has the id.
     */
    public function setItemLabel(string $item, string $label): void
    {
        $this->states->setLabel($item, $label);
        [$section, $index] = $this->place($item);
        $node = $this->profile->nodes()[$item];
        $section->remove($index);
        $section->insertItem($index, $node->isFolder() ? $this->folderEntry($node) : $this->leafItem($node));
        if ($section === $this->model) {
            $this->apply($item);
        }
        // GTK's macOS exporter applies a model change by index into the native menu, where macOS
        // has put items of its own (Show Tab Bar atop a View menu): the shown bar is built anew.
        $application = $this->session->application();
        if (GTKBridgeDriver::appWideBar() && $application->getMenubar() === $this->model) {
            $application->setMenubar(null);
            $application->setMenubar($this->model);
        }
    }

    /** @throws WindowException When no node has the id. */
    public function itemLabel(string $item): string
    {
        return $this->states->label($item);
    }

    /**
     * A node's section and index.
     *
     * @return array{GMenu, int}
     * @throws WindowException When no node has the id.
     */
    public function place(string $id): array
    {
        return $this->places[$id] ?? throw new WindowException("No node '{$id}' in menu profile '{$this->profile->name}'.");
    }

    /** Every action of $group takes the group's chosen id as its state. */
    protected function showRadio(string $group): void
    {
        $chosen = GVariant::newString($this->states->radio($group));
        foreach ($this->groups[$group] as $id) {
            $this->action($id)->setState($chosen);
        }
    }

    protected function placeAt(string $id, GMenu $section): void
    {
        $this->places[$id] = [$section, $section->getNItems()];
    }

    /** A leaf's item: its label, and its action with its own id as the target for a radio item. */
    protected function leafItem(MenuItem $item): GMenuItem
    {
        $detailed = is_null($item->radio()) ? "{$this->scope}.{$item->id}" : "{$this->scope}.{$item->id}::{$item->id}";

        return GMenuItem::new($this->states->label($item->id), $detailed);
    }

    /** A folder's item over its submenu, built once. */
    protected function folderEntry(MenuItem $folder): GMenuItem
    {
        $item = GMenuItem::new($this->states->label($folder->id), "{$this->scope}.{$folder->id}");
        $item->setSubmenu($this->submenus[$folder->id]);

        return $item;
    }

    protected function toggle(string $item): GSimpleAction
    {
        return $this->toggles[$item] ?? throw new WindowException("No toggle item '{$item}' in menu profile '{$this->profile->name}'.");
    }

    protected function folder(MenuItem $folder): GMenu
    {
        $menu = GMenu::new();
        $section = GMenu::new();

        foreach ($folder->items as $item) {
            if ($item->separator) {
                $menu->appendSection(null, $section);
                $section = GMenu::new();
                continue;
            }

            $this->placeAt($item->id, $section);
            if ($item->isFolder()) {
                $section->appendItem($this->folderItem($item));
                continue;
            }

            $section->appendItem($this->leafItem($item));
            $this->actions->addAction($this->actionFor($item));

            if (! is_null($item->keys)) {
                $this->accels["{$this->scope}.{$item->id}"] = self::accelerator($item->keys);
            }
        }

        $menu->appendSection(null, $section);

        return $menu;
    }

    /** A folder as a menu item whose action attribute names its own action, which GTK's menu tracker reads its sensitivity from. */
    protected function folderItem(MenuItem $folder): GMenuItem
    {
        $this->submenus[$folder->id] = $this->folder($folder);
        $this->actions->addAction(GSimpleAction::new($folder->id, null));

        return $this->folderEntry($folder);
    }

    /**
     * Show a node's effective state: its action, and for a top-level folder in the bar widget the
     * bar's child at the folder's index, which GtkPopoverMenuBar does not bind to the action.
     */
    protected function apply(string $id): void
    {
        $node = $this->profile->nodes()[$id];
        $effective = $this->states->effective($id)
            && ! ($this->blocked && ! $node->isFolder() && $node->role?->isEdit() !== true);
        $this->action($id)->setEnabled($effective);
        $index = array_search($id, array_map(fn (MenuItem $folder): string => $folder->id, $this->profile->folders), true);
        if ($index !== false && ! is_null($this->widget)) {
            $child = $this->widget->getFirstChild();
            for ($i = 0; $i < $index && ! is_null($child); $i++) {
                $child = $child->getNextSibling();
            }
            $child?->setSensitive($effective);
        }
    }

    protected function actionFor(MenuItem $item): GSimpleAction
    {
        $window = $this->window ?? '';

        if ($item->toggle) {
            $action = GSimpleAction::newStateful($item->id, null, GVariant::newBoolean($this->states->toggle($item->id)));
            // With no activate handler, activating a boolean stateful action asks to change its state.
            g_signal_connect($action, 'change-state', function (GSimpleAction $action, GVariant $value) use ($item, $window): void {
                $this->states->setToggle($item->id, $value->getBoolean());
                $action->setState($value);
                $this->session->post(new MenuToggled($window, $item->id, $value->getBoolean()));
            });
            $this->toggles[$item->id] = $action;

            return $action;
        }

        $group = $item->radio();
        if (! is_null($group)) {
            // A string action per item, its state the group's chosen id: GTK draws the item as chosen when the state is its target.
            $action = GSimpleAction::newStateful($item->id, 's', GVariant::newString($this->states->radio($group)));
            g_signal_connect($action, 'change-state', function (GSimpleAction $action, GVariant $value) use ($group, $window): void {
                $chosen = (string) $value->getString();
                if ($chosen === $this->states->radio($group)) {
                    return;
                }
                $this->states->setRadio($group, $chosen);
                $this->showRadio($group);
                $this->session->post(new MenuRadioChosen($window, $group, $chosen));
            });
            $this->groups[$group][] = $item->id;

            return $action;
        }

        if ($item->role?->isEdit() === true) {
            $action = GSimpleAction::new($item->id, null);
            // The bar's window, or for the app's default bar the window the user is in.
            g_signal_connect($action, 'activate', fn () => self::edit($this->parent ?? $this->session->application()->getActiveWindow(), $item->role));

            return $action;
        }

        $action = GSimpleAction::new($item->id, null);
        g_signal_connect($action, 'activate', match ($item->role) {
            MenuRole::ABOUT => function (): void {
                $this->session->showAbout($this->about, $this->parent);
            },
            MenuRole::QUIT => fn () => $this->session->post(new QuitRequested($this->window)),
            default => fn () => $this->session->post(new MenuActivated($window, $item->id)),
        });

        return $action;
    }
}
