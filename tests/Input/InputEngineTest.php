<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Input\GTKInputEngine;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\HumanInput\PadSource;
use Voyager\Vessel\ControlPanel;

afterEach(function (): void {
    letGoOfInput();
    driver()->closeAll();
    pumpUntil(fn (): bool => driver()->all() === []);
});

it('wires five capture-phase controllers to each window once', function (): void {
    driver()->open('keys', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $engine->poll();
    $controllers = $engine->controllersOf('keys');

    expect(array_map(fn (GtkEventController $c): string => $c::class, $controllers))
        ->toBe([GtkEventControllerKey::class, GtkEventControllerMotion::class, GtkEventControllerScroll::class, GtkGestureClick::class, GtkEventControllerFocus::class])
        ->and(array_map(fn (GtkEventController $c): GtkPropagationPhase => $c->getPropagationPhase(), $controllers))->toBe(array_fill(0, 5, GtkPropagationPhase::CAPTURE))
        ->and(array_map(fn (GtkEventController $c): ?GtkWidget => $c->getWidget(), $controllers))->toBe(array_fill(0, 5, driver()->get('keys')->native()));
});

it('reads keys by position and types the keyval\'s text', function (): void {
    driver()->open('keys', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $key = controllerOf($engine, 'keys', GtkEventControllerKey::class);

    g_signal_emit_by_name($key, 'key-pressed', 0x61, keycodeOf('q'), 0);
    $engine->poll();
    [$down, $text] = [$engine->keyboard()->isDown(Key::Q), $engine->keyboard()->text()];
    g_signal_emit_by_name($key, 'key-released', 0x61, keycodeOf('q'), 0);
    $engine->poll();

    expect([$down, $text])->toBe([true, 'a'])
        ->and($engine->keyboard()->isDown(Key::Q))->toBeFalse()
        ->and($engine->keyboard()->wasReleased(Key::Q))->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::A))->toBeFalse();
});

it('ignores the VoidSymbol press macOS GTK sends after each key, which never releases', function (): void {
    driver()->open('void', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $key = controllerOf($engine, 'void', GtkEventControllerKey::class);

    g_signal_emit_by_name($key, 'key-pressed', 0x71, keycodeOf('q'), 0);
    g_signal_emit_by_name($key, 'key-pressed', 0xFFFFFF, 0, 0);
    $engine->poll();

    expect($engine->keyboard()->pressedKeys())->toBe([Key::Q])
        ->and($engine->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($engine->keyboard()->text())->toBe('q');
});

it('withholds text for shortcut chords, not for Option on macOS', function (): void {
    expect([
        GTKInputEngine::text(0x63, GDK_CONTROL_MASK),
        GTKInputEngine::text(0x63, GDK_META_MASK),
        GTKInputEngine::text(0x63, GDK_SUPER_MASK),
        GTKInputEngine::text(0x0D, 0),
        GTKInputEngine::text(0x7F, 0),
        GTKInputEngine::text(0, 0),
        GTKInputEngine::text(0xE9, GDK_ALT_MASK, macos: true),
        GTKInputEngine::text(0xE9, GDK_ALT_MASK, macos: false),
        GTKInputEngine::text(0xE9, GDK_SHIFT_MASK),
    ])->toBe(['', '', '', '', '', '', 'é', '', 'é']);
});

it('reads modifiers from key events, each event\'s own modifier key applied, and ignores the modifiers signal', function (): void {
    driver()->open('keys', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $key = controllerOf($engine, 'keys', GtkEventControllerKey::class);
    $read = function () use ($engine): bool {
        $engine->poll();

        return $engine->keyboard()->modifiers()->shift;
    };

    g_signal_emit_by_name($key, 'key-pressed', 0xFFE1, keycodeOf('shift'), 0);
    $shift_down = $read();
    g_signal_emit_by_name($key, 'key-pressed', 0x41, keycodeOf('a'), GDK_SHIFT_MASK);
    g_signal_emit_by_name($key, 'modifiers', 0);
    g_signal_emit_by_name($key, 'key-pressed', 0xFFFFFF, 0, 0);
    $through_a = $read();
    g_signal_emit_by_name($key, 'key-released', 0x41, keycodeOf('a'), GDK_SHIFT_MASK);
    g_signal_emit_by_name($key, 'key-released', 0xFFE1, keycodeOf('shift'), GDK_SHIFT_MASK);

    expect([$shift_down, $through_a, $read()])->toBe([true, true, false])
        ->and(GTKInputEngine::modifiers(GDK_SHIFT_MASK | GDK_SUPER_MASK))->toEqual(new Modifiers(shift: true, ctrl: false, alt: false, meta: true))
        ->and(GTKInputEngine::modifiers(GDK_CONTROL_MASK | GDK_ALT_MASK | GDK_META_MASK))->toEqual(new Modifiers(shift: false, ctrl: true, alt: true, meta: true));
});

it('keeps a modifier while its other side is held', function (): void {
    driver()->open('keys', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $key = controllerOf($engine, 'keys', GtkEventControllerKey::class);

    g_signal_emit_by_name($key, 'key-pressed', 0xFFE1, keycodeOf('shift'), 0);
    g_signal_emit_by_name($key, 'key-pressed', 0xFFE2, keycodeOf('rshift'), GDK_SHIFT_MASK);
    g_signal_emit_by_name($key, 'key-released', 0xFFE1, keycodeOf('shift'), GDK_SHIFT_MASK);
    $engine->poll();
    $one_side = $engine->keyboard()->modifiers()->shift;
    g_signal_emit_by_name($key, 'key-released', 0xFFE2, keycodeOf('rshift'), GDK_SHIFT_MASK);
    $engine->poll();

    expect([$one_side, $engine->keyboard()->modifiers()->shift])->toBe([true, false]);
});

it('names hardware keycodes by position on each OS', function (): void {
    expect([GTKInputEngine::keyOf(30 + 8, macos: false), GTKInputEngine::keyOf(0x00, macos: true), GTKInputEngine::keyOf(42 + 8, macos: false), GTKInputEngine::keyOf(0x38, macos: true), GTKInputEngine::keyOf(7, macos: false)])
        ->toBe([Key::A, Key::A, Key::LEFT_SHIFT, Key::LEFT_SHIFT, Key::UNKNOWN]);
});

it('tracks the pointer, its window and motion, and forgets the window it leaves', function (): void {
    driver()->open('pointer', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $motion = controllerOf($engine, 'pointer', GtkEventControllerMotion::class);
    g_signal_emit_by_name($motion, 'enter', 10.0, 20.0);
    g_signal_emit_by_name($motion, 'motion', 15.0, 18.0);
    $engine->poll();
    $over = [$engine->mouse()->x(), $engine->mouse()->y(), $engine->mouse()->window(), $engine->mouse()->motion()];
    g_signal_emit_by_name($motion, 'leave');
    $engine->poll();

    expect($over)->toBe([15.0, 18.0, 'pointer', ['dx' => 5.0, 'dy' => -2.0]])
        ->and([$engine->mouse()->x(), $engine->mouse()->y(), $engine->mouse()->window()])->toBe([15.0, 18.0, null]);
});

it('reads the button the gesture reports, at the click point', function (): void {
    driver()->open('click', 200, 100);
    $engine = gtkInput(button: fn (GtkGestureSingle $gesture): int => 3);
    $engine->poll();
    $click = controllerOf($engine, 'click', GtkGestureClick::class);
    g_signal_emit_by_name($click, 'pressed', 1, 40.0, 30.0);
    $engine->poll();
    $down = [$engine->mouse()->isDown(MouseButton::RIGHT), $engine->mouse()->x(), $engine->mouse()->y(), $engine->mouse()->window()];
    g_signal_emit_by_name($click, 'released', 1, 40.0, 30.0);
    $engine->poll();

    expect($down)->toBe([true, 40.0, 30.0, 'click'])
        ->and($engine->mouse()->isDown(MouseButton::RIGHT))->toBeFalse()
        ->and(array_map(fn (int $b): ?MouseButton => GTKInputEngine::buttonOf($b), [1, 2, 3, 8, 9, 4]))
        ->toBe([MouseButton::LEFT, MouseButton::MIDDLE, MouseButton::RIGHT, MouseButton::X1, MouseButton::X2, null]);
});

it('turns GTK\'s down-positive vertical scrolling round, ten pixels to a line', function (): void {
    driver()->open('wheel', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    g_signal_emit_by_name(controllerOf($engine, 'wheel', GtkEventControllerScroll::class), 'scroll', 1.0, 2.0);
    $engine->poll();

    expect($engine->mouse()->wheel())->toBe(['dx' => 1.0, 'dy' => -2.0])
        ->and(GTKInputEngine::wheel(5.0, -20.0, pixels: true))->toBe([0.5, 2.0]);
});

it('undoes natural scrolling: a scroll GTK marks inverted reads as the fingers moved, as on sdl3', function (): void {
    driver()->open('natural', 200, 100);
    $engine = gtkInput(inverted: fn (GtkEventControllerScroll $scroll): bool => true);
    $engine->poll();
    g_signal_emit_by_name(controllerOf($engine, 'natural', GtkEventControllerScroll::class), 'scroll', 1.0, 2.0);
    $engine->poll();

    expect($engine->mouse()->wheel())->toBe(['dx' => -1.0, 'dy' => 2.0])
        ->and(GTKInputEngine::wheel(5.0, -20.0, pixels: true, inverted: true))->toBe([-0.5, -2.0]);
});

it('releases every key and button when focus leaves for no window of the driver', function (): void {
    driver()->open('focus', 200, 100);
    $active = true;
    $engine = gtkInput(button: fn (GtkGestureSingle $gesture): int => 1, active: function () use (&$active): bool {
        return $active;
    });
    $engine->poll();
    g_signal_emit_by_name(controllerOf($engine, 'focus', GtkEventControllerKey::class), 'key-pressed', 0x61, keycodeOf('a'), 0);
    g_signal_emit_by_name(controllerOf($engine, 'focus', GtkGestureClick::class), 'pressed', 1, 1.0, 1.0);
    g_signal_emit_by_name(controllerOf($engine, 'focus', GtkEventControllerFocus::class), 'leave');
    $engine->poll();
    $kept = [$engine->keyboard()->isDown(Key::A), $engine->mouse()->isDown(MouseButton::LEFT)];
    $active = false;
    g_signal_emit_by_name(controllerOf($engine, 'focus', GtkEventControllerFocus::class), 'leave');
    $engine->poll();

    expect($kept)->toBe([true, true])
        ->and([$engine->keyboard()->isDown(Key::A), $engine->mouse()->isDown(MouseButton::LEFT)])->toBe([false, false])
        ->and($engine->keyboard()->wasReleased(Key::A))->toBeTrue();
});

it('releases what was held when its window closes, and forgets the window', function (): void {
    $window = driver()->open('closing', 200, 100);
    $engine = gtkInput(active: fn (): bool => false);
    $engine->poll();
    g_signal_emit_by_name(controllerOf($engine, 'closing', GtkEventControllerKey::class), 'key-pressed', 0x61, keycodeOf('a'), 0);
    $engine->poll();
    $held = $engine->keyboard()->isDown(Key::A);
    $window->close();
    pumpUntil(fn (): bool => ! driver()->has('closing'));
    $engine->poll();

    expect($held)->toBeTrue()
        ->and($engine->keyboard()->isDown(Key::A))->toBeFalse()
        ->and($engine->controllersOf('closing'))->toBe([]);
});

it('wires a window opened again under the same name afresh', function (): void {
    $first = driver()->open('again', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $before = $engine->controllersOf('again');
    $first->close();
    pumpUntil(fn (): bool => ! driver()->has('again'));
    driver()->open('again', 200, 100);
    $engine->poll();

    expect($engine->controllersOf('again'))->toHaveCount(5)
        ->and($engine->controllersOf('again')[0])->not->toBe($before[0])
        ->and($engine->controllersOf('again')[0]->getWidget())->toBe(driver()->get('again')->native());
});

it('takes its controllers off windows still open when it disconnects, and sees nothing after', function (): void {
    driver()->open('leaving', 200, 100);
    $engine = gtkInput();
    $engine->poll();
    $controllers = $engine->controllersOf('leaving');
    $engine->disconnect();
    g_signal_emit_by_name($controllers[0], 'key-pressed', 0x61, keycodeOf('a'), 0);
    $engine->poll();

    expect(array_map(fn (GtkEventController $c): ?GtkWidget => $c->getWidget(), $controllers))->toBe(array_fill(0, 5, null))
        ->and($engine->connected())->toBeFalse()
        ->and($engine->controllersOf('leaving'))->toBe([])
        ->and($engine->keyboard()->isDown(Key::A))->toBeFalse();
});

it('refuses to connect while its driver has no connected session, naming the toolkit', function (): void {
    $engine = new GTKInputEngine(inputFrame(), new GTKBridgeDriver(new ControlPanel()));

    expect(fn () => $engine->connect())->toThrow(HumanInputException::class, 'gtk: no connected GTK session to read input from')
        ->and($engine->connected())->toBeFalse();
});

it('lists no pads: pads are the pad source\'s', function (): void {
    expect(gtkInput())->not->toBeInstanceOf(PadSource::class);
});
