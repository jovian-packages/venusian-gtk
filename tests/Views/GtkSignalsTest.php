<?php

use Jovian\Venusian\GTK\Views\GtkSignals;

final class SignalHost
{
    use GtkSignals;

    protected int $pointer = 7;

    public function wire(string $signal, ?Closure $handler): void
    {
        $this->connect($signal, $handler);
    }

    public function hush(Closure $setter): void
    {
        $this->quietly($setter);
    }
}

beforeEach(function () {
    $GLOBALS['gtk_signal_log'] = [];
    $GLOBALS['gtk_signal_handlers'] = [];
    $GLOBALS['gtk_signal_fail_next'] = false;
});

it('connects a handler once per signal', function () {
    (new SignalHost)->wire('clicked', fn () => null);

    expect($GLOBALS['gtk_signal_log'])->toBe([['connect', 7, 'clicked']]);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

it('disconnects the previous handler before connecting a replacement', function () {
    $host = new SignalHost;
    $host->wire('clicked', fn () => null);
    $host->wire('clicked', fn () => null);

    expect($GLOBALS['gtk_signal_log'])->toBe([
        ['connect', 7, 'clicked'],
        ['disconnect', 7, 1],
        ['connect', 7, 'clicked'],
    ]);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

it('clears with null and stays quiet when nothing was connected', function () {
    $host = new SignalHost;
    $host->wire('clicked', null);
    $host->wire('clicked', fn () => null);
    $host->wire('clicked', null);

    expect($GLOBALS['gtk_signal_log'])->toBe([
        ['connect', 7, 'clicked'],
        ['disconnect', 7, 1],
    ]);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

it('keeps signals independent', function () {
    $host = new SignalHost;
    $host->wire('changed', fn () => null);
    $host->wire('activate', fn () => null);
    $host->wire('changed', null);

    expect($GLOBALS['gtk_signal_log'])->toBe([
        ['connect', 7, 'changed'],
        ['connect', 7, 'activate'],
        ['disconnect', 7, 1],
    ]);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

it('keeps native signals away from the sketch while a setter runs', function () {
    $host = new SignalHost;
    $fired = 0;
    $host->wire('changed', function () use (&$fired): void { $fired++; });
    $handler = $GLOBALS['gtk_signal_handlers'][1];

    $host->hush(function () use ($handler): void { $handler(); });
    $handler();

    expect($fired)->toBe(1);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');

it('drops a failed connection instead of remembering id 0', function () {
    $GLOBALS['gtk_signal_fail_next'] = true;
    $host = new SignalHost;
    $host->wire('clicked', fn () => null);
    $host->wire('clicked', null);

    expect(array_filter($GLOBALS['gtk_signal_log'], fn ($e) => $e[0] === 'disconnect'))->toBe([]);
})->skip(extension_loaded('gtk'), 'fakes are bypassed when ext-gtk is loaded');
