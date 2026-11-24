<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\GtkAdapterDriver;
use Surface\Toolkits\ToolkitAdapters;

afterEach(function (): void {
    toolkitApp();
});

it('registers the toolkit without building its driver', function (): void {
    bootCore();

    expect(ToolkitAdapters::adapters())->toBe([]);
});

it('merges its config with the package defaults', function (): void {
    bootCore();

    expect(config('gtk'))->toBeArray()->not->toBeEmpty();
});

it('builds its driver when toolkits.default selects it, with nothing native started', function (): void {
    bootCore();

    $driver = ToolkitAdapters::driver();

    expect($driver)->toBeInstanceOf(GtkAdapterDriver::class)
        ->and(ToolkitAdapters::driver('gtk'))->toBe($driver)
        ->and($driver->session())->toBeNull();
});

it('refuses to start without an application id', function (): void {
    bootCore(['gtk' => ['application_id' => '']]);

    expect(fn () => ToolkitAdapters::driver()->connect())
        ->toThrow(RuntimeException::class, 'config/gtk.php has no application_id');
});
