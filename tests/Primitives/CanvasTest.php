<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKCanvas;
use Surface\Contracts\Windows\WindowException;

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

it('lays a canvas out like any view and measures it in device pixels', function (): void {
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $main->label('title', 'Canvas');
    $canvas = $main->canvas('view')->fill();
    $window->present();
    pumpUntil(fn (): bool => $canvas->size()[1] > 0, 3.0);

    [$width, $height] = $canvas->size();
    $scale = $canvas->native()->getScaleFactor();

    expect($canvas)->toBeInstanceOf(GTKCanvas::class)
        ->and($canvas->native())->toBeInstanceOf(\GtkBox::class)
        ->and($canvas->picture())->toBeInstanceOf(\GtkPicture::class)
        ->and($width)->toBeGreaterThanOrEqual(300)
        ->and($height)->toBeGreaterThan(100)
        ->and($canvas->pixelSize())->toBe([$width * $scale, $height * $scale])
        ->and($canvas->picture()->getPaintable())->toBeNull()
        ->and($canvas->picture()->getContentFit())->toBe(\GtkContentFit::FILL)
        ->and($canvas->picture()->getCanShrink())->toBeTrue();
});

it('shows its framebuffer as the picture\'s texture, only when something was drawn', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpUntil(fn (): bool => $canvas->size()[1] > 0, 3.0);

    $buffer = $canvas->framebuffer('dirty');
    $buffer->fill(0xFF6600FF);
    $canvas->present();
    pumpFor(0.05);

    $shown = $canvas->picture()->getPaintable();
    expect($shown)->toBeInstanceOf(\GdkMemoryTexture::class)
        ->and([$shown->getWidth(), $shown->getHeight()])->toBe($canvas->pixelSize());

    $canvas->present();                                             // nothing drawn since: the same texture stays up
    expect($canvas->picture()->getPaintable())->toBe($shown);

    $buffer->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();
    expect($canvas->picture()->getPaintable())->toBeInstanceOf(\GdkMemoryTexture::class)->not->toBe($shown);
});

it('stretches a framebuffer of another size over the view, without the view taking its size', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpUntil(fn (): bool => $canvas->size()[1] > 0, 3.0);
    $laid_out = $canvas->size();

    $canvas->framebuffer('full', 32, 24)->fill(0x3366CCFF);
    $canvas->present();
    pumpFor(0.1);
    expect($canvas->picture()->getPaintable()->getWidth())->toBe(32)
        ->and($canvas->size())->toBe($laid_out);

    $canvas->framebuffer('full', 1200, 900)->fill(0x3366CCFF);       // larger than the window: the layout still decides
    $canvas->present();
    pumpFor(0.1);
    expect($canvas->picture()->getPaintable()->getWidth())->toBe(1200)
        ->and($canvas->size())->toBe($laid_out);
});

it('takes a framebuffer before the window is shown when given a size, and goes with its view', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $canvas->framebuffer('ring', 16, 8)->fill(0xFFFFFFFF);
    $canvas->boundFramebuffer()->present();
    $canvas->present();

    expect($canvas->picture()->getPaintable()->getWidth())->toBe(16);

    $canvas->remove();
    expect(fn () => $canvas->present())->toThrow(WindowException::class, 'was removed')
        ->and($window->view('m.view'))->toBeNull();
});

it('pipes an ext-fb framebuffer to the picture by address, updating its texture', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpUntil(fn (): bool => $canvas->size()[1] > 0, 3.0);

    $buffer = $canvas->framebuffer('dirty', driver: 'extended');
    $buffer->fill(0xFF6600FF);
    $canvas->present();
    pumpFor(0.05);

    $shown = $canvas->picture()->getPaintable();
    expect($buffer->pointer())->not->toBe(0)
        ->and($shown)->toBeInstanceOf(\GdkMemoryTexture::class)
        ->and([$shown->getWidth(), $shown->getHeight()])->toBe([$buffer->viewportWidth(), $buffer->viewportHeight()]);

    $buffer->setPixel(5, 5, 0x000000FF);
    $canvas->present();
    pumpFor(0.05);

    $next = $canvas->picture()->getPaintable();
    expect($next)->toBeInstanceOf(\GdkMemoryTexture::class)->not->toBe($shown)
        ->and([$next->getWidth(), $next->getHeight()])->toBe([$buffer->viewportWidth(), $buffer->viewportHeight()]);
})->skip(! class_exists(FbBuffer::class), 'ext-fb is not loaded in this PHP.');
