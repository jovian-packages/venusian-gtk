<?php

declare(strict_types=1);

use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Windows\Primitives\ImagePosition;
use Surface\Framebuffers\Native\NativeFullFramebuffer;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
});

dataset('image buttons', [
    'push' => ['button'],
    'toggle' => ['toggle'],
]);

/** The image's frame and the label beside it (null for ONLY), once laid out. */
function imageParts(GtkButton $native): array
{
    pumpUntil(fn (): bool => $native->getChild()->getFirstChild()->getWidth() > 0);
    $frame = $native->getChild()->getFirstChild();

    return [$frame, $frame->getNextSibling()];
}

it('shows a framebuffer image at its size in points above, beside or instead of the label', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Open') : $column->toggleButton('b', 'Open');
    $image = (new NativeFullFramebuffer(FormatSpec::rgba8(), 32, 32))->fill(0xff4020ff);
    $window->present();

    $button->setImage($image, ImagePosition::ABOVE, 2.0);
    [$frame, $label] = imageParts($button->native());
    [$lx, $ly] = widgetPoint($label, $frame, 0, 0);
    expect($button->native()->getChild())->toBeInstanceOf(GtkBox::class)
        ->and($frame->getSizeRequest())->toBe([16, 16])
        ->and($frame->getWidth())->toBe(16)
        ->and($frame->getHeight())->toBe(16)
        ->and($label)->toBeInstanceOf(GtkLabel::class)
        ->and($label->getText())->toBe('Open')
        ->and($ly)->toBeGreaterThanOrEqual(16.0);

    $button->setImage($image);
    [$frame, $label] = imageParts($button->native());
    [$lx, $ly] = widgetPoint($label, $frame, 0, 0);
    expect($frame->getSizeRequest())->toBe([32, 32])
        ->and($frame->getWidth())->toBe(32)
        ->and($lx)->toBeGreaterThanOrEqual(32.0);

    $button->setImage($image, ImagePosition::ONLY);
    [$frame, $label] = imageParts($button->native());
    expect($label)->toBeNull();

    $button->setLabel('Close');
    $button->setImage($image);
    expect(imageParts($button->native())[1]->getText())->toBe('Close');

    $button->setImage(null);
    expect($button->native()->getChild())->toBeInstanceOf(GtkLabel::class)
        ->and($button->native()->getLabel())->toBe('Close');
    $window->close();
})->with('image buttons');

it('draws without a frame when not bordered', function (string $kind): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $button = $kind === 'button' ? $column->button('b', 'Open') : $column->toggleButton('b', 'Open');

    $button->setBordered(false);
    expect($button->native()->getHasFrame())->toBeFalse();

    $button->setBordered(true);
    expect($button->native()->getHasFrame())->toBeTrue();
    $window->close();
})->with('image buttons');
