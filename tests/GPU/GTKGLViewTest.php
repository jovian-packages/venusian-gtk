<?php

use Jovian\Venusian\GTK\Views\GTKGLSurface;
use Jovian\Venusian\GTK\Views\GTKGLView;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\Drawing\GPUEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Venusian\GTK\Tests\Support\FakeFixed;
use Venusian\GTK\Tests\Support\FakeGLArea;
use Venusian\Surface\Tests\Support\Fakes\FakeExecutor;
use Venusian\Surface\Tests\Support\Fakes\FakeWindow;

function glView(int $scale = 1): array
{
    $area = new FakeGLArea(100, 50, $scale);
    $fixed = new FakeFixed;
    $executor = new FakeExecutor;
    $view = new GTKGLView('scene', new FakeWindow('main'), GPUEngine::OPENGL, $executor, (float) $scale, new GTKGLSurface($area), $fixed);

    return [$view, $area, $fixed, $executor];
}

it('drives its own frames: a request only queues a native render', function () {
    [$view, $area, , $executor] = glView();
    $view->onDraw(fn () => null);

    $view->requestFrame();

    expect($view->drivesOwnFrames())->toBeTrue()
        ->and($area->renders_queued)->toBe(1)
        ->and($executor->frames_begun)->toBe(0);
});

it('renders one Surface frame from the render signal and returns true', function () {
    [$view, $area, , $executor] = glView();
    $view->onDraw(fn () => null);

    expect($area->fireRender())->toBeTrue()
        ->and($executor->frames_begun)->toBe(1)
        ->and($executor->frames_ended)->toBe(1);
});

it('paints the clear colour when Surface has nothing to draw, because GTK shows the buffer anyway', function () {
    [$view, $area, , $executor] = glView(); // no hook → renderFrame() is false

    expect($area->fireRender())->toBeTrue()
        ->and($executor->frames_begun)->toBe(1)
        ->and($executor->draws)->toBe([])
        ->and($executor->frames_ended)->toBe(1);
});

it('applyFrame moves in the fixed, requests the size, and resizes the executor in pixels', function () {
    [$view, $area, $fixed, $executor] = glView(2);

    $view->place(10, 20, 300, 200);

    expect($fixed->log)->toContain(['move', 10.0, 20.0])
        ->and($area->size_requests)->toContain([300, 200])
        ->and($executor->size)->toBe([600, 400])
        ->and($view->scale())->toBe(2.0);
});

it('the surface answers pixels and lends the area', function () {
    $area = new FakeGLArea(100, 50, 2);
    $surface = new GTKGLSurface($area);

    expect($surface->drawableSize())->toBe([200, 100]);
    $surface->makeCurrent();
    $surface->present();
    expect($area->made_current)->toBe(1);
});

it('GTK hosts GL contexts and refuses layers, by kind', function () {
    expect(GTKWindowDelegate::hostsSurfaceKind(SurfaceKind::GL_CONTEXT))->toBeTrue()
        ->and(GTKWindowDelegate::hostsSurfaceKind(SurfaceKind::LAYER))->toBeFalse()
        ->and(GTKWindowDelegate::hostsSurfaceKind(SurfaceKind::VULKAN_SURFACE))->toBeFalse()
        ->and(GTKWindowDelegate::hostsSurfaceKind(SurfaceKind::HOST_WINDOW))->toBeFalse();
});
