<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Primitives\TKTransition;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

it('draws a widget at the opacity it is given', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('a', 'A');
    $window->present();

    $label->setOpacity(0.4);

    expect($label->native()->getOpacity())->toEqualWithDelta(0.4, 1 / 255)
        ->and($label->opacity())->toBe(0.4);
});

it('shows the widget at once when it appears, from tk-away, and lands at rest', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m')->column('card');
    $card->label('text', 'Card');
    $window->present();
    pumpFor(0.2);

    $card->appear(TKTransition::SLIDE_UP, 0.2)->hide();
    $card->show();

    expect($card->native()->getVisible())->toBeTrue()
        ->and($card->native()->hasCssClass('tk-away'))->toBeTrue();

    pumpFor(0.5);

    expect($card->native()->hasCssClass('tk-away'))->toBeFalse()
        ->and($card->native()->hasCssClass('tk-moving'))->toBeFalse()
        ->and($card->native()->getVisible())->toBeTrue();
});

it('holds tk-away until a widget shown before its window is on screen is mapped, then lands at rest', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m')->column('card');
    $card->label('text', 'Card');
    $card->appear(TKTransition::FADE, 0.2)->hide();

    $card->show();

    expect($card->native()->getMapped())->toBeFalse()
        ->and($card->native()->hasCssClass('tk-away'))->toBeTrue();

    pumpFor(0.3);

    expect($card->native()->hasCssClass('tk-away'))->toBeTrue();

    $window->present();
    pumpUntil(fn (): bool => $card->native()->getMapped(), 3.0);

    expect($card->native()->hasCssClass('tk-away'))->toBeTrue();

    pumpFor(0.6);

    expect($card->native()->hasCssClass('tk-away'))->toBeFalse()
        ->and($card->native()->hasCssClass('tk-moving'))->toBeFalse();
});

/**
 * A stylesheet that also moves colour on the driver's tk-moving / tk-away classes: getColor() reads the
 * colour GTK's style is at now, so a value between red and blue means the driver's switch animates.
 */
function colourProbe(string $styleClass): GtkCssProvider
{
    $provider = GtkCssProvider::new();
    $provider->loadFromString(
        ".{$styleClass} { color: rgb(255,0,0); } "
        .".{$styleClass}.tk-moving { transition: color 1s linear, opacity 1s linear, transform 1s linear; } "
        .".{$styleClass}.tk-away { color: rgb(0,0,255); }"
    );
    gtk_style_context_add_provider_for_display(GdkDisplay::getDefault(), $provider, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION + 1);

    return $provider;
}

it('animates the class switch when shown before its window is on screen', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m')->column('card');
    $card->label('text', 'Card');
    $probe = colourProbe($card->styleClass());
    $card->appear(TKTransition::FADE, 1.0)->hide();

    $card->show();
    $window->present();
    pumpUntil(fn (): bool => $card->native()->getMapped(), 3.0);
    pumpFor(0.55);
    [$red, , $blue] = $card->native()->getColor();

    gtk_style_context_remove_provider_for_display(GdkDisplay::getDefault(), $probe);

    expect($red)->toBeGreaterThan(0.1)->toBeLessThan(0.9)
        ->and($blue)->toBeGreaterThan(0.1)->toBeLessThan(0.9);
});

it('keeps the widget up while it disappears, then hides it at rest', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('a', 'A');
    $window->present();
    pumpFor(0.2);

    $label->disappear(TKTransition::FADE, 0.2)->hide();

    expect($label->isVisible())->toBeFalse()
        ->and($label->native()->getVisible())->toBeTrue()
        ->and($label->native()->hasCssClass('tk-away'))->toBeTrue();

    pumpFor(0.5);

    expect($label->native()->getVisible())->toBeFalse()
        ->and($label->native()->hasCssClass('tk-away'))->toBeFalse();
});

it('stays shown when shown again before its disappearance lands', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('a', 'A');
    $window->present();
    pumpFor(0.2);

    $label->disappear(TKTransition::FADE, 0.3)->appear(TKTransition::FADE, 0.1);
    $label->hide();
    pumpFor(0.05);
    $label->show();
    pumpFor(0.6);

    expect($label->isVisible())->toBeTrue()
        ->and($label->native()->getVisible())->toBeTrue()
        ->and($label->native()->hasCssClass('tk-away'))->toBeFalse();
});
