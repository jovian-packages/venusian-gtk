<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKCanvasClicks;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return list<CanvasClicked> the canvas clicks posted since the last read */
function canvasClicks(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasClicked));
}

/** A canvas filling a column, and a button beside it. */
function boardWindow(): array
{
    $window = driver()->open('main', 300, 240);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    $beside = $column->button('beside', 'Beside');

    return [$window, $column, $board, $beside];
}

it('posts view.clicked for a press and release on a canvas, at the press, from its top-left', function (): void {
    [$window, , $board] = boardWindow();
    [$x, $y] = canvasPointIn($window, $board, 12.0, 34.0);
    takeMail(session());
    $clicks = $window->canvasClicks();
    $clicks->pressed($x, $y);
    $clicks->moved($x + 1.0, $y);
    $clicks->released($x + 1.0, $y);

    $mail = canvasClicks();
    expect($mail)->toHaveCount(1)
        ->and([$mail[0]->name(), $mail[0]->uuid])->toBe(['view.clicked.main.m.board', $board->uuid()])
        ->and([round($mail[0]->x), round($mail[0]->y)])->toBe([12.0, 34.0]);
});

it('posts nothing for a drag, a release beside the canvas, a press beside it, or a disabled canvas', function (): void {
    [$window, $column, $board, $beside] = boardWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    [$bx, $by] = canvasPointIn($window, $beside, 4.0, 4.0);
    $drag = g_object_get_property(GtkSettings::getDefault(), 'gtk-dnd-drag-threshold');
    takeMail(session());
    $clicks = $window->canvasClicks();

    $clicks->pressed($x, $y);
    $clicks->moved($x + $drag + 2.0, $y);
    $clicks->released($x, $y);
    $clicks->pressed($x, $y);
    $clicks->released($bx, $by);
    $clicks->pressed($bx, $by);
    $clicks->released($x, $y);
    $column->disable();
    $clicks->pressed($x, $y);
    $clicks->released($x, $y);

    expect(canvasClicks())->toBe([]);
});

it('posts nothing for a press held past GTK\'s long-press time where a hold is a right click', function (): void {
    [$window, , $board] = boardWindow();
    [$x, $y] = canvasPointIn($window, $board, 20.0, 20.0);
    $hold = g_object_get_property(GtkSettings::getDefault(), 'gtk-long-press-time') / 1000;
    $clicks = $window->canvasClicks();
    $clicks->holdCancels(true);
    takeMail(session());
    // Pressed a hold and a fifth ago: no waiting, so no real pointer motion can land in between.
    $clicks->pressed($x, $y, microtime(true) - $hold * 1.2);
    $clicks->released($x, $y);
    $held = canvasClicks();
    $clicks->holdCancels(false);
    $clicks->pressed($x, $y, microtime(true) - $hold * 1.2);
    $clicks->released($x, $y);

    expect($held)->toBe([])
        ->and(array_map(fn (CanvasClicked $mail): string => $mail->name(), canvasClicks()))->toBe(['view.clicked.main.m.board']);
});

it('reads a primary press without Control on macOS as a click press, and watches the window in capture phase', function (): void {
    [$window] = boardWindow();

    expect([
        GTKCanvasClicks::primary(1, 0, macos: true),
        GTKCanvasClicks::primary(1, GDK_CONTROL_MASK, macos: true),
        GTKCanvasClicks::primary(1, GDK_CONTROL_MASK, macos: false),
        GTKCanvasClicks::primary(3, 0, macos: false),
    ])->toBe([true, false, true, false])
        ->and($window->canvasClicks()->events()->getPropagationPhase())->toBe(GtkPropagationPhase::CAPTURE)
        ->and($window->canvasClicks()->events()->getWidget())->toBe($window->native());
});
