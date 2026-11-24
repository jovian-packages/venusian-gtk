<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\GtkSession;
use Jovian\Toolkits\GTK\Gtk;

it('connects one session for the driver', function (): void {
    $session = session();

    expect($session)->toBeInstanceOf(GtkSession::class)
        ->and($session->connected())->toBeTrue()
        ->and($session->application())->toBeInstanceOf(Gtk::class)
        ->and(driver()->connect())->toBe($session);
});

it('registers a GtkApplication under the configured application id', function (): void {
    $application = session()->application()->get();

    expect($application)->toBeInstanceOf(GtkApplication::class)
        ->and($application->getIsRegistered())->toBeTrue()
        ->and($application->getApplicationId())->toBe(APPLICATION_ID);
});

it('reconnects to the same toolkit library', function (): void {
    $toolkit = session()->application();

    session()->disconnect();
    expect(session()->connected())->toBeTrue()
        ->and(session()->application())->toBe($toolkit);
});
