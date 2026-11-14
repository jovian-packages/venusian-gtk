<?php

namespace Jovian\Toolkits\GTK;

use GIOCondition;
use GMainContext;
use GtkAboutDialog;
use GtkWindow;
use Jovian\Toolkits\GTK\GTKWindow as DriverWindow;
use NSApplication;
use Surface\Contracts\Toolkits\Mail\Views\ViewResized;
use Surface\Contracts\Toolkits\Mail\Windows\WindowResized;
use Surface\Contracts\Toolkits\Primitives\TKPrimitive;
use GtkApplication;
use GApplicationFlags;
use Surface\Toolkits\ToolkitSession;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;

class GTKSession extends ToolkitSession
{
    /**
     * The application, from initialization until the process ends.
     * @var GtkApplication|null
     */
    protected ?GtkApplication $application = null;

    protected ?GMainContext $context = null;

    /**
     * Non-blocking iterations one pump runs after its wait. A source that is always ready
     * (an idle handler re-adding itself) waits for the next pump instead of holding this one.
     */
    public const int DRAIN_LIMIT = 64;

    /**
     * The loop's waiter descriptor while joined, and its source while armed.
     */
    protected ?int $wake_fd = null;

    protected ?int $wake_tag = null;

    /**
     * The About dialog while it is open. Closing destroys it: a hidden toplevel keeps the
     * frame clock waking the process every frame on macOS.
     */
    protected ?GtkAboutDialog $about_dialog = null;

    /**
     * Primitives reporting their size, by uuid, with the size last reported.
     * @var array<string, array{TKPrimitive, array{int, int}}>
     */
    protected array $watched = [];

    /**
     * Open windows by name, with the content-area size last reported.
     * @var array<string, array{DriverWindow, array{int, int}}>
     */
    protected array $windows = [];

    /**
     * @param string $application_id the desktop identity: Wayland's app_id, the bus name when unique
     * @param bool $unique one primary instance per id (a second process becomes remote), or any number
     */
    public function __construct(
        protected readonly string $application_id,
        protected readonly bool $unique = false,
    ) {
        parent::__construct();
    }

    protected function initializeEngine(): void
    {
        if (device_os_family() === 'mac' && ! extension_loaded('appkit')) {
            throw new ToolkitRuntimeException('GTK on macOS required ext-appkit.');
        }

        g_set_prgname($this->application_id);
        $this->application = GtkApplication::new(
            $this->application_id,
            $this->unique ? GApplicationFlags::DEFAULT_FLAGS : GApplicationFlags::NON_UNIQUE,
        ) ?? throw new ToolkitRuntimeException("GTK made no application for the id '{$this->application_id}': GLib takes only a valid application id (reverse-DNS, see g_application_id_is_valid).");

        g_signal_connect($this->application, 'activate', fn () => null);
        $this->application->register(null);
        $this->context = GMainContext::default();
    }

    protected function connectToEngine(): void
    {
        $this->application->hold();
        $this->application->activate();
    }

    /**
     * Release the hold taken in connectToEngine().
     */
    protected function disconnectEngine(): void
    {
        $this->application->release();
    }

    /**
     * Wait at most $budget_ns for a source to be ready, dispatch it, then dispatch what else is ready.
     *
     * @param int $budget_ns Zero dispatches what is ready without waiting.
     * @return int Iterations that dispatched.
     */
    public function pump(int $budget_ns): int
    {
        $this->armWake();
        $dispatched = 0;

        if ($budget_ns > 0) {
            $expired = false;
            $budget = g_timeout_add(max(1, intdiv($budget_ns + 999_999, 1_000_000)), function () use (&$expired): bool {
                $expired = true;

                return G_SOURCE_REMOVE;
            });

            if ($this->context->iteration(true)) {
                $dispatched++;
            }
            if (! $expired) {
                g_source_remove($budget);
            }
        }

        while ($dispatched < self::DRAIN_LIMIT && $this->context->pending()) {
            if ($this->context->iteration(false)) {
                $dispatched++;
            }
        }

        $this->checkWatched();
        foreach ($this->windows as [$window]) {
            $window->settleActive();
        }

        // Not ported yet: the canvas coasts ticked here (GTKCanvasCoast is in deprecated/Windows).

        return $dispatched;
    }

    /** Report $primitive's allocation from now on, whenever a pump finds it changed. */
    public function watch(TKPrimitive $primitive): void
    {
        $this->watched[$primitive->uuid()] = [$primitive, $primitive->size()];
    }

    /** Stop reporting $primitive's size and drop a report still pending for it. */
    public function unwatch(TKPrimitive $primitive): void
    {
        unset($this->watched[$primitive->uuid()]);
        $this->forgetLatest(self::viewResizedKey($primitive));
    }

    /**
     * Report $window's content-area size from now on. A size with a zero side (not yet
     * allocated) is recorded without a report, so the first allocation is not a resize.
     */
    public function watchWindow(DriverWindow $window): void
    {
        $this->windows[$window->name()] = [$window, [0, 0]];
    }

    /** Stop reporting $window's size and drop a report still pending for it. */
    public function unwatchWindow(DriverWindow $window): void
    {
        unset($this->windows[$window->name()]);
        $this->forgetLatest("window.resized.{$window->name()}");
    }

    /**
     * Post WindowResized and ViewResized, latest-only, for each window and watched primitive
     * whose allocation changed. GTK has no per-widget resize signal, so each pump compares.
     */
    protected function checkWatched(): void
    {
        foreach ($this->windows as $name => [$window, $last]) {
            $size = $window->size();
            if ($size === $last) {
                continue;
            }
            $this->windows[$name][1] = $size;
            if (in_array(0, $last, true) || in_array(0, $size, true)) {
                continue;
            }
            $this->postLatest("window.resized.{$name}", new WindowResized($name, $size[0], $size[1]));
        }

        foreach ($this->watched as $uuid => [$primitive, $last]) {
            $size = $primitive->size();
            if ($size === $last) {
                continue;
            }
            $this->watched[$uuid][1] = $size;
            $this->postLatest(self::viewResizedKey($primitive), new ViewResized(
                $primitive->window()->name(), $primitive->path(), $uuid, $size[0], $size[1],
            ));
        }
    }

    /** Keyed by uuid: "<window>.<path>" would collide across windows whose names hold dots. */
    protected static function viewResizedKey(TKPrimitive $primitive): string
    {
        return "view.resized.{$primitive->uuid()}";
    }

    protected function wakeDescriptor(int $fd): void
    {
        $this->wake_fd = $fd;
        $this->armWake();
    }

    protected function releaseWakeDescriptor(): void
    {
        if (! is_null($this->wake_tag)) {
            g_source_remove($this->wake_tag);
        }
        $this->wake_tag = null;
        $this->wake_fd = null;
    }

    /**
     * The descriptor's source is one-shot: it ends a wait and removes itself, and the next
     * pump re-arms it, so a descriptor the loop has not read yet cannot keep a drain busy.
     */
    protected function armWake(): void
    {
        if (is_null($this->wake_fd) || ! is_null($this->wake_tag)) {
            return;
        }

        $this->wake_tag = g_unix_fd_add($this->wake_fd, GIOCondition::IN, function (): bool {
            $this->wake_tag = null;

            return G_SOURCE_REMOVE;
        });
    }

    /**
     * Bring the process forward so a presented window takes focus. macOS declines GDK's one
     * activation at display open for a process started from a terminal, and GDK has no other,
     * so it is asked of NSApplication. Wayland and X11 give focus on present.
     */
    public function bringForward(): void
    {
        if (device_os_family() === 'mac') {
            NSApplication::sharedApplication()->activateIgnoringOtherApps(true);
        }
    }

    /**
     * The application, for window hosts.
     */
    public function application(): GtkApplication
    {
        return $this->application;
    }

    /**
     * Show the app's non-modal About dialog with the identity given, over $parent when there
     * is one. While it is open, showing it again updates and raises the same dialog, as macOS does.
     *
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     */
    public function showAbout(array $about, ?GtkWindow $parent): GtkAboutDialog
    {
        if (is_null($this->about_dialog)) {
            $this->about_dialog = GtkAboutDialog::new();
            // false lets GTK destroy it.
            g_signal_connect($this->about_dialog, 'close-request', function (): bool {
                $this->about_dialog = null;

                return false;
            });
        }

        $dialog = $this->about_dialog;
        $dialog->setProgramName(empty($about['name']) ? null : (string) $about['name']);
        $dialog->setVersion(empty($about['version']) ? null : (string) $about['version']);
        $dialog->setCopyright(empty($about['copyright']) ? null : (string) $about['copyright']);
        $dialog->setTransientFor($parent);
        $dialog->present();

        return $dialog;
    }

    /** The About dialog while it is open. */
    public function aboutDialog(): ?GtkAboutDialog
    {
        return $this->about_dialog;
    }
}
