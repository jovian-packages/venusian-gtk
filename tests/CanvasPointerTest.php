<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKCanvasPointer;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;
use Surface\Contracts\Windows\Mail\View\CanvasWheeled;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;
use Surface\Contracts\Windows\Mail\View\RightClicked;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return list<PrimitiveMail> the pointer, wheel and right-click mail posted since the last read */
function pointerMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasPressed
        || $mail instanceof CanvasDragged
        || $mail instanceof CanvasReleased
        || $mail instanceof CanvasWheeled
        || $mail instanceof RightClicked));
}

/** @return list<string> the names of that mail, in order */
function pointerNames(array $mail): array
{
    return array_map(fn (PrimitiveMail $one): string => $one->name(), $mail);
}

/** A watching canvas filling a column, a button beside it, and the window's pointer. */
function pointerWindow(bool $watching = true): array
{
    $window = driver()->open('main', 300, 240);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    $beside = $column->button('beside', 'Beside');
    if ($watching) {
        $board->watchPointer();
    }

    return [$window, $column, $board, $beside];
}

/** GTK's double-click time in seconds and distance in pixels. */
function doubleClickSettings(): array
{
    $settings = GtkSettings::getDefault();

    return [
        g_object_get_property($settings, 'gtk-double-click-time') / 1000,
        (int) g_object_get_property($settings, 'gtk-double-click-distance'),
    ];
}

it('posts nothing for a press, a drag and a release on a canvas that is not watching', function (): void {
    [$window, , $board] = pointerWindow(watching: false);
    [$x, $y] = canvasPointIn($window, $board, 12.0, 34.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);
    $pointer->moved($x + 6.0, $y, 0);
    $pointer->released($x + 6.0, $y, 1, 0);

    expect(pointerMail())->toBe([])
        ->and($board->isWatchingPointer())->toBeFalse();
});

it('posts the press, each move that changed the point, and the release, in canvas coordinates', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 12.0, 34.0);
    [$mx, $my] = canvasPointIn($window, $board, 18.0, 34.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);
    $pointer->moved($mx, $my, 0);
    $pointer->moved($mx, $my, 0);                                    // the same point again: nothing
    $pointer->released($mx, $my, 1, 0);

    $mail = pointerMail();
    expect(pointerNames($mail))->toBe(['view.pressed.main.m.board', 'view.dragged.main.m.board', 'view.released.main.m.board'])
        ->and(array_map(fn (PrimitiveMail $one): array => [round($one->x), round($one->y)], $mail))->toBe([[12.0, 34.0], [18.0, 34.0], [18.0, 34.0]])
        ->and([$mail[0]->button, $mail[1]->button, $mail[2]->button])->toBe([MouseButton::LEFT, MouseButton::LEFT, MouseButton::LEFT])
        ->and([$mail[0]->clicks, $mail[0]->uuid])->toBe([1, $board->uuid()])
        ->and($mail[0]->modifiers)->toEqual(new Modifiers());
});

it('takes a first move less than a point from the press for the press itself, as X11 reports it in whole pixels', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 12.3, 34.5);
    takeMail(session());
    $pointer = $window->canvasPointer();
    // X11 on the gaming PC: the press at (40.1, 36.5), the motion GTK follows it with at (40.0, 36.0).
    $pointer->pressed($x, $y, 1, 0);
    $pointer->moved(floor($x), floor($y), 0);
    $pointer->released($x, $y, 1, 0);
    $click = pointerNames(pointerMail());
    $pointer->pressed($x, $y, 1, 0);
    $pointer->moved(floor($x), floor($y), 0);
    $pointer->moved($x + 1.0, $y, 0);
    $pointer->moved($x + 1.4, $y, 0);                                // once dragging, every change posts
    $pointer->released($x + 1.4, $y, 1, 0);

    expect($click)->toBe(['view.pressed.main.m.board', 'view.released.main.m.board'])
        ->and(pointerNames(pointerMail()))->toBe(['view.pressed.main.m.board', 'view.dragged.main.m.board', 'view.dragged.main.m.board', 'view.released.main.m.board']);
});

it('carries the modifiers held with the press, the drag and the release', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 10.0, 10.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, GDK_SHIFT_MASK | GDK_CONTROL_MASK);
    $pointer->moved($x + 5.0, $y, GDK_SHIFT_MASK);
    $pointer->released($x + 5.0, $y, 1, 0);

    $mail = pointerMail();
    expect($mail[0]->modifiers)->toEqual(new Modifiers(shift: true, ctrl: true))
        ->and($mail[1]->modifiers)->toEqual(new Modifiers(shift: true))
        ->and($mail[2]->modifiers)->toEqual(new Modifiers());
});

it('counts presses of the same button on the same canvas within GTK\'s double-click time and distance', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 40.0, 40.0);
    [$time, $distance] = doubleClickSettings();
    takeMail(session());
    $pointer = $window->canvasPointer();
    $at = microtime(true);
    foreach ([0.0, 0.05, 0.10] as $after) {                          // 50 ms apart: a triple click
        $pointer->pressed($x, $y, 1, 0, $at + $after);
        $pointer->released($x, $y, 1, 0);
    }
    $counted = array_map(fn (CanvasPressed $mail): int => $mail->clicks, array_values(array_filter(pointerMail(), fn (object $mail): bool => $mail instanceof CanvasPressed)));

    $pointer->pressed($x, $y, 1, 0, $at + 0.10 + $time * 1.5);        // too late to be the fourth
    $pointer->released($x, $y, 1, 0);
    $late = pointerMail()[0]->clicks;

    $pointer->pressed($x, $y, 1, 0, $at + 10.0);
    $pointer->released($x, $y, 1, 0);
    takeMail(session());
    $pointer->pressed($x + $distance + 2.0, $y, 1, 0, $at + 10.05);   // in time, but too far away
    $pointer->released($x + $distance + 2.0, $y, 1, 0);
    $far = pointerMail()[0]->clicks;

    $pointer->pressed($x, $y, 1, 0, $at + 20.0);
    $pointer->released($x, $y, 1, 0);
    takeMail(session());
    $pointer->pressed($x, $y, 3, 0, $at + 20.05);                     // in time and place, but another button
    $pointer->released($x, $y, 3, 0);

    expect($counted)->toBe([1, 2, 3])
        ->and([$late, $far, pointerMail()[0]->clicks])->toBe([1, 1, 1]);
});

it('posts nothing for a second button pressed and released during a drag', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);
    $pointer->pressed($x, $y, 3, 0);                                 // the right button, while the left is held
    $pointer->moved($x + 8.0, $y, 0);
    $pointer->released($x + 8.0, $y, 3, 0);
    $pointer->released($x + 8.0, $y, 1, 0);

    $mail = pointerMail();
    expect(pointerNames($mail))->toBe(['view.pressed.main.m.board', 'view.dragged.main.m.board', 'view.released.main.m.board'])
        ->and(array_map(fn (PrimitiveMail $one): MouseButton => $one->button, $mail))->toBe([MouseButton::LEFT, MouseButton::LEFT, MouseButton::LEFT]);
});

it('posts a right press, its drag and its release on a canvas with no context menu', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 3, 0);
    $pointer->moved($x + 4.0, $y, 0);
    $pointer->released($x + 4.0, $y, 3, 0);

    $mail = pointerMail();
    expect(pointerNames($mail))->toBe(['view.pressed.main.m.board', 'view.dragged.main.m.board', 'view.released.main.m.board'])
        ->and(array_map(fn (PrimitiveMail $one): MouseButton => $one->button, $mail))->toBe([MouseButton::RIGHT, MouseButton::RIGHT, MouseButton::RIGHT]);
});

it('reads a pointer-emulating touch as the left button', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 15.0, 25.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);                                 // as seen() maps TOUCH_BEGIN with getEmulatingPointer()
    $pointer->released($x, $y, 1, 0);

    $mail = pointerMail();
    expect(pointerNames($mail))->toBe(['view.pressed.main.m.board', 'view.released.main.m.board'])
        ->and($mail[0]->button)->toBe(MouseButton::LEFT);
});

it('keeps posting the drag and the release outside the canvas', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    [, $height] = $board->size();
    [$ox, $oy] = canvasPointIn($window, $board, -12.0, $height + 20.0);   // left of the canvas, below its bottom
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);
    $pointer->moved($ox, $oy, 0);
    $pointer->released($ox, $oy, 1, 0);

    $mail = pointerMail();
    expect(pointerNames($mail))->toBe(['view.pressed.main.m.board', 'view.dragged.main.m.board', 'view.released.main.m.board'])
        ->and($mail[1]->x)->toBeLessThan(0.0)
        ->and($mail[1]->y)->toBeGreaterThan((float) $height)
        ->and([round($mail[2]->x), round($mail[2]->y)])->toBe([round($mail[1]->x), round($mail[1]->y)]);
});

it('drops the press silently when code ends it, and starts fresh on the next press', function (Closure $end): void {
    [$window, $column, $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);
    expect(pointerNames(pointerMail()))->toBe(['view.pressed.main.m.board']);

    $end($board, $column);
    $pointer->moved($x + 6.0, $y, 0);
    $pointer->released($x + 6.0, $y, 1, 0);

    expect(pointerMail())->toBe([]);
})->with([
    'watchPointer(false)' => [fn (object $board): object => $board->watchPointer(false)],
    'the canvas disabled' => [fn (object $board): object => $board->setEnabled(false)],
    'its group disabled' => [fn (object $board, object $column): object => $column->setEnabled(false)],
    'the canvas removed' => [function (object $board): void { $board->remove(); }],
]);

it('starts the next press fresh with no release in between, once the canvas watches again', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);
    $pressed = pointerNames(pointerMail());

    $board->watchPointer(false);                                     // the press ends here, silently
    $pointer->pressed($x, $y, 1, 0);                                 // not watching: still nothing
    $while_off = pointerMail();

    $board->watchPointer();
    $pointer->pressed($x, $y, 1, 0);                                 // the dropped press must not block this one
    $pointer->released($x, $y, 1, 0);

    expect($pressed)->toBe(['view.pressed.main.m.board'])
        ->and($while_off)->toBe([])
        ->and(pointerNames(pointerMail()))->toBe(['view.pressed.main.m.board', 'view.released.main.m.board']);
});

it('posts the next press once the canvas watches again', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 1, 0);
    $pressed = pointerNames(pointerMail());
    $board->watchPointer(false);
    $pointer->released($x, $y, 1, 0);
    $dropped = pointerMail();

    $board->watchPointer();
    $pointer->pressed($x, $y, 1, 0);
    $pointer->released($x, $y, 1, 0);

    expect($pressed)->toBe(['view.pressed.main.m.board'])
        ->and($dropped)->toBe([])                                    // the release of the press code ended
        ->and(pointerNames(pointerMail()))->toBe(['view.pressed.main.m.board', 'view.released.main.m.board']);
});

it('releases the press where it landed when a context menu opens for it, and nothing after', function (): void {
    [$window, , $board] = pointerWindow();
    $board->setContextMenu([['label' => 'Mark']]);
    [$x, $y] = canvasPointIn($window, $board, 24.0, 36.0);
    takeMail(session());
    $pointer = $window->canvasPointer();
    $pointer->pressed($x, $y, 3, GDK_SHIFT_MASK);
    $opened = $window->rightClicks()->at($x, $y);
    $during = pointerMail();
    $pointer->released($x, $y, 3, 0);
    $after = pointerMail();
    $window->rightClicks()->openMenu()?->popover()->popdown();

    expect($opened)->toBeTrue()
        ->and(pointerNames($during))->toBe(['view.pressed.main.m.board', 'view.right-clicked.main.m.board', 'view.released.main.m.board'])
        ->and([round($during[2]->x), round($during[2]->y)])->toBe([24.0, 36.0])
        ->and($during[2]->button)->toBe(MouseButton::RIGHT)
        ->and($during[2]->modifiers)->toEqual(new Modifiers(shift: true))   // the press's, which GTK does not repeat with the menu
        ->and($after)->toBe([]);
});

it('posts nothing when a press is interrupted with none owned', function (): void {
    [$window, , $board] = pointerWindow();
    canvasPointIn($window, $board, 10.0, 10.0);
    takeMail(session());
    $window->canvasPointer()->interrupt();

    expect(pointerMail())->toBe([]);
});

it('routes the pointer\'s events, and nothing from a touch that does not emulate the pointer', function (): void {
    expect(GTKCanvasPointer::route(GdkEventType::BUTTON_PRESS, true))->toBe('pressed')
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_BEGIN, true))->toBe('pressed')
        ->and(GTKCanvasPointer::route(GdkEventType::MOTION_NOTIFY, true))->toBe('moved')
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_UPDATE, true))->toBe('moved')
        ->and(GTKCanvasPointer::route(GdkEventType::BUTTON_RELEASE, true))->toBe('released')
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_END, true))->toBe('released')
        ->and(GTKCanvasPointer::route(GdkEventType::GRAB_BROKEN, true))->toBe('interrupt')
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_CANCEL, true))->toBe('interrupt')
        // Crossing into the window is a move (hover); out of it, the canvas under the pointer is left.
        ->and(GTKCanvasPointer::route(GdkEventType::ENTER_NOTIFY, true))->toBe('moved')
        ->and(GTKCanvasPointer::route(GdkEventType::LEAVE_NOTIFY, true))->toBe('left')
        ->and(GTKCanvasPointer::route(GdkEventType::PROXIMITY_IN, true))->toBeNull()
        // A second finger: its moves, lift and cancel are not the press's.
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_BEGIN, false))->toBeNull()
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_UPDATE, false))->toBeNull()
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_END, false))->toBeNull()
        ->and(GTKCanvasPointer::route(GdkEventType::TOUCH_CANCEL, false))->toBeNull();
});

it('watches the window in capture phase', function (): void {
    [$window] = pointerWindow();

    expect($window->canvasPointer()->events()->getPropagationPhase())->toBe(GtkPropagationPhase::CAPTURE)
        ->and($window->canvasPointer()->events()->getWidget())->toBe($window->native());
});

it('posts the wheel over a watching canvas and keeps the event, and passes it on otherwise', function (): void {
    [$window, , $board] = pointerWindow();
    canvasPointIn($window, $board, 10.0, 10.0);
    takeMail(session());

    $kept = g_signal_emit_by_name($board->scrollController(), 'scroll', 0.0, 1.0);
    $mail = pointerMail();

    $board->watchPointer(false);
    $passed = g_signal_emit_by_name($board->scrollController(), 'scroll', 0.0, 1.0);

    expect($kept)->toBeTrue()
        ->and(pointerNames($mail))->toBe(['view.wheeled.main.m.board'])
        ->and([$mail[0]->dx, $mail[0]->dy, $mail[0]->precise])->toBe([0.0, 1.0, false])
        ->and($mail[0]->modifiers)->toEqual(new Modifiers())
        ->and([$mail[0]->window, $mail[0]->path, $mail[0]->uuid])->toBe(['main', 'm.board', $board->uuid()])
        ->and($passed)->toBeFalse()
        ->and(pointerMail())->toBe([]);
});

it('posts a precise wheel with its modifiers, and nothing from a disabled or removed canvas', function (): void {
    [$window, $column, $board] = pointerWindow();
    canvasPointIn($window, $board, 10.0, 10.0);
    takeMail(session());

    $precise = $board->wheeled(0.0, 3.0, true, GDK_SHIFT_MASK);
    $mail = pointerMail();

    $column->setEnabled(false);
    $grouped = $board->wheeled(0.0, 1.0, false, 0);
    $column->setEnabled(true);
    $board->setEnabled(false);
    $own = $board->wheeled(0.0, 1.0, false, 0);
    $board->setEnabled(true);
    $dropped = pointerMail();
    $board->remove();
    $gone = $board->wheeled(0.0, 1.0, false, 0);

    expect($precise)->toBeTrue()
        ->and([$mail[0]->dx, $mail[0]->dy, $mail[0]->precise])->toBe([0.0, 3.0, true])
        ->and($mail[0]->modifiers)->toEqual(new Modifiers(shift: true))
        ->and([$grouped, $own, $gone])->toBe([false, false, false])
        ->and($dropped)->toBe([]);
});

it('keeps the wheel from the scroll view a watching canvas sits in', function (): void {
    $window = driver()->open('main', 300, 240);
    $column = $window->column('m')->scrollView('s')->column('inner');
    $board = $column->canvas('board')->minSize(200, 120);
    $column->label('tall', str_repeat("line\n", 80));
    $board->watchPointer();
    canvasPointIn($window, $board, 10.0, 10.0);
    takeMail(session());

    $kept = g_signal_emit_by_name($board->scrollController(), 'scroll', 0.0, 1.0);
    $mail = pointerMail();
    $board->watchPointer(false);
    $passed = g_signal_emit_by_name($board->scrollController(), 'scroll', 0.0, 1.0);

    expect([$kept, $passed])->toBe([true, false])
        ->and(pointerNames($mail))->toBe(['view.wheeled.main.m.s.inner.board'])
        ->and(pointerMail())->toBe([]);
});

it('gives a wheel its gesture phase: none with no begin, began, changed, then ended', function (): void {
    [$window, , $board] = pointerWindow();
    $scroll = $board->scrollController();

    g_signal_emit_by_name($scroll, 'scroll', 0.0, 1.0);
    g_signal_emit_by_name($scroll, 'scroll-begin');
    g_signal_emit_by_name($scroll, 'scroll', 0.0, 2.0);
    g_signal_emit_by_name($scroll, 'scroll', 0.0, 3.0);
    g_signal_emit_by_name($scroll, 'scroll-end');
    $mail = pointerMail();

    expect(array_map(fn (CanvasWheeled $wheel): string => $wheel->phase->value, $mail))->toBe(['none', 'began', 'changed', 'ended'])
        ->and([$mail[3]->dx, $mail[3]->dy])->toBe([0.0, 0.0])
        ->and(($scroll->getFlags() & GTK_EVENT_CONTROLLER_SCROLL_KINETIC) !== 0)->toBeTrue();
    $window->close();
});

it('coasts after a flick: momentum with decaying deltas, then one momentum end', function (): void {
    [$window, , $board] = pointerWindow();
    g_signal_emit_by_name($board->scrollController(), 'decelerate', 1.0, 0.0);
    $coast = $board->coast();

    for ($t = 16.0; $t <= 3000.0; $t += 16.0) {
        $coast->tick($coast->started() + $t);
    }
    $mail = pointerMail();
    $momentum = array_values(array_filter($mail, fn (CanvasWheeled $wheel): bool => $wheel->phase === Surface\Contracts\Windows\Mail\View\ScrollPhase::MOMENTUM));
    $deltas = array_map(fn (CanvasWheeled $wheel): float => $wheel->dx, $momentum);

    expect(count($momentum))->toBeGreaterThan(10)
        ->and(array_filter(array_keys($deltas), fn (int $i): bool => $i > 0 && $deltas[$i] >= $deltas[$i - 1]))->toBe([])
        ->and(array_filter($deltas, fn (float $delta): bool => $delta <= 0.0))->toBe([])
        ->and(end($mail)->phase)->toBe(Surface\Contracts\Windows\Mail\View\ScrollPhase::MOMENTUM_ENDED)
        ->and(count(array_filter($mail, fn (CanvasWheeled $wheel): bool => $wheel->phase === Surface\Contracts\Windows\Mail\View\ScrollPhase::MOMENTUM_ENDED)))->toBe(1)
        ->and($board->coast())->toBeNull();
    $window->close();
});

it('ends a coast with momentum end for a press or a new gesture, and with no mail for removal', function (): void {
    [$window, , $board] = pointerWindow();
    [$x, $y] = canvasPointIn($window, $board, 12.0, 34.0);
    $scroll = $board->scrollController();
    $phases = fn (): array => array_map(fn (object $mail): string => $mail instanceof CanvasWheeled ? $mail->phase->value : (new ReflectionClass($mail))->getShortName(), pointerMail());

    g_signal_emit_by_name($scroll, 'decelerate', 0.0, 2.0);
    $window->canvasPointer()->pressed($x, $y, 1, 0);
    $window->canvasPointer()->released($x, $y, 1, 0);
    expect($phases())->toBe(['momentum-ended', 'CanvasPressed', 'CanvasReleased']);

    g_signal_emit_by_name($scroll, 'decelerate', 0.0, 2.0);
    g_signal_emit_by_name($scroll, 'scroll-begin');
    expect($phases())->toBe(['momentum-ended']);

    g_signal_emit_by_name($scroll, 'decelerate', 0.0, 2.0);
    $coast = $board->coast();
    $board->remove();
    $coast->tick($coast->started() + 16.0);
    expect($phases())->toBe([]);
    $window->close();
});

it('sizes its pixels by the surface scale', function (): void {
    [$window, , $board] = pointerWindow();
    canvasPointIn($window, $board, 0.0, 0.0);
    $scale = $window->native()->getSurface()->getScale();
    [$width, $height] = $board->size();

    expect($board->pixelSize())->toBe([(int) round($width * $scale), (int) round($height * $scale)]);
    $window->close();
});
