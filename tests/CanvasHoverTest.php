<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasEntered;
use Surface\Contracts\Windows\Mail\View\CanvasLeft;
use Surface\Contracts\Windows\Mail\View\CanvasMoved;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The hover and press mail posted since the last read, latest-wins mail flushed first, by class. */
function hoverKinds(): array
{
    session()->flushLatest();
    $mail = array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasEntered || $mail instanceof CanvasMoved
        || $mail instanceof CanvasLeft || $mail instanceof CanvasPressed || $mail instanceof CanvasDragged || $mail instanceof CanvasReleased);

    return array_values(array_map(fn (object $mail): string => (new ReflectionClass($mail))->getShortName(), $mail));
}

/** A canvas above a button, watching unless told not to, and window points inside it and on the button. */
function hoverWindow(bool $watch = true): array
{
    $window = driver()->open('main', 300, 260);
    $column = $window->column('m');
    $board = $column->canvas('board')->minSize(200, 120);
    $beside = $column->button('beside', 'Beside');
    if ($watch) {
        $board->watchPointer();
    }
    $inside = canvasPointIn($window, $board, 20.0, 30.0);
    $further = canvasPointIn($window, $board, 25.0, 30.0);
    $outside = canvasPointIn($window, $beside, 5.0, 5.0);
    pumpFor(0.1);
    hoverKinds();

    return [$window, $board, $inside, $further, $outside];
}

it('posts Entered, Moved and Left as the pointer crosses a watching canvas', function (): void {
    [$window, $board, $inside, $further, $outside] = hoverWindow();
    $pointer = $window->canvasPointer();

    $pointer->moved(...$inside, ...[0]);
    session()->flushLatest();
    $first = array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof CanvasEntered || $mail instanceof CanvasMoved));
    $pointer->moved(...$further, ...[0]);
    $pointer->moved(...$outside, ...[0]);

    expect(array_map(fn (object $mail): string => (new ReflectionClass($mail))->getShortName(), $first))->toBe(['CanvasEntered', 'CanvasMoved'])
        ->and($first[1])->toEqual(new CanvasMoved('main', 'm.board', $board->uuid(), 20.0, 30.0, new Modifiers()))
        ->and(hoverKinds())->toBe(['CanvasMoved', 'CanvasLeft']);
    $window->close();
});

it('posts Left when the pointer leaves the window', function (): void {
    [$window, , $inside] = hoverWindow();
    $pointer = $window->canvasPointer();
    $pointer->moved(...$inside, ...[0]);
    hoverKinds();

    $pointer->leftWindow();

    expect(hoverKinds())->toBe(['CanvasLeft']);
    $window->close();
});

it('posts nothing from a canvas that is not watching', function (): void {
    [$window, , $inside, , $outside] = hoverWindow(watch: false);
    $pointer = $window->canvasPointer();

    $pointer->moved(...$inside, ...[0]);
    $pointer->moved(...$outside, ...[0]);

    expect(hoverKinds())->toBe([]);
    $window->close();
});

it('posts no hover during an owned press, and Left after Released for a release outside', function (): void {
    [$window, , $inside, , $outside] = hoverWindow();
    $pointer = $window->canvasPointer();
    $pointer->moved(...$inside, ...[0]);
    hoverKinds();

    $pointer->pressed(...[...$inside, 1, 0]);
    $pointer->moved(...$outside, ...[GDK_BUTTON1_MASK]);
    $pointer->leftWindow();
    $pointer->released(...[...$outside, 1, 0]);

    expect(hoverKinds())->toBe(['CanvasPressed', 'CanvasDragged', 'CanvasReleased', 'CanvasLeft']);
    $window->close();
});

it('posts nothing for a removed canvas', function (): void {
    [$window, $board, $inside, , $outside] = hoverWindow();
    $pointer = $window->canvasPointer();
    $pointer->moved(...$inside, ...[0]);
    hoverKinds();

    $board->remove();
    $pointer->moved(...$outside, ...[0]);

    expect(hoverKinds())->toBe([]);
    $window->close();
});
