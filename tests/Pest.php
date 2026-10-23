<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Bridge\ToolkitManager;
use Surface\Bridge\ToolkitPump;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;
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
            ['id' => 'view.refresh', 'label' => 'Refresh', 'keys' => 'Primary+Shift+F5'],
            ['id' => 'view.next', 'label' => 'Next Difference', 'keys' => 'Alt+Down'],
            ['id' => 'view.tab', 'label' => 'Next Tab', 'keys' => 'Primary+]'],
            ['id' => 'view.erase', 'label' => 'Erase', 'keys' => 'Primary+BACKSPACE'],
            ['id' => 'view.locked', 'label' => 'Locked', 'enabled' => false, 'items' => [
                ['id' => 'view.locked.item', 'label' => 'Item'],
            ]],
        ]],
    ],
    'tools' => [
        ['label' => 'Tools', 'items' => [
            ['id' => 'tools.measure', 'label' => 'Measure'],
        ]],
    ],
    'editing' => [
        ['label' => 'App', 'items' => [
            ['role' => 'quit', 'label' => 'Quit', 'hotkey' => 'q'],
        ]],
        ['label' => 'Edit', 'items' => [
            ['role' => 'undo', 'label' => 'Undo'],
            ['role' => 'redo', 'label' => 'Redo'],
            ['separator' => true],
            ['role' => 'cut', 'label' => 'Cut'],
            ['role' => 'copy', 'label' => 'Copy'],
            ['role' => 'paste', 'label' => 'Paste'],
            ['role' => 'select-all', 'label' => 'Select All'],
            ['separator' => true],
            ['id' => 'edit.find', 'label' => 'Find'],
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
            'app' => ['name' => 'venusian-gtk tests', 'id' => 'org.venusian.GtkDriverTests'],
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

/**
 * Pump until $done() holds or $seconds pass, and say whether it held. For waits on the OS:
 * the first window a process presents on macOS takes ~200-300 ms to become active.
 */
function pumpUntil(Closure $done, float $seconds = 3.0): bool
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (! $done() && microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }

    return $done();
}

/**
 * Wait for $window to become active. macOS activation is cooperative (macOS 14+): while the
 * user works in another app the system may decline it, and no API forces it, so there a test
 * that needs an active window is skipped with that reason. Elsewhere activation is required.
 */
function requireActive(GTKWindow $window): void
{
    if (pumpUntil(fn (): bool => $window->isActive())) {
        return;
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        test()->markTestSkipped('macOS declined to activate the app: another app holds focus (cooperative activation).');
    }
    throw new RuntimeException("Window '{$window->name()}' did not become active.");
}

/** The primitives' own mail the session is holding, taken out; window and menu mail is dropped. */
function viewMail(GTKSession $session): array
{
    return array_values(array_filter(takeMail($session), fn (object $mail): bool => $mail instanceof PrimitiveMail));
}

/** Pump GTK for $seconds through the loop's own sleeper, which flushes latest-only mail after each pump. */
function pumpFor(float $seconds): void
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $pump->sleep(10_000_000);
    }
}

/**
 * Pump until $area's layout has settled: its window is mapped, its scroll adjustments read the
 * same value, upper and page size on two pumps in a row, and the area holds no relayout of its
 * own (isSettling()); say whether that happened within $seconds.
 */
function settled(Jovian\Toolkits\GTK\Primitives\GTKTextArea $area, float $seconds = 3.0): bool
{
    $pump = new ToolkitPump(session());
    $until = microtime(true) + $seconds;
    $previous = null;
    while (microtime(true) < $until) {
        $pump->sleep(10_000_000);
        [$horizontal, $vertical] = [$area->native()->getHadjustment(), $area->native()->getVadjustment()];
        $now = [
            $horizontal->getValue(), $horizontal->getUpper(), $horizontal->getPageSize(),
            $vertical->getValue(), $vertical->getUpper(), $vertical->getPageSize(),
        ];
        if ($area->native()->getMapped() && ! $area->isSettling() && $now === $previous) {
            return true;
        }
        $previous = $now;
    }

    return false;
}

/** @return list<Surface\Contracts\Drawing\SurfaceKind> A dmabuf surface, after the GL context, where GTK can import one (Linux, GTK 4.14+). */
function gtkDmabufKinds(): array
{
    return PHP_OS_FAMILY === 'Linux' && class_exists(GdkDmabufTextureBuilder::class) ? [Surface\Contracts\Drawing\SurfaceKind::DMABUF] : [];
}

/** What a canvas lends here, as its refusal names it. */
function gtkLends(): string
{
    $kinds = [...(extension_loaded('opengl') ? ['gl-context'] : []), ...(gtkDmabufKinds() === [] ? [] : ['dmabuf'])];

    return $kinds === [] ? 'none' : implode(', ', $kinds);
}

/** A frame an hour long: within a test only reads end it. */
function inputFrame(): \Surface\HumanInput\InputFrame
{
    return new \Surface\HumanInput\InputFrame(fn (): int => 3_600_000_000_000);
}

/** A gtk input engine on the shared driver's session, connected; let go by letGoOfInput(). */
function gtkInput(?Closure $active = null, ?Closure $inverted = null): \Jovian\Toolkits\GTK\Input\GTKInputEngine
{
    session();
    $engine = new \Jovian\Toolkits\GTK\Input\GTKInputEngine(inputFrame(), driver(), $active, $inverted);
    $GLOBALS['gtk_input_engines'][] = $engine;

    return $engine->connect();
}

/** Disconnects every engine gtkInput() made: their taps leave the shared session. */
function letGoOfInput(): void
{
    foreach ($GLOBALS['gtk_input_engines'] ?? [] as $engine) {
        $engine->disconnect();
    }
    $GLOBALS['gtk_input_engines'] = [];
}

/** The engine's controller of $class on $window, wired by a poll. */
function controllerOf(\Jovian\Toolkits\GTK\Input\GTKInputEngine $engine, string $window, string $class): GtkEventController
{
    foreach ($engine->controllersOf($window) as $controller) {
        if ($controller instanceof $class) {
            return $controller;
        }
    }

    throw new RuntimeException("No {$class} on '{$window}'.");
}

/** The hardware keycode GTK reports for a key position here: the macOS key code, or the evdev code + 8. */
function keycodeOf(string $position): int
{
    [$mac, $evdev] = ['a' => [0x00, 30], 'q' => [0x0C, 16], 'shift' => [0x38, 42], 'rshift' => [0x3C, 54], 'e' => [0x0E, 18]][$position];

    return PHP_OS_FAMILY === 'Darwin' ? $mac : $evdev + 8;
}

/** Activate an item the way its menu does: through the window's "win" action. */
function choose(Jovian\Toolkits\GTK\Windows\GTKWindow $window, string $id): void
{
    expect($window->native()->activateActionVariant("win.{$id}", null))->toBeTrue();
}

/** A text buffer's whole text, hidden characters left out. */
function bufferText(GtkTextBuffer $buffer): string
{
    return $buffer->getText($buffer->getStartIter(), $buffer->getEndIter(), false) ?? '';
}

/** The iter at $line's start (GTK's buffer end past the last line). */
function lineIter(GtkTextBuffer $buffer, int $line): GtkTextIter
{
    $buffer->getIterAtLine($iter, $line);

    return $iter;
}

/** The iter at $offset in $line (GTK's line end past it). */
function lineOffsetIter(GtkTextBuffer $buffer, int $line, int $offset): GtkTextIter
{
    $buffer->getIterAtLineOffset($iter, $line, $offset);

    return $iter;
}

/**
 * ($x, $y) in $from's coordinates in $to's, by gtk_widget_compute_point.
 *
 * @return array{float, float}
 */
function widgetPoint(GtkWidget $from, GtkWidget $to, float $x, float $y): array
{
    expect($from->computePoint($to, new GraphenePoint($x, $y), $point))->toBeTrue();

    return [$point->x, $point->y];
}

/**
 * The window point of ($x, $y) from $primitive's border-box top-left, once the window is laid out.
 * Shared by the canvas click and canvas pointer tests, which both speak in canvas coordinates.
 *
 * @return array{float, float}
 */
function canvasPointIn(GTKWindow $window, Surface\Contracts\Windows\Primitives\TKPrimitive $primitive, float $x, float $y): array
{
    $window->present();
    for ($waited = 0; $primitive->native()->getWidth() === 0 && $waited < 60; $waited++) {
        pumpFor(0.05);
    }
    [$bx, $by] = boundsOrigin($primitive->native());

    return widgetPoint($primitive->native(), $window->native(), $x + $bx, $y + $by);
}

/**
 * The origin of $widget's bounds in its own coordinates: its border box's corner.
 *
 * @return array{float, float}
 */
function boundsOrigin(GtkWidget $widget): array
{
    expect($widget->computeBounds($widget, $bounds))->toBeTrue();

    return [$bounds->origin->x, $bounds->origin->y];
}

/** Whether GTK has a media backend: without one its media file is the GtkNoMediaFile stand-in. */
function mediaBackend(): bool
{
    return GtkMediaFile::new()->typeName() !== 'GtkNoMediaFile';
}

/** The first label under $root (popovers included) that shows $text. */
function labelIn(GtkWidget $root, string $text): ?GtkLabel
{
    if ($root instanceof GtkLabel && $root->getText() === $text) {
        return $root;
    }
    for ($child = $root->getFirstChild(); ! is_null($child); $child = $child->getNextSibling()) {
        $found = labelIn($child, $text);
        if (! is_null($found)) {
            return $found;
        }
    }

    return null;
}
