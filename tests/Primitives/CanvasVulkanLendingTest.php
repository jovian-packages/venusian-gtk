<?php

declare(strict_types=1);

use Jovian\Engines\Vulkan\VulkanDevice;
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
 * The canvas lends a dmabuf surface: the vulkan engine exports each frame as a
 * dmabuf and the canvas shows it as a GdkDmabufTexture. These tests draw real
 * frames through venusian-vulkan into a window on screen (Linux).
 */

/** A borrower with a real device; its framebuffer is a plain dirty one. */
final class GtkVulkanBorrower implements SurfaceBorrower
{
    public VulkanDevice $device;

    public Framebuffer $frame;

    public function __construct()
    {
        $this->device = new VulkanDevice;
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return $this->device->handles();
    }

    public function presentInto(LentSurface $surface): bool
    {
        return true;
    }
}

beforeEach(function (): void {
    if (gtkDmabufKinds() === [] || ! extension_loaded('vulkan') || ! in_array(SurfaceKind::DMABUF, (new VulkanDevice)->surfaces(), true)) {
        $this->markTestSkipped('needs Linux, GdkDmabufTextureBuilder, ext-vulkan and a device that exports dmabufs');
    }
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

afterEach(function (): void {
    if (gtkDmabufKinds() === []) {
        return;
    }
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

/** @return array{\Jovian\Toolkits\GTK\Windows\GTKWindow, \Jovian\Toolkits\GTK\Primitives\GTKCanvas} a shown window with a filling canvas */
function vulkanCanvas(int $width = 300, int $height = 200): array
{
    $window = driver()->open('main', $width, $height);
    $canvas = $window->column('m')->canvas('view')->fill();
    $window->present();
    pumpUntil(fn (): bool => $canvas->size()[1] > 0, 3.0);

    return [$window, $canvas];
}

it('lends a dmabuf surface with its display', function (): void {
    [$window, $canvas] = vulkanCanvas();

    expect($canvas->surfaces())->toBe([...(extension_loaded('opengl') ? [SurfaceKind::GL_CONTEXT] : []), SurfaceKind::DMABUF]);

    $surface = $canvas->lend(SurfaceKind::DMABUF, new GtkVulkanBorrower);

    expect($surface->handle('display'))->toBe(GdkDisplay::getDefault()->pointer())
        ->and($surface->size())->toBe($canvas->pixelSize())
        ->and($canvas->lent())->toBe($surface);
});

it('lends nothing a device it cannot host, naming what it can', function (): void {
    [$window, $canvas] = vulkanCanvas();

    expect(fn () => $canvas->lend(SurfaceKind::METAL_LAYER, new GtkVulkanBorrower))
        ->toThrow(WindowException::class, 'lends no metal-layer surface (it lends: '.gtkLends().').')
        ->and(gtkLends())->toEndWith('dmabuf');
});

it('shows vulkan frames through a dmabuf texture', function (): void {
    [$window, $canvas] = vulkanCanvas(320, 240);

    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    for ($i = 0; $i < 60; $i++) {
        $engine->frame(function (RenderingEngine $g) use ($i, $canvas): void {
            [$width, $height] = $canvas->pixelSize();
            $g->clear(Color::rgb(16, 24, 32));
            $g->fillEllipse($width * (0.2 + 0.6 * $i / 59), $height / 2, $height / 6, $height / 6, Color::rgb(255, 128, 0));
        });
        $canvas->present();
        pumpFor(1 / 60);
    }
    $paintable = $canvas->picture()->getPaintable();

    expect($canvas->lent()?->kind)->toBe(SurfaceKind::DMABUF)
        ->and($paintable)->toBeInstanceOf(GdkTexture::class)
        ->and($paintable)->not->toBeInstanceOf(GdkMemoryTexture::class)
        ->and([$paintable->getWidth(), $paintable->getHeight()])->toBe($canvas->pixelSize())
        ->and($engine->framebuffer()->damage())->toBe([]);
    $engine->release();
});

it('re-targets the engine when the window is resized, and the texture follows', function (): void {
    [$window, $canvas] = vulkanCanvas();
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
    $canvas->present();
    $before = $engine->framebuffer();

    $window->native()->setDefaultSize(400, 300);
    pumpUntil(fn (): bool => $canvas->pixelSize()[0] !== $before->viewportWidth(), 2.0);
    // Each frame re-targets to the size the canvas has then; a window that settles late is caught by the next.
    pumpUntil(function () use ($engine, $canvas): bool {
        $drawnAt = $canvas->pixelSize();
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 0)));
        $canvas->present();
        pumpFor(0.05);

        return [$engine->width(), $engine->height()] === $drawnAt;
    }, 2.0);
    $paintable = $canvas->picture()->getPaintable();

    expect($engine->framebuffer())->not->toBe($before)
        ->and([$engine->width(), $engine->height()])->toBe($canvas->pixelSize())
        ->and([$paintable->getWidth(), $paintable->getHeight()])->toBe([$engine->width(), $engine->height()]);
    $engine->release();
});

it('shows its framebuffer again after a reclaim', function (): void {
    [$window, $canvas] = vulkanCanvas();
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 255)));
    $canvas->present();
    pumpFor(0.1);
    $surface = $canvas->lent();

    $engine->release();
    pumpFor(0.1);
    $own = $canvas->framebuffer('dirty');
    $own->fill(0xFF6600FF);
    $canvas->present();
    pumpFor(0.1);

    expect($surface?->released())->toBeTrue()
        ->and($canvas->lent())->toBeNull()
        ->and($canvas->picture()->getPaintable())->toBeInstanceOf(GdkMemoryTexture::class);
});

it('reclaims before it is removed', function (): void {
    [$window, $canvas] = vulkanCanvas();
    $engine = new GpuRenderingEngine(new VulkanDevice, ...$canvas->pixelSize(), output: $canvas);
    $surface = $canvas->lent();

    $canvas->remove();

    expect($surface?->released())->toBeTrue()
        ->and($canvas->isRemoved())->toBeTrue();
    $engine->release();
});
