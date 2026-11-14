<?php

namespace Jovian\Toolkits\GTK;

use GtkLabel;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKFonts;
use Jovian\Toolkits\GTK\Services\GTKClipboard;
use Jovian\Toolkits\GTK\Services\GTKDesktop;
use Jovian\Toolkits\GTK\Services\GTKDialogs;
use Jovian\Toolkits\GTK\Services\GTKSystemAccent;
use Jovian\Toolkits\GTK\Windows\GTKMenuBar;
use ReflectionException;
use Surface\Contracts\Toolkits\Drivers\ToolkitWindowDriver;
use Surface\Contracts\Toolkits\Menus\MenuProfile as MenuProfileContract;
use Surface\Contracts\Toolkits\Styling\FontMetrics;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Contracts\Toolkits\ToolkitWindow;
use Surface\Toolkits\Menus\MenuProfile;
use Surface\Toolkits\ToolkitBridge;
use Surface\Toolkits\Windows\ChildWindows;

class GTKBridge extends ToolkitBridge implements ToolkitWindowDriver
{
    /**
     * Open windows by name; a window leaves when it closes.
     * @var array<string, GTKWindow>
     */
    protected array $windows = [];

    protected string $session_class = GTKSession::class;

    protected ?GTKDialogs $dialogs = null;

    protected ?GTKClipboard $clipboard = null;

    protected ?GTKDesktop $desktop = null;

    protected ?GTKSystemAccent $systemAccent = null;

    protected ?GtkLabel $metrics_label = null;

    /** macOS: the bar whose actions the application holds, the one shown at the top of the screen. */
    protected ?GTKMenuBar $held = null;

    /**
     * @throws ReflectionException
     */
    protected function instanceSession(): void
    {
        $session_class  = $this->session_class;
        $this->session ??= new $session_class(
            $this->applicationId(),
            (bool) config('gtk.unique_primary_instance', false),
        );
    }

    /**
     * The clipboard's changed signal reports another app's copy from the start, not from the
     * app's first clipboard call.
     */
    protected function connected(): void
    {
        /** @var GTKSession $session */
        $session = $this->session;
        $this->clipboard ??= new GTKClipboard($session);
    }

    /** With a parent, the window is transient for it and modal, in its window group. */
    public function open(string $name, int $width, int $height, ?MenuProfileContract $menu = null, ?ToolkitWindow $parent = null): GTKWindow
    {
        if (isset($this->windows[$name])) {
            throw ToolkitRuntimeException::instanceOpen($name);
        }

        if (! is_null($parent)) {
            ChildWindows::check($this, $parent, $menu);
            $this->dialogs()->refuseChild($parent);
        }

        /** @var GTKSession $session */
        $session = $this->connect();

        return $this->windows[$name] = new GTKWindow($name, $session, $this, $width, $height, $this->concrete($menu), $parent);
    }

    public function has(string $name): bool
    {
        return isset($this->windows[$name]);
    }

    public function get(string $name): ?GTKWindow
    {
        return $this->windows[$name] ?? null;
    }

    /** @return array<string, GTKWindow> */
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

    /** A window closed: it leaves the open windows. */
    public function forget(string $name): void
    {
        unset($this->windows[$name]);
    }

    /** Alert and file dialogs, modal to their parent. */
    public function dialogs(): GTKDialogs
    {
        /** @var GTKSession $session */
        $session = $this->connect();

        return $this->dialogs ??= new GTKDialogs($this, $session);
    }

    /** The display's clipboard; GTK starts with the session, so the session connects first. */
    public function clipboard(): GTKClipboard
    {
        $this->connect();

        return $this->clipboard;
    }

    /** GtkFileLauncher is GTK too: the session connects first. */
    public function desktop(): GTKDesktop
    {
        $this->connect();

        return $this->desktop ??= new GTKDesktop();
    }

    /** GSettings is GIO alone: no session is needed to read the accent. */
    public function systemAccent(): GTKSystemAccent
    {
        return $this->systemAccent ??= new GTKSystemAccent();
    }

    /** Pango measures through a widget's context: one label, never shown, kept for it. */
    public function fontMetrics(FontSpec $font): FontMetrics
    {
        $this->connect();

        return GTKFonts::metrics(($this->metrics_label ??= GtkLabel::new(''))->getPangoContext(), $font);
    }

    /**
     * A profile from config/windows.php, through the window manager.
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
     * macOS: put an active window's own bar in the app bar. The bar shown before gives its
     * actions and hotkeys back.
     */
    public function showWindowMenuBar(GTKMenuBar $bar): void
    {
        $this->hold($bar);
        $bar->setAccelerators(true);
        $this->session->application()->setMenubar($bar->model());
    }

    /** macOS: take $bar out of the app bar if it is the one shown (its window closed, or took another bar). */
    public function releaseWindowMenuBar(GTKMenuBar $bar): void
    {
        if ($this->held !== $bar) {
            return;
        }
        $this->hold(null);
        $this->session->application()->setMenubar(null);
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
     * @throws ToolkitRuntimeException When the profile is not one Surface parsed.
     */
    protected function concrete(?MenuProfileContract $menu): ?MenuProfile
    {
        if (is_null($menu) || $menu instanceof MenuProfile) {
            return $menu;
        }

        throw new ToolkitRuntimeException(get_class($menu).' is not a menu profile parsed by Surface\\Toolkits\\Menus\\MenuProfile.');
    }

    /**
     * Whether menus live in one bar at the top of the screen (macOS) and not inside each window.
     */
    public static function appWideBar(): bool
    {
        return device_os_family() === 'mac';
    }

    /**
     * The desktop identity: config/app.php app.id, the name a packaged build's .desktop file carries.
     * @throws ReflectionException
     */
    protected function applicationId(): string
    {
        $id = config('app.id');

        if (! is_string($id) || $id === '') {
            throw new ToolkitRuntimeException("config/app.php has no app.id; add 'id' => env('APP_ID', 'com.venusian.app') under name.");
        }

        return $id;
    }
}
