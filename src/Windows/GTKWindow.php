<?php

namespace Jovian\Toolkits\GTK\Windows;

use GtkApplicationWindow;
use GtkBox;
use GtkOrientation;
use GtkWidget;
use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuProfile;

class GTKWindow implements ToolkitWindow
{
    /**
     * The native window while open; null once closed.
     * @var GtkApplicationWindow|null
     */
    protected ?GtkApplicationWindow $window;

    /**
     * Vertical box filling the window: the menu bar (Linux) above the content.
     * @var GtkBox
     */
    protected GtkBox $scaffold;

    /**
     * Where views go: the area below the bar, expanding both ways.
     * @var GtkBox
     */
    protected GtkBox $content;

    protected ?GTKMenuBar $menu = null;

    /**
     * The bar widget inside the window (Linux), removed when the bar is replaced.
     * @var GtkWidget|null
     */
    protected ?GtkWidget $bar_widget = null;

    public function __construct(
        protected readonly string $name,
        protected readonly GTKSession $session,
        protected readonly GTKBridgeDriver $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
    ) {
        $this->window = GtkApplicationWindow::new($session->application());
        $this->window->setTitle($name);
        $this->window->setDefaultSize($width, $height);

        $this->scaffold = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->content = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->content->setHexpand(true);
        $this->content->setVexpand(true);
        $this->scaffold->append($this->content);
        $this->window->setChild($this->scaffold);

        // false lets GTK destroy the window; the mail goes first.
        g_signal_connect($this->window, 'close-request', function (): bool {
            $this->closed();

            return false;
        });
        g_signal_connect($this->window, 'notify::is-active', fn () => $this->activeChanged());

        if (! is_null($menu)) {
            $this->useMenu(new GTKMenuBar($session, $menu, $name, $driver->about(), $this->window));
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->live()->getTitle() ?? '';
    }

    public function setTitle(string $title): static
    {
        $this->live()->setTitle($title);

        return $this;
    }

    public function present(): static
    {
        $this->live()->present();
        $this->session->bringForward();

        return $this;
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * Whether this window is the active one, and so (macOS) whose bar the app shows.
     * @return bool
     */
    public function isActive(): bool
    {
        return ! is_null($this->window) && $this->window->isActive();
    }

    /**
     * A realized window closes like the window manager's close button: close-request runs
     * inside close() and does the mail and bookkeeping. GTK skips close-request for a window
     * never realized, so that one is destroyed here and takes the same path.
     * @return void
     */
    public function close(): void
    {
        if (is_null($this->window)) {
            return;
        }

        if ($this->window->getRealized()) {
            $this->window->close();
            return;
        }

        $window = $this->window;
        $this->closed();
        $window->destroy();
    }

    public function setMenuBar(string $profile): static
    {
        $this->useMenu(new GTKMenuBar($this->session, $this->driver->profile($profile), $this->name, $this->driver->about(), $this->live()));

        return $this;
    }

    public function setToggle(string $item, bool $on): static
    {
        $this->menuOrFail()->setToggle($item, $on);

        return $this;
    }

    public function isToggled(string $item): bool
    {
        return $this->menuOrFail()->isToggled($item);
    }

    /**
     * The native window, for engines that draw into it.
     * @return GtkApplicationWindow
     * @throws WindowException Once closed.
     */
    public function native(): GtkApplicationWindow
    {
        return $this->live();
    }

    /**
     * The area below the menu bar, where views go.
     * @return GtkBox
     */
    public function content(): GtkBox
    {
        return $this->content;
    }

    public function menuBar(): ?GTKMenuBar
    {
        return $this->menu;
    }

    /**
     * Put a bar where the OS keeps menus: inside the window on Linux, the app's bar on macOS
     * (while this window is active). Its actions live in the window's "win" group either way.
     * @param GTKMenuBar $menu
     * @return void
     */
    protected function useMenu(GTKMenuBar $menu): void
    {
        $window = $this->live();
        $this->menu = $menu;
        $window->insertActionGroup('win', $menu->actions());
        $menu->setAccelerators(true);

        if (GTKBridgeDriver::appWideBar()) {
            $window->setShowMenubar(true);
            if ($this->isActive()) {
                $this->driver->showWindowMenuBar($menu);
            }
            return;
        }

        if (! is_null($this->bar_widget)) {
            $this->scaffold->remove($this->bar_widget);
        }
        $this->bar_widget = $menu->widget();
        $this->scaffold->prepend($this->bar_widget);
    }

    protected function activeChanged(): void
    {
        if (! $this->isActive()) {
            $this->driver->showDefaultMenuBar();
            return;
        }

        if (GTKBridgeDriver::appWideBar()) {
            if (is_null($this->menu)) {
                $this->driver->installDefaultMenuBar();
            } else {
                $this->driver->showWindowMenuBar($this->menu);
            }
        }

        $this->session->post(new WindowFocused($this->name));
    }

    /**
     * The one close path: the native window is going, post it, let the driver forget it.
     * @return void
     */
    protected function closed(): void
    {
        if (is_null($this->window)) {
            return;
        }

        $this->window = null;
        $this->session->post(new WindowClosed($this->name));
        $this->driver->forget($this->name);
    }

    protected function live(): GtkApplicationWindow
    {
        return $this->window ?? throw new WindowException("Window '{$this->name}' is closed.");
    }

    protected function menuOrFail(): GTKMenuBar
    {
        return $this->menu ?? throw new WindowException("Window '{$this->name}' has no menu bar.");
    }
}
