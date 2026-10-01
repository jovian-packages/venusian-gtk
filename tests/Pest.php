<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Surface\Bridge\ToolkitManager;
use Surface\Windows\ToolkitWindowManager;
use Voyager\Config\Repository;
use Voyager\Vessel\ControlPanel;

if (! extension_loaded('gtk')) {
    throw new RuntimeException('venusian-gtk tests need ext-gtk loaded.');
}

const TEST_MENUS = [
    'main' => [
        ['label' => 'App', 'items' => [
            ['role' => 'about', 'label' => 'About'],
            ['separator' => true],
            ['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q'],
        ]],
        ['label' => 'View', 'items' => [
            ['id' => 'grid', 'label' => 'Show Grid', 'toggle' => true, 'on' => false],
            ['id' => 'view.refresh', 'label' => 'Refresh', 'hotkey' => 'R'],
        ]],
    ],
    'tools' => [
        ['label' => 'Tools', 'items' => [
            ['id' => 'tools.measure', 'label' => 'Measure'],
        ]],
    ],
];

/**
 * The one driver for the process: one GtkApplication per process, so every test shares
 * the driver, its session and its windows, through a container like the framework's.
 */
function driver(): GTKBridgeDriver
{
    static $driver = null;

    if (is_null($driver)) {
        $container = new ControlPanel();
        $container->registerInstance('config', new Repository([
            'bridge' => ['gtk' => ['application_id' => 'org.venusian.GtkDriverTests']],
            'windows' => [
                'about' => ['name' => 'venusian-gtk tests', 'version' => '0.10.0', 'copyright' => null],
                'default_menu' => 'main',
                'menus' => TEST_MENUS,
            ],
        ]));
        $container->registerInstance('toolkit-bridge', $toolkits = new ToolkitManager($container));
        $container->registerInstance('toolkit-windows', new ToolkitWindowManager($toolkits, TEST_MENUS, 'main'));
        $driver = new GTKBridgeDriver($container);
    }

    return $driver;
}

function session(): GTKSession
{
    return driver()->connect();
}

/** Mail the session is holding for a loop, taken out. */
function takeMail(GTKSession $session): array
{
    return (function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    })->call($session);
}

/** Pump GTK for $seconds. */
function pumpFor(float $seconds): void
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        session()->pump(10_000_000);
    }
}
