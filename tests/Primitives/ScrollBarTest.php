<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKScrollBar;
use Surface\Contracts\Windows\Mail\View\ScrollChanged;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The scroll mail posted since the last read, latest-wins mail flushed first. */
function scrollMail(): array
{
    session()->flushLatest();

    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof ScrollChanged));
}

function scrollBar(): array
{
    $window = driver()->open('main', 200, 300);
    $bar = $window->column('m')->scrollBar('bar');
    $bar->setRange(1000, 100);

    return [$window, $bar];
}

it('is a scroll bar over an adjustment holding the range, page and value', function (): void {
    [$window, $bar] = scrollBar();

    $bar->setStep(10)->setValue(450);
    $adjustment = $bar->native()->getAdjustment();

    expect($bar)->toBeInstanceOf(GTKScrollBar::class)
        ->and($bar->native())->toBeInstanceOf(\GtkScrollbar::class)
        ->and([$adjustment->getLower(), $adjustment->getUpper(), $adjustment->getPageSize(), $adjustment->getValue()])->toBe([0.0, 1000.0, 100.0, 450.0])
        ->and(scrollMail())->toBe([]);

    $bar->setRange(50, 100);
    expect([$adjustment->getUpper(), $adjustment->getPageSize(), $adjustment->getValue()])->toBe([100.0, 100.0, 0.0]);
    $window->close();
});

it('pulls the value in when the range shrinks under it, and posts nothing', function (): void {
    [$window, $bar] = scrollBar();
    $bar->setValue(900);

    $bar->setRange(500, 100);

    expect($bar->value())->toBe(400)
        ->and($bar->native()->getAdjustment()->getValue())->toBe(400.0)
        ->and(scrollMail())->toBe([]);
    $window->close();
});

it('posts the user\'s move, rounded', function (): void {
    [$window, $bar] = scrollBar();

    // A drag, a track click or GTK's own keys write the adjustment outside the driver's guard.
    $bar->native()->getAdjustment()->setValue(312.6);

    expect(scrollMail())->toEqual([new ScrollChanged('main', 'm.bar', $bar->uuid(), 313)])
        ->and($bar->value())->toBe(313);
    $window->close();
});

it('moves step per wheel line over it, a precise wheel one line per 10 points', function (): void {
    [$window, $bar] = scrollBar();
    $bar->setStep(10);

    expect($bar->wheeled(3.0, false))->toBeTrue();
    expect(array_map(fn (ScrollChanged $mail): int => $mail->value, scrollMail()))->toBe([30]);

    $bar->wheeled(25.0, true);
    expect($bar->value())->toBe(50);
    $bar->wheeled(5.0, true);
    expect($bar->value())->toBe(60)
        ->and(scrollMail()[0]->value)->toBe(60);
    $window->close();
});
