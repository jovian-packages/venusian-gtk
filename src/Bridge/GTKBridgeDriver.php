<?php

namespace Jovian\Toolkits\GTK\Bridge;

use Jovian\Toolkits\GTK\Contracts\Bridge\GTKBridgeDriver as BridgeContract;
use Jovian\Toolkits\GTK\Services\GTKClipboard;
use Jovian\Toolkits\GTK\Services\GTKDesktop;
use Jovian\Toolkits\GTK\Services\GTKDialogs;
use Jovian\Toolkits\GTK\Windows\GTKMenuBar;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Bridge\BridgeException;
use Surface\Contracts\Windows\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\ToolkitWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\ChildWindows;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Menus\MenuStates;

class GTKBridgeDriver extends ToolkitBridgeDriver implements BridgeContract, ToolkitWindowDriver
{
    /**
     * Open windows by name; a window leaves when it closes.
     * @var array<string, GTKWindow>
     */
    protected array $windows = [];

    /**
     * macOS: the application menubar while none of our windows is active. Linux has no app-wide bar.
     * @var MenuProfile|null
     */
    protected ?MenuProfile $default_menu = null;

    protected ?GTKMenuBar $default_bar = null;

    /** macOS: the bar whose actions the application holds, the one in the app bar. */
    protected ?GTKMenuBar $held = null;

    /** The states of the default profile's nodes, kept for a default bar built later. */
    protected ?MenuStates $default_states = null;

    protected ?GTKDialogs $dialogs = null;

    protected ?GTKClipboard $clipboard = null;

    protected ?GTKDesktop $desktop = null;

    public function connect(): GTKSession
    {
        $this->session ??= new GTKSession(
            $this->applicationId(),
            (bool) $this->app->get('config')->get('bridge.gtk.unique', false),
        );

        return $this->session->connect();
    }

    /** The desktop identity: config/app.php app.id, the name a packaged build's .desktop file carries. */
    protected function applicationId(): string
    {
        $id = $this->app->get('config')->get('app.id');

        if (! is_string($id) || $id === '') {
            throw new BridgeException("config/app.php has no app.id; add 'id' => env('APP_ID', 'com.venusian.app') under name.");
        }

        return $id;
    }

    /** With a parent, the window is transient for it and modal, in its window group. */
    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null, ?ToolkitWindow $parent = null): GTKWindow
    {
        if (isset($this->windows[$name])) {
            throw new WindowException("A window named '{$name}' is already open.");
        }

        if (! is_null($parent)) {
            ChildWindows::check($this, $parent, $menu);
            $this->dialogs()->refuseChild($parent);
        }

        return $this->windows[$name] = new GTKWindow($name, $this->connect(), $this, $width, $height, $this->concrete($menu), $parent);
    }

    /** Alert and file dialogs, modal to their parent. */
    public function dialogs(): GTKDialogs
    {
        return $this->dialogs ??= new GTKDialogs($this, $this->connect());
    }

    /** The display's clipboard; GTK starts with the session, so the session connects first. */
    public function clipboard(): GTKClipboard
    {
        $this->connect();

        return $this->clipboard ??= new GTKClipboard();
    }

    /** GtkFileLauncher is GTK too: the session connects first. */
    public function desktop(): GTKDesktop
    {
        $this->connect();

        return $this->desktop ??= new GTKDesktop();
    }

    public function has(string $name): bool
    {
        return isset($this->windows[$name]);
    }

    public function get(string $name): ?GTKWindow
    {
        return $this->windows[$name] ?? null;
    }

    /**
     * @return array<string, GTKWindow>
     */
    public function all(): array
    {
        return $this->windows;
    }

    public function closeAll(): void
    {
        foreach ($this->windows as $window) {
            $window->close();
        }
    }

    public function setDefaultMenuBar(?MenuProfileContract $menu): void
    {
        $menu = $this->concrete($menu);
        // The window manager passes the configured profile on every open: the same profile keeps the bar built.
        if ($menu === $this->default_menu) {
            return;
        }

        $this->default_menu = $menu;
        $this->default_states = is_null($menu) ? null : new MenuStates($menu);

        if (! is_null($this->default_bar)) {
            if ($this->held === $this->default_bar) {
                $this->hold(null);
            } else {
                $this->default_bar->setAccelerators(false);
            }
            $this->default_bar = null;
        }

        $this->showDefaultMenuBar();
    }

    /**
     * macOS keeps one bar for the app: with none of our windows active it shows the default
     * profile. Linux bars live inside windows, so there is nothing to show.
     * @return void
     */
    public function showDefaultMenuBar(): void
    {
        foreach ($this->windows as $window) {
            if ($window->isActive()) {
                return;
            }
        }

        $this->installDefaultMenuBar();
    }

    /**
     * macOS: put the default bar in the app bar, with no active window or for an active window
     * that has no bar of its own. Its items are "app" actions, so they work with no window.
     * Blocked (a child window is active), every action but the edit roles is disabled.
     * @param bool $blocked
     * @return void
     */
    public function installDefaultMenuBar(bool $blocked = false): void
    {
        if (! self::appWideBar() || is_null($this->session)) {
            return;
        }

        $application = $this->session->application();

        if (is_null($this->default_menu)) {
            $this->hold(null);
            $application->setMenubar(null);
            return;
        }

        if (is_null($this->default_bar)) {
            // The bar builds from the driver's states and keeps them: what was set before, and the user's choices since, survive a rebuild.
            $this->default_bar = new GTKMenuBar($this->session, $this->default_menu, null, $this->about(), null, $this->default_states);
        }

        $this->hold($this->default_bar);
        $this->default_bar->block($blocked);
        $this->default_bar->setAccelerators(true);
        $application->setMenubar($this->default_bar->model());
    }

    /**
     * Set the state of a node of the default profile, on every platform; it shows where a default
     * bar shows (macOS), and a bar built later starts from it. Posts nothing.
     * @throws WindowException
     */
    public function setItemEnabled(string $item, bool $enabled): void
    {
        $this->defaultStates()->set($item, $enabled);
        $this->default_bar?->setItemEnabled($item, $enabled);
    }

    /** @throws WindowException */
    public function isItemEnabled(string $item): bool
    {
        return $this->defaultStates()->isEnabled($item);
    }

    /**
     * The default bar's toggles, radios and labels: kept in its states, which a bar built later
     * (and the bar now, sharing them) shows. Post nothing.
     * @throws WindowException
     */
    public function setToggle(string $item, bool $on): void
    {
        $this->defaultStates()->setToggle($item, $on);
        $this->default_bar?->setToggle($item, $on);
    }

    /** @throws WindowException */
    public function isToggled(string $item): bool
    {
        return $this->defaultStates()->toggle($item);
    }

    /** @throws WindowException */
    public function setRadio(string $group, string $item): void
    {
        $this->defaultStates()->setRadio($group, $item);
        $this->default_bar?->setRadio($group, $item);
    }

    /** @throws WindowException */
    public function radio(string $group): string
    {
        return $this->defaultStates()->radio($group);
    }

    /** @throws WindowException */
    public function setItemLabel(string $item, string $label): void
    {
        $this->defaultStates()->setLabel($item, $label);
        $this->default_bar?->setItemLabel($item, $label);
    }

    /** @throws WindowException */
    public function itemLabel(string $item): string
    {
        return $this->defaultStates()->label($item);
    }

    protected function defaultStates(): MenuStates
    {
        return $this->default_states ?? throw new WindowException('No default menu bar profile is set.');
    }

    /**
     * macOS: put an active window's own bar in the app bar; the default bar's hotkeys step aside.
     * @param GTKMenuBar $bar
     * @return void
     */
    public function showWindowMenuBar(GTKMenuBar $bar): void
    {
        $this->default_bar?->setAccelerators(false);
        $this->hold($bar);
        $bar->setAccelerators(true);
        $this->session->application()->setMenubar($bar->model());
    }

    /**
     * macOS: the application holds the shown bar's actions, which its items name as "app" actions;
     * the bar shown before gives its actions and hotkeys back. Null: no bar's.
     */
    protected function hold(?GTKMenuBar $bar): void
    {
        if ($this->held === $bar) {
            return;
        }
        $application = $this->session->application();
        $this->held?->removeFrom($application);
        $bar?->addTo($application);
        $this->held = $bar;
    }

    /**
     * The default bar while it is built (macOS, once shown).
     * @return GTKMenuBar|null
     */
    public function defaultMenuBar(): ?GTKMenuBar
    {
        return $this->default_bar;
    }

    /**
     * Whether menus live in one app-wide bar (macOS) rather than inside each window.
     * @return bool
     */
    public static function appWideBar(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * Forget a window that closed.
     * @param string $name
     * @return void
     */
    public function forget(string $name): void
    {
        unset($this->windows[$name]);
        $this->showDefaultMenuBar();
    }

    /**
     * A profile from config/windows.php, through the window manager.
     * @param string $name
     * @return MenuProfile
     * @throws WindowException When no profile is registered under that name.
     */
    public function profile(string $name): MenuProfile
    {
        return $this->app->get('toolkit-windows')->profile($name);
    }

    /**
     * The About identity from config('windows.about').
     * @return array{name?: string|null, version?: string|null, copyright?: string|null}
     */
    public function about(): array
    {
        return (array) $this->app->get('config')->get('windows.about', []);
    }

    /**
     * @param MenuProfileContract|null $menu
     * @return MenuProfile|null
     * @throws WindowException When the profile is not one Surface parsed.
     */
    protected function concrete(?MenuProfileContract $menu): ?MenuProfile
    {
        if (is_null($menu) || $menu instanceof MenuProfile) {
            return $menu;
        }

        throw new WindowException(get_class($menu).' is not a menu profile parsed by Surface\Windows\Menus\MenuProfile.');
    }
}
