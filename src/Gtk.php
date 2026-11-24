<?php

namespace Jovian\Toolkits\GTK;

use GApplicationFlags;
use GIOCondition;
use GMainContext;
use GtkApplication;
use NSApplication;
use ReflectionException;
use RuntimeException;
use Surface\Contracts\Toolkits\Toolkit;

class Gtk implements Toolkit
{
    protected readonly int $drain_limit;

    protected ?GMainContext $context = null;

    /**
     * The loop's waiter descriptor while joined, and its source while armed.
     */
    protected ?int $wake_fd = null;

    protected ?int $wake_tag = null;

    /**
     * @throws ReflectionException
     */
    public function __construct(
        protected readonly string $application_id,
        protected readonly bool $unique = false,
        protected ?GtkApplication $application = null

    )  {
        g_set_prgname($this->application_id);
        $this->application ??= GtkApplication::new(
            $this->application_id,
            $this->unique ? GApplicationFlags::DEFAULT_FLAGS : GApplicationFlags::NON_UNIQUE,
        ) ?? throw new RuntimeException("GTK made no application for the id '{$this->application_id}': GLib takes only a valid application id (reverse-DNS, see g_application_id_is_valid).");

        g_signal_connect($this->application, 'activate', fn () => null);
        $this->application->register(null);
        $this->context = GMainContext::default();

        $this->drain_limit = config('gtk.loop_drain_limit', 64);
    }

    public function get(): GtkApplication
    {
        return $this->application;
    }

    /**
     * Hold the application so it does not quit with no window open, and activate it.
     */
    public function connect(): void
    {
        $this->application->hold();
        $this->application->activate();
    }

    /**
     * Release the hold taken in connect().
     */
    public function disconnect(): void
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
            // A one-shot timeout ends the wait at its budget.
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

        while ($dispatched < $this->drain_limit && $this->context->pending()) {
            if ($this->context->iteration(false)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    public function wakeDescriptor(int $fd): void
    {
        $this->wake_fd = $fd;
        $this->armWake();
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
}
