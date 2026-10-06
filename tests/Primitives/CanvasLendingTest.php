<?php

declare(strict_types=1);

use Jovian\Engines\OpenGL\OpenGLDevice;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\WindowException;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Surface\NutsAndBolts\Color;

/*
 * The canvas lends its GtkGLArea's context to a GL engine and copies the
 * engine's frames in its render callback. These tests draw real frames
 * through venusian-opengl into a window on screen.
 */

/** A borrower that counts what it was asked to present into; its framebuffer is a plain dirty one. */
final class ContextBorrower implements SurfaceBorrower
{
    /** @var list<LentSurface> */
    public array $presented = [];

    public Framebuffer $frame;

    public function __construct()
    {
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return [];
    }

    public function presentInto(LentSurface $surface): bool
    {
        $this->presented[] = $surface;

        return true;
    }
}

beforeEach(function (): void {
    if (! extension_loaded('opengl')) {
        $this->markTestSkipped('needs ext-opengl');
    }
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

afterEach(function (): void {
    if (! extension_loaded('opengl')) {
        return;
    }
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

/** @return array{\Jovian\Toolkits\GTK\Windows\GTKWindow, \Jovian\Toolkits\GTK\Primitives\GTKCanvas} a shown window with a filling canvas */
function glCanvas(int $width = 300, int $height = 200): array
{
    $window = driver()->open('main', $width, $height);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpUntil(fn (): bool => $canvas->size()[1] > 0, 3.0);

    return [$window, $canvas];
}

it('lends a GL context when ext-opengl is loaded', function (): void {
    [$window, $canvas] = glCanvas();

    expect($canvas->surfaces())->toBe([SurfaceKind::GL_CONTEXT]);

    $surface = $canvas->lend(SurfaceKind::GL_CONTEXT, new ContextBorrower);
    pumpFor(0.1);

    expect($surface->kind)->toBe(SurfaceKind::GL_CONTEXT)
        ->and($surface->handle('context'))->toBeGreaterThan(0)
        ->and($canvas->glArea())->toBeInstanceOf(GtkGLArea::class)
        ->and($canvas->glArea()->getContext()?->pointer())->not->toBeNull()
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
    if (PHP_OS_FAMILY !== 'Darwin') {
        expect($surface->handle('display'))->toBeGreaterThan(0);
    }
});

it('refuses to lend before the window is shown', function (): void {
    $window = driver()->open('main', 300, 200);
    $canvas = $window->column('m')->canvas('view')->fill();

    $canvas->lend(SurfaceKind::GL_CONTEXT, new ContextBorrower);
})->throws(WindowException::class, 'has no GL context yet: present the window first');

it('lends nothing a device it cannot host, naming what it can', function (): void {
    [$window, $canvas] = glCanvas();

    $canvas->lend(SurfaceKind::METAL_LAYER, new ContextBorrower);
})->throws(WindowException::class, 'lends no metal-layer surface (it lends: gl-context).');

it('shows opengl frames in the window with no pixel through PHP', function (): void {
    [$window, $canvas] = glCanvas(320, 240);

    $engine = new GpuRenderingEngine(OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(16, 24, 32));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(255, 128, 0));
        });
        $canvas->present();
        pumpFor(1 / 60);
    }

    expect($canvas->lent()?->size())->toBe($canvas->pixelSize())
        ->and($engine->framebuffer()->damage())->toBe([])
        ->and($canvas->picture()->getPaintable())->toBeNull();
    $engine->release();
});

it('repaints the last frame when the toolkit asks', function (): void {
    [$window, $canvas] = glCanvas();
    $engine = new GpuRenderingEngine(OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 128, 255)));
    $canvas->present();
    pumpFor(0.1);
    $renders = $canvas->renders();

    $canvas->glArea()->queueRender();
    pumpUntil(fn (): bool => $canvas->renders() > $renders, 1.0);

    expect($canvas->renders())->toBeGreaterThan($renders);
    $engine->release();
});

it('re-targets the engine when the window is resized', function (): void {
    [$window, $canvas] = glCanvas();
    $engine = new GpuRenderingEngine(OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    $before = $engine->framebuffer();

    $window->native()->setDefaultSize(400, 300);
    pumpUntil(fn (): bool => $canvas->pixelSize()[0] !== $before->viewportWidth(), 2.0);
    // Each frame re-targets to the size the canvas has then; a window that settles (or changes display) late is caught by the next.
    pumpUntil(function () use ($engine, $canvas): bool {
        $drawnAt = $canvas->pixelSize();
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
        $canvas->present();
        pumpFor(0.05);

        return [$engine->width(), $engine->height()] === $drawnAt;
    }, 2.0);
    // The display a window sits on can change under a test (two monitors at different scales):
    // what holds is that each frame re-targets to the size the canvas has when it is drawn.
    $drawnAt = $canvas->pixelSize();
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));

    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($drawnAt)
        ->and($canvas->lent()?->size())->toBe($canvas->pixelSize());
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    [$window, $canvas] = glCanvas();
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    $surface = $canvas->lend(SurfaceKind::GL_CONTEXT, new ContextBorrower);
    pumpFor(0.1);

    $canvas->reclaim();
    pumpFor(0.1);
    $own->setSegment(10, 10, 20, 20, 0x000000FF);
    $canvas->present();

    expect($surface->released())->toBeTrue()
        ->and($canvas->glArea())->toBeNull()
        ->and($canvas->picture()->getPaintable())->toBeInstanceOf(GdkTexture::class)
        ->and($canvas->surfaces())->toBe([SurfaceKind::GL_CONTEXT]);
});

it('reclaims before it is removed', function (): void {
    [$window, $canvas] = glCanvas();
    $surface = $canvas->lend(SurfaceKind::GL_CONTEXT, new ContextBorrower);

    $canvas->remove();

    expect($surface->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
});

it('leaves no GL objects behind across lend and release cycles', function (): void {
    [$window, $canvas] = glCanvas();
    $programs = [];
    for ($i = 0; $i < 50; $i++) {
        $engine = new GpuRenderingEngine(OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, $i)));
        $canvas->present();
        pumpFor(0.01);
        $programs[] = $engine->device()->program();
        $engine->release();
    }

    // A last engine's context shares objects with every context before it: none of their programs may still exist.
    $engine = new GpuRenderingEngine($probe = OpenGLDevice::adopting(), ...$canvas->pixelSize(), output: $canvas);
    $live = $probe->program();
    $probe->current();
    $alive = [];
    foreach (array_unique($programs) as $program) {
        glGetError();
        glUseProgram($program);
        if ($program !== $live && glGetError() === GL_NO_ERROR) {
            $alive[] = $program;
        }
    }
    glUseProgram(0);
    $engine->release();

    expect($alive)->toBe([]);
});

it('puts back the context that was current when it lends', function (): void {
    [$window, $canvas] = glCanvas();
    $own = new OpenGLDevice;
    $own->current();
    $before = PHP_OS_FAMILY === 'Darwin' ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer();

    $canvas->lend(SurfaceKind::GL_CONTEXT, new ContextBorrower);

    expect(PHP_OS_FAMILY === 'Darwin' ? CGLGetCurrentContext()?->pointer() : eglGetCurrentContext()?->pointer())->toBe($before)
        ->and($before)->not->toBeNull();
    $own->release();
});

