<?php

namespace Jovian\Toolkits\GTK\Windows;

use Closure;
use GtkApplicationWindow;
use GtkWidget;
use Surface\Contracts\Windows\Mail\View\FocusGained;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use WeakReference;

/**
 * A toolkit window's keyboard focus as primitives. The window's notify::focus-widget tells every
 * move; the holder is the primitive whose focus widget is the focused widget or its nearest
 * ancestor (an entry's inner GtkText belongs to the entry). A move made from code, inside
 * quietly(), posts nothing and only sets the holder, so the next move the user makes posts
 * FocusLost for that holder, then FocusGained for the new one.
 */
final class GTKFocus
{
    /** @var WeakReference<GTKWindow> the window holds this */
    private readonly WeakReference $window;

    /** @var WeakReference<TKPrimitive>|null the holder last seen: a weak one, so a removed primitive is freed */
    private ?WeakReference $last = null;

    private bool $quiet = false;

    private bool $attached = true;

    public function __construct(GTKWindow $window, GtkApplicationWindow $native)
    {
        $this->window = WeakReference::create($window);
        $self = WeakReference::create($this);
        g_signal_connect($native, 'notify::focus-widget', static function () use ($self): void {
            $self->get()?->changed();
        });
    }

    /** The primitive whose focus widget holds focus; null for none, or for a widget Surface did not make. */
    public function holder(): ?TKPrimitive
    {
        $window = $this->window->get();
        if (! $this->attached || ! $window instanceof GTKWindow || ! $window->isOpen()) {
            return null;
        }
        $focus = $window->native()->getFocus();
        if (is_null($focus)) {
            return null;
        }
        $owners = [];
        foreach ($window->registry()->all() as $primitive) {
            // Every GTK primitive (the GTKPrimitive trait) answers focusWidget().
            if (method_exists($primitive, 'focusWidget')) {
                $owners[spl_object_id($primitive->focusWidget())] = $primitive;
            }
        }
        for ($at = $focus; $at instanceof GtkWidget; $at = $at->getParent()) {
            if (isset($owners[spl_object_id($at)])) {
                return $owners[spl_object_id($at)];
            }
        }

        return null;
    }

    /**
     * Move focus from code: no mail, and the holder after it is the one the user's next move leaves.
     * @param Closure(): void $move
     */
    public function quietly(Closure $move): void
    {
        $this->quiet = true;
        try {
            $move();
        } finally {
            $this->quiet = false;
            $this->remember($this->holder());
        }
    }

    /** Whether the holder is $primitive or inside it. */
    public function within(TKPrimitive $primitive): bool
    {
        for ($at = $this->holder(); ! is_null($at); $at = $at->parent()) {
            if ($at === $primitive) {
                return true;
            }
        }

        return false;
    }

    /** The window closed: nothing posts after. */
    public function detach(): void
    {
        $this->attached = false;
        $this->last = null;
    }

    private function changed(): void
    {
        if ($this->quiet || ! $this->attached) {
            return;
        }
        $now = $this->holder();
        $last = $this->last?->get();
        if ($now === $last) {
            return;
        }
        $window = $this->window->get();
        $this->remember($now);
        if (! $window instanceof GTKWindow) {
            return;
        }
        if (! is_null($last) && ! $last->isRemoved()) {
            $window->session()->post(new FocusLost($window->name(), $last->path(), $last->uuid()));
        }
        if (! is_null($now)) {
            $window->session()->post(new FocusGained($window->name(), $now->path(), $now->uuid()));
        }
    }

    private function remember(?TKPrimitive $holder): void
    {
        $this->last = is_null($holder) ? null : WeakReference::create($holder);
    }
}
