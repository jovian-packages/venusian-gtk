<?php

namespace Jovian\Toolkits\GTK\Bridge;

use Jovian\Toolkits\GTK\Contracts\Bridge\GTKBridgeDriver as BridgeContract;
use Jovian\Toolkits\GTK\Windows\GTKMenuBar;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Bridge\BridgeException;
use Surface\Contracts\Windows\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Windows\ToolkitWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuProfile;

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

    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null): GTKWindow
    {
        if (isset($this->windows[$name])) {
            throw new WindowException("A window named '{$name}' is already open.");
        }

        return $this->windows[$name] = new GTKWindow($name, $this->connect(), $this, $width, $height, $this->concrete($menu));
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

        if (! is_null($this->default_bar)) {
            $this->default_bar->removeFrom($this->session->application());
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
     * @return void
     */
    public function installDefaultMenuBar(): void
    {
        if (! self::appWideBar() || is_null($this->session)) {
            return;
        }

        $application = $this->session->application();

        if (is_null($this->default_menu)) {
            $application->setMenubar(null);
            return;
        }

        if (is_null($this->default_bar)) {
            $this->default_bar = new GTKMenuBar($this->session, $this->default_menu, null, $this->about(), null);
            $this->default_bar->addTo($application);
        }

        $this->default_bar->setAccelerators(true);
        $application->setMenubar($this->default_bar->model());
    }

    /**
     * macOS: put an active window's own bar in the app bar; the default bar's hotkeys step aside.
     * @param GTKMenuBar $bar
     * @return void
     */
    public function showWindowMenuBar(GTKMenuBar $bar): void
    {
        $this->default_bar?->setAccelerators(false);
        $this->session->application()->setMenubar($bar->model());
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
