<?php

use Jovian\Venusian\GTK\Input\GTKInputEngine;
use Surface\Contracts\HumanInput\GamepadAxis;
use Surface\Contracts\HumanInput\GamepadButton;
use Surface\Contracts\HumanInput\InputEngine;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\GTK\Tests\Support\FakeControllerFactory;
use Venusian\GTK\Tests\Support\FakeGtkWindowSpace;
use Venusian\GTK\Tests\Support\FakePadScanner;
use Venusian\Surface\Tests\Support\Fakes\FakeControllerPad;

const GDK_SHIFT = 1;
const GDK_CONTROL = 4;
const GDK_ALT = 8;
const GDK_SUPER = 67108864;
const GDK_META = 268435456;
const GDK_SCROLL_WHEEL = 0;
const GDK_SCROLL_SURFACE = 1;

/** @return array{GTKInputEngine, FakeControllerFactory, FakeGtkWindowSpace, FakePadScanner} a connected engine with one window 'main' already attached */
function gtkInput(int $rescan_ticks = 60, array $pads = [], array $nodes = []): array
{
    $factory = new FakeControllerFactory;
    $windows = new FakeGtkWindowSpace(['main' => 11]);
    $scanner = new FakePadScanner($pads);
    $engine = new GTKInputEngine($factory, $windows, $scanner, $rescan_ticks, fn (): array => $nodes);
    $engine->connect();
    $engine->poll();

    return [$engine, $factory, $windows, $scanner];
}

function stickPad(): FakeControllerPad
{
    return new FakeControllerPad([GamepadButton::SOUTH, GamepadButton::EAST], [GamepadAxis::LEFT_X, GamepadAxis::LEFT_Y]);
}

it('is the gtk engine and owns no devices until connected', function () {
    $engine = new GTKInputEngine(new FakeControllerFactory, new FakeGtkWindowSpace, new FakePadScanner);

    expect($engine->engine())->toBe(InputEngine::GTK)
        ->and($engine->connected())->toBeFalse()
        ->and($engine->keyboard())->toBeNull()
        ->and($engine->mouse())->toBeNull()
        ->and($engine->gamePads())->toBe([])
        ->and($engine->gameControllers())->toBe([]);

    $engine->poll();

    expect($engine->connect())->toBe($engine)
        ->and($engine->connected())->toBeTrue()
        ->and($engine->keyboard())->not->toBeNull()
        ->and($engine->mouse())->not->toBeNull();
});

it('attaches controllers lazily to a new window and forgets a window that is gone', function () {
    $factory = new FakeControllerFactory;
    $windows = new FakeGtkWindowSpace(['main' => 11]);
    $engine = (new GTKInputEngine($factory, $windows, new FakePadScanner))->connect();

    expect($factory->attached)->toBe([]);

    $engine->poll();
    $engine->poll();

    expect($factory->attached)->toBe([['main', 11]]);

    $windows->windows = ['main' => 11, 'tools' => 12];
    $engine->poll();

    expect($factory->attached)->toBe([['main', 11], ['tools', 12]]);

    $windows->windows = ['tools' => 12];
    $engine->poll();

    expect($factory->forgotten)->toBe(['main'])
        ->and($factory->detached)->toBe([]);
});

it('reattaches a window whose name now points at a new handle', function () {
    [$engine, $factory, $windows] = gtkInput();

    $windows->windows = ['main' => 99];
    $engine->poll();

    expect($factory->forgotten)->toBe(['main'])
        ->and($factory->attached)->toBe([['main', 11], ['main', 99]]);
});

it('lands a key press by hardware keycode minus 8 only on the next poll', function () {
    [$engine, $factory] = gtkInput();

    $handled = $factory->fire('main', 'key_pressed', 0x61, 30 + 8, 0);

    expect($handled)->toBeFalse()
        ->and($engine->keyboard()->isDown(Key::A))->toBeFalse();

    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::A))->toBeTrue()
        ->and($engine->keyboard()->text())->toBe('a');

    $engine->poll();

    expect($engine->keyboard()->isDown(Key::A))->toBeTrue()
        ->and($engine->keyboard()->isPressed(Key::A))->toBeFalse()
        ->and($engine->keyboard()->text())->toBe('');

    $factory->fire('main', 'key_released', 0x61, 30 + 8, 0);
    $engine->poll();

    expect($engine->keyboard()->wasReleased(Key::A))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::A))->toBeFalse();
});

it('shows a tap inside one poll as pressed and released together', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'key_pressed', 0x20, 57 + 8, 0);
    $factory->fire('main', 'key_released', 0x20, 57 + 8, 0);
    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::SPACE))->toBeTrue()
        ->and($engine->keyboard()->wasReleased(Key::SPACE))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::SPACE))->toBeFalse();
});

it('withholds text under control, alt and super, and for control characters', function (int $keyval, int $state) {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'key_pressed', $keyval, 30 + 8, $state);
    $engine->poll();

    expect($engine->keyboard()->text())->toBe('')
        ->and($engine->keyboard()->isPressed(Key::A))->toBeTrue();
})->with([
    'control' => [0x61, GDK_CONTROL],
    'alt' => [0x61, GDK_ALT],
    'super' => [0x61, GDK_SUPER],
    'no code point' => [0xff0d, 0],
    'C0 control' => [0x1b, 0],
    'DEL' => [0x7f, 0],
]);

it('keeps text under shift', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'key_pressed', 0x41, 30 + 8, GDK_SHIFT);
    $factory->fire('main', 'key_pressed', 0xe9, 18 + 8, GDK_SHIFT);
    $engine->poll();

    expect($engine->keyboard()->text())->toBe('Aé');
});

it('reads modifiers from the modifiers signal; only super sets meta', function (int $state, array $expected) {
    [$engine, $factory] = gtkInput();

    expect($factory->fire('main', 'modifiers', $state))->toBeFalse();

    $engine->poll();
    $m = $engine->keyboard()->modifiers();

    expect([$m->shift, $m->ctrl, $m->alt, $m->meta])->toBe($expected);
})->with([
    'none' => [0, [false, false, false, false]],
    'shift' => [GDK_SHIFT, [true, false, false, false]],
    'super' => [GDK_SUPER, [false, false, false, true]],
    'meta alone' => [GDK_META, [false, false, false, false]],
    'alt + meta' => [GDK_ALT | GDK_META, [false, false, true, false]],
    'super + meta' => [GDK_SUPER | GDK_META, [false, false, false, true]],
    'control + alt' => [GDK_CONTROL | GDK_ALT, [false, true, true, false]],
]);

it('follows modifier keys when GTK signals the state from before them', function () {
    [$engine, $factory] = gtkInput();

    // What a Pi 5 (labwc) delivers for a lone Shift tap: every state is the pre-key state.
    $factory->fire('main', 'modifiers', 0);
    $factory->fire('main', 'key_pressed', 0xffe1, 42 + 8, 0);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->shift)->toBeTrue();

    $factory->fire('main', 'modifiers', GDK_SHIFT);
    $factory->fire('main', 'key_released', 0xffe1, 42 + 8, GDK_SHIFT);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->shift)->toBeFalse();

    // Right shift still held keeps shift after the left one comes up.
    $factory->fire('main', 'key_pressed', 0xffe1, 42 + 8, 0);
    $factory->fire('main', 'key_pressed', 0xffe2, 54 + 8, GDK_SHIFT);
    $factory->fire('main', 'key_released', 0xffe1, 42 + 8, GDK_SHIFT);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->shift)->toBeTrue();
});

it('trusts the signal for a modifier held before focus until its key is seen', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'modifiers', GDK_CONTROL);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->ctrl)->toBeTrue();

    $factory->fire('main', 'key_released', 0xffe3, 29 + 8, GDK_CONTROL);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->ctrl)->toBeFalse();
});

it('ignores the pre-event state that key events carry', function () {
    [$engine, $factory] = gtkInput();

    // A lone Shift tap as GTK reports it: the press carries the state before
    // Shift went down, the release the state before it came up.
    $factory->fire('main', 'key_pressed', 0xffe1, 42 + 8, 0);
    $factory->fire('main', 'modifiers', GDK_SHIFT);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->shift)->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::LEFT_SHIFT))->toBeTrue();

    $factory->fire('main', 'key_released', 0xffe1, 42 + 8, GDK_SHIFT);
    $factory->fire('main', 'modifiers', 0);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->shift)->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::LEFT_SHIFT))->toBeTrue();

    // A key event alone never moves the modifiers.
    $factory->fire('main', 'key_pressed', 0x61, 30 + 8, GDK_CONTROL);
    $engine->poll();

    expect($engine->keyboard()->modifiers()->ctrl)->toBeFalse();
});

it('maps an unknown keycode to UNKNOWN without throwing', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'key_pressed', 0, 3, 0);
    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::UNKNOWN))->toBeTrue();
});

it('reports motion from the second sample on a window', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'motion', 10.0, 20.0);
    $engine->poll();

    expect($engine->mouse()->x())->toBe(10.0)
        ->and($engine->mouse()->y())->toBe(20.0)
        ->and($engine->mouse()->window())->toBe('main')
        ->and($engine->mouse()->motion())->toBe(['dx' => 0.0, 'dy' => 0.0]);

    $factory->fire('main', 'motion', 13.0, 18.0);
    $factory->fire('main', 'motion', 15.0, 19.0);
    $engine->poll();

    expect($engine->mouse()->motion())->toBe(['dx' => 5.0, 'dy' => -1.0])
        ->and($engine->mouse()->x())->toBe(15.0);

    $engine->poll();

    expect($engine->mouse()->motion())->toBe(['dx' => 0.0, 'dy' => 0.0])
        ->and($engine->mouse()->x())->toBe(15.0);
});

it('takes position and window on enter, and measures the next motion from there', function () {
    [$engine, $factory, $windows] = gtkInput();
    $windows->windows = ['main' => 11, 'tools' => 12];
    $engine->poll();

    $factory->fire('tools', 'enter', 4.0, 5.0);
    $factory->fire('tools', 'motion', 6.0, 5.0);
    $engine->poll();

    expect($engine->mouse()->window())->toBe('tools')
        ->and($engine->mouse()->x())->toBe(6.0)
        ->and($engine->mouse()->motion())->toBe(['dx' => 2.0, 'dy' => 0.0]);
});

it('clears the window on leave only when the leaving window is the current one', function () {
    [$engine, $factory, $windows] = gtkInput();
    $windows->windows = ['main' => 11, 'tools' => 12];
    $engine->poll();

    $factory->fire('main', 'enter', 1.0, 2.0);
    $factory->fire('tools', 'enter', 3.0, 4.0);
    $factory->fire('main', 'leave');
    $engine->poll();

    expect($engine->mouse()->window())->toBe('tools');

    $factory->fire('tools', 'leave');
    $engine->poll();

    expect($engine->mouse()->window())->toBeNull()
        ->and($engine->mouse()->x())->toBe(3.0)
        ->and($engine->mouse()->y())->toBe(4.0);
});

it('maps the gesture current button to a mouse button', function (int $gtk, MouseButton $button) {
    [$engine, $factory] = gtkInput();
    $factory->button = $gtk;

    $factory->fire('main', 'pressed', 1, 7.0, 8.0);
    $engine->poll();

    expect($engine->mouse()->isPressed($button))->toBeTrue()
        ->and($engine->mouse()->x())->toBe(7.0)
        ->and($engine->mouse()->window())->toBe('main');

    $factory->fire('main', 'released', 1, 7.0, 8.0);
    $engine->poll();

    expect($engine->mouse()->wasReleased($button))->toBeTrue();
})->with([
    'left' => [1, MouseButton::LEFT],
    'middle' => [2, MouseButton::MIDDLE],
    'right' => [3, MouseButton::RIGHT],
    'x1' => [8, MouseButton::X1],
    'x2' => [9, MouseButton::X2],
]);

it('ignores an unmapped mouse button', function () {
    [$engine, $factory] = gtkInput();
    $factory->button = 5;

    $factory->fire('main', 'pressed', 1, 7.0, 8.0);
    $engine->poll();

    foreach (MouseButton::cases() as $button) {
        expect($engine->mouse()->isDown($button))->toBeFalse();
    }
});

it('drops the window and releases held buttons when the current window is removed', function () {
    [$engine, $factory, $windows] = gtkInput();
    $windows->windows = ['main' => 11, 'tools' => 12];
    $engine->poll();

    $factory->fire('main', 'enter', 5.0, 6.0);
    $factory->button = 3;
    $factory->fire('main', 'pressed', 1, 5.0, 6.0);
    $engine->poll();

    expect($engine->mouse()->isDown(MouseButton::RIGHT))->toBeTrue();

    $windows->windows = ['tools' => 12];
    $engine->poll();

    expect($engine->mouse()->window())->toBeNull()
        ->and($engine->mouse()->x())->toBe(5.0)
        ->and($engine->mouse()->isDown(MouseButton::RIGHT))->toBeFalse()
        ->and($engine->mouse()->wasReleased(MouseButton::RIGHT))->toBeTrue();
});

it('keeps the pointer when a window other than the current one is removed', function () {
    [$engine, $factory, $windows] = gtkInput();
    $windows->windows = ['main' => 11, 'tools' => 12];
    $engine->poll();

    $factory->fire('main', 'pressed', 1, 5.0, 6.0);
    $engine->poll();

    $windows->windows = ['main' => 11];
    $engine->poll();

    expect($engine->mouse()->window())->toBe('main')
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeTrue();
});

it('flips the scroll sign so up is positive and lets widgets have the scroll', function () {
    [$engine, $factory] = gtkInput();

    $handled = $factory->fire('main', 'scroll', 1.0, -2.0, GDK_SCROLL_WHEEL);
    $factory->fire('main', 'scroll', 0.5, -1.0, GDK_SCROLL_WHEEL);
    $engine->poll();

    expect($handled)->toBeFalse()
        ->and($engine->mouse()->wheel())->toBe(['dx' => -1.5, 'dy' => 3.0]);
});

it('scales surface-unit scroll deltas to lines', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'scroll', 25.0, -40.0, GDK_SCROLL_SURFACE);
    $factory->fire('main', 'scroll', 0.0, 1.0, GDK_SCROLL_WHEEL);
    $engine->poll();

    expect($engine->mouse()->wheel())->toBe(['dx' => -2.5, 'dy' => 3.0]);
});

it('releases every key and mouse button when a window loses focus', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'key_pressed', 0x61, 30 + 8, 0);
    $factory->fire('main', 'modifiers', GDK_SHIFT);
    $factory->button = 1;
    $factory->fire('main', 'pressed', 1, 5.0, 6.0);
    $engine->poll();

    expect($engine->keyboard()->isDown(Key::A))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeTrue();

    $factory->fire('main', 'active', true);
    $engine->poll();

    expect($engine->keyboard()->isDown(Key::A))->toBeTrue()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeTrue();

    $factory->fire('main', 'active', false);

    expect($engine->keyboard()->isDown(Key::A))->toBeTrue();

    $engine->poll();

    expect($engine->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::A))->toBeTrue()
        ->and($engine->keyboard()->releasedKeys())->toBe([Key::A])
        ->and($engine->keyboard()->downKeys())->toBe([])
        ->and($engine->keyboard()->modifiers()->shift)->toBeFalse()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeFalse()
        ->and($engine->mouse()->wasReleased(MouseButton::LEFT))->toBeTrue()
        ->and($engine->mouse()->window())->toBe('main');

    $engine->poll();

    expect($engine->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::A))->toBeFalse()
        ->and($engine->mouse()->isDown(MouseButton::LEFT))->toBeFalse()
        ->and($engine->mouse()->wasReleased(MouseButton::LEFT))->toBeFalse();
});

it('keeps text typed before focus left in the same poll', function () {
    [$engine, $factory] = gtkInput();

    $factory->fire('main', 'key_pressed', 0x61, 30 + 8, 0);
    $factory->fire('main', 'active', false);
    $engine->poll();

    expect($engine->keyboard()->text())->toBe('a')
        ->and($engine->keyboard()->isPressed(Key::A))->toBeTrue()
        ->and($engine->keyboard()->wasReleased(Key::A))->toBeTrue();
});

/** Run $body with no error handler of the test's own; answer the last PHP error it left, if any. */
function lastErrorAfter(Closure $body): ?array
{
    error_clear_last();
    $body();

    return error_get_last();
}

it('records and skips a window whose attach throws, still attaching the others', function () {
    $factory = new FakeControllerFactory;
    $factory->refuse = ['broken'];
    $windows = new FakeGtkWindowSpace(['broken' => 10, 'main' => 11]);
    $engine = (new GTKInputEngine($factory, $windows, new FakePadScanner))->connect();

    expect(lastErrorAfter(fn () => $engine->poll()))->toBeNull();

    $errors = $engine->errors();

    expect($factory->attached)->toBe([['broken', 10], ['main', 11]])
        ->and($factory->on)->toHaveKey('main')
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toStartWith('input.gtk: ')
        ->and($errors[0])->toContain("'broken'")
        ->and($engine->errors())->toBe([]);

    $factory->fire('main', 'key_pressed', 0x61, 30 + 8, 0);
    $engine->poll();

    expect($engine->keyboard()->isPressed(Key::A))->toBeTrue();
});

it('retries a failing window attach only on the rescan cadence and records the streak once', function () {
    $factory = new FakeControllerFactory;
    $factory->refuse = ['broken'];
    $windows = new FakeGtkWindowSpace(['broken' => 10]);
    $engine = (new GTKInputEngine($factory, $windows, new FakePadScanner, 3))->connect();

    $engine->poll(); // 1: first sight, fails
    $engine->poll(); // 2
    expect($factory->attached)->toHaveCount(1);

    $engine->poll(); // 3: rescan, retried
    expect($factory->attached)->toHaveCount(2);

    $engine->poll(); // 4
    $engine->poll(); // 5
    $engine->poll(); // 6: rescan, retried
    expect($factory->attached)->toHaveCount(3)
        ->and($engine->errors())->toHaveCount(1);

    // A new handle starts a new streak: tried at once, recorded again.
    $windows->windows = ['broken' => 20];
    $engine->poll(); // 7
    expect($factory->attached)->toBe([['broken', 10], ['broken', 10], ['broken', 10], ['broken', 20]])
        ->and($engine->errors())->toHaveCount(1);

    // Success ends the streak; a later failure is recorded again.
    $factory->refuse = [];
    $engine->poll(); // 8
    $engine->poll(); // 9: rescan, attaches
    expect($factory->on)->toHaveKey('broken');

    $factory->refuse = ['broken'];
    $windows->windows = [];
    $engine->poll(); // 10
    $windows->windows = ['broken' => 20];
    $engine->poll(); // 11: seen again, fails
    expect($engine->errors())->toHaveCount(1);
});

it('records and skips a node whose open throws, still opening the others', function () {
    $scanner = new FakePadScanner(['/dev/input/event2' => stickPad(), '/dev/input/event3' => stickPad()]);
    $scanner->refuse = ['/dev/input/event2'];
    $engine = new GTKInputEngine(new FakeControllerFactory, new FakeGtkWindowSpace, $scanner);

    expect(lastErrorAfter(fn () => $engine->connect()))->toBeNull();

    $errors = $engine->errors();

    expect(array_keys($engine->gameControllers()))->toBe(['event3'])
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('/dev/input/event2');
});

it('records a failing scan, skips that rescan and stays connected', function () {
    $scanner = new FakePadScanner;
    $scanner->fail_scan = true;
    $engine = new GTKInputEngine(new FakeControllerFactory, new FakeGtkWindowSpace(['main' => 11]), $scanner, 1);

    $error = lastErrorAfter(function () use ($engine): void {
        $engine->connect();
        $engine->poll();
    });

    expect($error)->toBeNull()
        ->and($engine->connected())->toBeTrue()
        ->and($engine->errors())->toBe(['input.gtk: gamepad scan failed: scan failed', 'input.gtk: gamepad scan failed: scan failed']);
});

it('notes unreadable input nodes once per connect', function () {
    [$engine] = gtkInput(rescan_ticks: 1, nodes: ['/dev/input/event0' => false, '/dev/input/event1' => false]);
    $engine->poll();
    $engine->poll();

    expect($engine->errors())->toBe(['input.gtk: no readable /dev/input/event*: add the user to the input group']);

    $engine->disconnect();
    $engine->connect();

    expect($engine->errors())->toHaveCount(1);
});

it('says nothing about access when a node is readable, none exist, or pads were found', function (array $nodes, array $pads) {
    [$engine] = gtkInput(rescan_ticks: 1, pads: $pads, nodes: $nodes);
    $engine->poll();

    expect($engine->errors())->toBe([]);
})->with([
    'one readable' => [['/dev/input/event0' => false, '/dev/input/event1' => true], []],
    'no nodes' => [[], []],
    'pads listed' => [['/dev/input/event0' => false], ['/dev/input/event3' => new FakeControllerPad([GamepadButton::SOUTH], [])]],
]);

it('carries no trigger_error in the engine sources', function () {
    foreach (glob(dirname(__DIR__, 2).'/src/Input/*.php') as $file) {
        expect(file_get_contents($file))->not->toContain('trigger_error');
    }
});

it('opens pads present at connect and splits controllers from pads', function () {
    $plain = new FakeControllerPad([GamepadButton::SOUTH], []);
    [$engine, , , $scanner] = gtkInput(pads: ['/dev/input/event3' => stickPad(), '/dev/input/event7' => $plain]);

    expect($scanner->scans)->toBe(1)
        ->and(array_keys($engine->gameControllers()))->toBe(['event3'])
        ->and(array_keys($engine->gamePads()))->toBe(['event7'])
        ->and($engine->gamePads()['event7']->id())->toBe('event7');
});

it('polls pads each tick', function () {
    $pad = stickPad();
    [$engine] = gtkInput(pads: ['/dev/input/event3' => $pad]);

    $pad->down = [GamepadButton::SOUTH];
    $pad->values = ['left_x' => 0.5];
    $engine->poll();

    $controller = $engine->gameControllers()['event3'];

    expect($controller->isPressed(GamepadButton::SOUTH))->toBeTrue()
        ->and($controller->axis(GamepadAxis::LEFT_X))->toBe(0.5);
});

it('picks up a new pad on the rescan tick, not before', function () {
    [$engine, , , $scanner] = gtkInput(rescan_ticks: 3);
    // gtkInput already polled once.
    $scanner->pads['/dev/input/event4'] = stickPad();

    $engine->poll();

    expect($engine->gameControllers())->toBe([])
        ->and($scanner->scans)->toBe(1);

    $engine->poll();

    expect(array_keys($engine->gameControllers()))->toBe(['event4'])
        ->and($scanner->scans)->toBe(2);

    $engine->poll();
    $engine->poll();
    $engine->poll();

    expect($scanner->scans)->toBe(3)
        ->and($scanner->opened)->toBe(['/dev/input/event4']);
});

it('lists only connected pads', function () {
    $stick = stickPad();
    $plain = new FakeControllerPad([GamepadButton::SOUTH], []);
    [$engine] = gtkInput(pads: ['/dev/input/event3' => $stick, '/dev/input/event7' => $plain]);

    $stick->connected = false;
    $plain->connected = false;

    expect($engine->gameControllers())->toBe([])
        ->and($engine->gamePads())->toBe([]);

    $stick->connected = true;

    expect(array_keys($engine->gameControllers()))->toBe(['event3']);
});

it('drops and closes a pad that reports disconnected on the next rescan', function () {
    $pad = stickPad();
    [$engine, , , $scanner] = gtkInput(rescan_ticks: 2, pads: ['/dev/input/event3' => $pad]);
    // One poll done; the next poll is a rescan tick.
    $engine->poll();

    $pad->connected = false;
    unset($scanner->pads['/dev/input/event3']);
    $engine->poll();

    expect($scanner->closed)->toBe([]);

    $engine->poll();

    expect($engine->gameControllers())->toBe([])
        ->and($scanner->closed)->toBe([$pad]);
});

it('reopens a pad whose node is listed again after it dropped', function () {
    $pad = stickPad();
    [$engine, , , $scanner] = gtkInput(rescan_ticks: 1, pads: ['/dev/input/event3' => $pad]);

    $pad->connected = false;
    $replacement = stickPad();
    $scanner->pads['/dev/input/event3'] = $replacement;
    $engine->poll();

    expect($scanner->closed)->toBe([$pad])
        ->and($engine->gameControllers()['event3'])->not->toBeNull()
        ->and($scanner->opened)->toBe(['/dev/input/event3', '/dev/input/event3']);
});

it('detaches every window, closes every pad and drops its devices on disconnect', function () {
    $pad = stickPad();
    [$engine, $factory, $windows, $scanner] = gtkInput(pads: ['/dev/input/event3' => $pad]);
    $windows->windows = ['main' => 11, 'tools' => 12];
    $engine->poll();

    $factory->fire('main', 'key_pressed', 0x61, 30 + 8, 0);
    $engine->disconnect();

    expect($factory->detached)->toBe(['main', 'tools'])
        ->and($scanner->closed)->toBe([$pad])
        ->and($engine->connected())->toBeFalse()
        ->and($engine->keyboard())->toBeNull()
        ->and($engine->gameControllers())->toBe([]);

    $engine->disconnect();

    expect($factory->detached)->toBe(['main', 'tools']);

    $engine->connect();
    $engine->poll();

    expect($engine->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($factory->attached)->toBe([['main', 11], ['tools', 12], ['main', 11], ['tools', 12]]);
});

it('only forgets, never detaches, a window already gone at disconnect', function () {
    [$engine, $factory, $windows] = gtkInput();

    $windows->windows = [];
    $engine->disconnect();

    expect($factory->detached)->toBe([])
        ->and($factory->forgotten)->toBe(['main']);
});
