<?php

use Jovian\Venusian\GTK\Views\GtkSignals;
use Jovian\Venusian\GTK\Views\GtkTextChange;

final class TextChangeHost
{
    use GtkSignals, GtkTextChange;

    protected int $pointer = 11;

    public function __construct()
    {
        $this->wireTextChange();
    }

    public function listen(?Closure $handler): void
    {
        $this->nativeOnChange($handler);
    }

    public function hush(Closure $setter): void
    {
        $this->quietly($setter);
    }

    public function fire(): void
    {
        foreach ($GLOBALS['gtk_text_change'][11] ?? [] as $handler) {
            $handler();
        }
    }

    public function rewire(): void
    {
        $this->wireTextChange();
    }
}

final class PopoverCloseHost
{
    use GtkSignals;

    protected int $pointer = 13;

    public function listen(?Closure $handler): void
    {
        $this->connect('closed', $handler);
    }

    public function hide(): void
    {
        gtk_popover_popdown($this->pointer);
    }
}

beforeEach(function () {
    $GLOBALS['gtk_signal_log'] = [];
    $GLOBALS['gtk_signal_handlers'] = [];
    $GLOBALS['gtk_signal_by_key'] = [];
    $GLOBALS['gtk_text_change'] = [];
    $GLOBALS['gtk_signal_fail_next'] = false;
});

it('connects the text dispatcher once and swaps the trampoline', function () {
    $host = new TextChangeHost;
    $seen = [];
    $host->listen(function () use (&$seen): void { $seen[] = 'a'; });
    $host->rewire();
    $host->listen(function () use (&$seen): void { $seen[] = 'b'; });
    $host->fire();

    expect($GLOBALS['gtk_signal_log'])->toBe([['text_on_change', 11]])
        ->and($seen)->toBe(['b']);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

it('mutes the text dispatcher while a setter runs', function () {
    $host = new TextChangeHost;
    $fired = 0;
    $host->listen(function () use (&$fired): void { $fired++; });

    $host->hush(fn () => $host->fire());
    $host->fire();

    expect($fired)->toBe(1);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

it('fires closed when popdown hides the popover', function () {
    $host = new PopoverCloseHost;
    $closed = 0;
    $host->listen(function () use (&$closed): void { $closed++; });

    $host->hide();

    expect($closed)->toBe(1)
        ->and($GLOBALS['gtk_signal_log'][1])->toBe(['popdown', 13]);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

final class TabsPageHost
{
    use GtkSignals;

    protected int $pointer = 17;

    public function listen(?Closure $handler): void
    {
        $this->connect('notify::page', $handler);
    }

    public function append(): void
    {
        $this->quietly(fn () => gtk_notebook_append_page($this->pointer, 1, 'Notes'));
    }
}

it('mutes notify::page while appending a page', function () {
    $host = new TabsPageHost;
    $fired = 0;
    $host->listen(function () use (&$fired): void { $fired++; });

    $host->append();

    expect($fired)->toBe(0)
        ->and($GLOBALS['gtk_signal_log'])->toContain(['append_page', 17, 1, 'Notes']);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');
