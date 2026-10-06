<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A canvas over GtkPicture: each present() makes a GdkMemoryTexture of the framebuffer's RGBA8
 * bytes in the R8G8B8X8 layout (the fourth byte ignored, so opaque) and shows it, stretched over
 * the widget (content fit FILL). An ext-fb framebuffer never becomes a string: the texture is
 * copied by address, and where GTK is 4.16 or newer the texture after the first is built with
 * an update region, so only the damage is read. The picture may shrink below what it shows, so
 * the layout sizes it, never the framebuffer. R8G8B8X8 came with GTK 4.14: an older GTK cannot
 * host a canvas.
 *
 * The native view is a vertical GtkBox holding one child that fills it: the picture, or, while
 * the canvas lends a GL context (with ext-opengl loaded), a GtkGLArea in its place. The area
 * renders only when asked: present() queues a render, and the area's render callback copies the
 * borrower's frame into the area's framebuffer, so a frame GTK redraws on its own (an expose, a
 * move) is copied again rather than lost.
 */
class GTKCanvas extends TKCanvas implements GTKView
{
    use GTKPrimitive;

    /** The texture shown last, for the next one to update. */
    private ?\GdkTexture $texture = null;

    /** What the canvas shows its own framebuffers in. */
    private \GtkPicture $picture;

    /** The GL view while a GL context is lent; null otherwise. */
    private ?\GtkGLArea $area = null;

    /** Render callbacks the GL view has run, for tests. */
    private int $renders = 0;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @throws WindowException When the name is not valid, or GTK is older than 4.14.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        if (\gtk_get_major_version() === 4 && \gtk_get_minor_version() < 14) {
            throw new WindowException('TKCanvas needs GTK 4.14 or newer, this is 4.'.\gtk_get_minor_version().'.');
        }

        parent::__construct($name, $window, $parent, $placement);
        $box = \GtkBox::new(\GtkOrientation::VERTICAL, 0);
        $this->picture = \GtkPicture::new();
        $this->picture->setCanShrink(true);
        $this->picture->setContentFit(\GtkContentFit::FILL);
        $this->picture->setHexpand(true);
        $this->picture->setVexpand(true);
        $box->append($this->picture);
        $this->adoptNative($box);
    }

    /** The picture the canvas's own framebuffers are shown in. */
    public function picture(): \GtkPicture
    {
        return $this->picture;
    }

    /** The GL view standing in for the picture while a GL context is lent. */
    public function glArea(): ?\GtkGLArea
    {
        return $this->area;
    }

    /** Render callbacks the GL view has run. */
    public function renders(): int
    {
        return $this->renders;
    }

    /** A GL context, when ext-opengl is loaded to read it back and draw in the callback. */
    public function surfaces(): array
    {
        return self::hasOpenGL() ? [SurfaceKind::GL_CONTEXT] : [];
    }

    /**
     * Swap a GtkGLArea in for the picture and hand out the context GTK made for it.
     *
     * @throws WindowException When the window has not been presented, or GTK could not make the area's context.
     */
    protected function makeSurface(SurfaceKind $kind, array $handles): array
    {
        /** @var \GtkBox $box */
        $box = $this->native;
        if (! $box->getRealized()) {
            throw new WindowException("Canvas '{$this->path()}' has no GL context yet: present the window first.");
        }

        $area = \GtkGLArea::new();
        $area->setAutoRender(false);
        $area->setHasDepthBuffer(false);
        $area->setHasStencilBuffer(false);
        $area->setHexpand(true);
        $area->setVexpand(true);
        \g_signal_connect($area, 'render', function (\GtkGLArea $area, \GdkGLContext $context): bool {
            $this->renders++;
            $this->renderLent();

            return true;
        });
        // GTK makes the area's context current as it realizes it, and the view's context is read with it
        // current: whatever was current before is put back at the end (GTK's own notion cleared first).
        $previous = self::currentContext();
        $box->remove($this->picture);
        $box->append($area);

        // A child added to a realized parent is realized with it: its context exists now, or GTK refused one.
        $context = $area->getContext();
        if (! $area->getRealized() || is_null($context) || ! is_null($area->getError())) {
            $reason = $area->getError() ?? 'GTK made no GL context for the area';
            $box->remove($area);
            $box->append($this->picture);
            \GdkGLContext::clearCurrent();
            self::restoreContext($previous);

            throw new WindowException("Canvas '{$this->path()}' has no GL context: {$reason}.");
        }

        $area->makeCurrent();
        try {
            $lent = $this->contextHandles();
        } finally {
            \GdkGLContext::clearCurrent();
            self::restoreContext($previous);
        }
        $this->area = $area;

        return $lent;
    }

    /** The picture back in the area's place. */
    protected function removeSurface(SurfaceKind $kind): void
    {
        if (is_null($this->area)) {
            return;
        }
        /** @var \GtkBox $box */
        $box = $this->native;
        $box->remove($this->area);
        $box->append($this->picture);
        $this->area = null;
    }

    /** GL frames are copied inside the area's render callback: presenting queues one. */
    protected function presentLent(LentSurface $surface, SurfaceBorrower $borrower): static
    {
        $this->area?->queueRender();

        return $this;
    }

    /**
     * The area's render callback, its context current and its framebuffer bound for drawing:
     * the borrower copies its frame in, and the frame's epoch begins anew. Called with no
     * borrower (reclaimed, a render still queued) the area is cleared to black.
     */
    protected function renderLent(): void
    {
        $surface = $this->lent;
        $borrower = $this->borrower;
        if (is_null($surface) || is_null($borrower) || $surface->released()) {
            \glClearColor(0.0, 0.0, 0.0, 1.0);
            \glClear(GL_COLOR_BUFFER_BIT);

            return;
        }
        $borrower->presentInto($surface);
        $frame = $borrower->framebuffer();
        if ($frame instanceof DamageTrackingFramebuffer) {
            $frame->beginEpoch();
        }
    }

    /**
     * The current context, read back through ext-opengl: CGL on macOS, EGL (with its display) elsewhere.
     *
     * @return array<string, int>
     * @throws WindowException When no context is current.
     */
    private function contextHandles(): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $context = \CGLGetCurrentContext() ?? throw new WindowException("Canvas '{$this->path()}': the GL view made no context current.");

            return ['context' => $context->pointer()];
        }
        $context = \eglGetCurrentContext() ?? throw new WindowException("Canvas '{$this->path()}': the GL view made no context current.");
        $display = \eglGetCurrentDisplay() ?? throw new WindowException("Canvas '{$this->path()}': the GL view's context has no display.");

        return ['context' => $context->pointer(), 'display' => $display->pointer()];
    }

    /**
     * Whatever context is current now, to put back after reading the view's: the CGL context on
     * macOS; on EGL the display, draw and read surfaces with it (null when none is current).
     *
     * @return array<int, mixed>|null
     */
    private static function currentContext(): ?array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return [\CGLGetCurrentContext()];
        }
        $context = \eglGetCurrentContext();

        return is_null($context) ? null : [\eglGetCurrentDisplay(), \eglGetCurrentSurface(EGL_DRAW), \eglGetCurrentSurface(EGL_READ), $context];
    }

    /** @param array<int, mixed>|null $previous What currentContext() answered; null leaves nothing current. */
    private static function restoreContext(?array $previous): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            \CGLSetCurrentContext($previous[0] ?? null);

            return;
        }
        if (! is_null($previous)) {
            \eglMakeCurrent(...$previous);
        }
    }

    /** ext-opengl is loaded: the context can be read back and the render callback can clear. */
    private static function hasOpenGL(): bool
    {
        return function_exists(PHP_OS_FAMILY === 'Darwin' ? 'CGLGetCurrentContext' : 'eglGetCurrentContext');
    }

    /** Device pixels per application pixel on the widget's surface. */
    protected function nativeScale(): float
    {
        return (float) $this->native->getScaleFactor();
    }

    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        $this->texture = \GdkMemoryTexture::new($width, $height, \GdkMemoryFormat::R8G8B8X8, $rgba8, $width * 4);
        $this->picture->setPaintable($this->texture);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $previous = $this->texture;
        $updates = ! is_null($previous) && $damage !== [] && \gtk_get_minor_version() >= 16
            && $previous->getWidth() === $width && $previous->getHeight() === $height;

        $this->texture = $updates
            ? \GdkMemoryTextureBuilder::new()
                ->setBytes($address, $stride * $height)
                ->setWidth($width)->setHeight($height)->setFormat(\GdkMemoryFormat::R8G8B8X8)->setStride($stride)
                ->setUpdateTexture($previous)
                ->setUpdateRegion(array_map(fn (Region $region): array => [$region->x, $region->y, $region->width, $region->height], $damage))
                ->build()
            : \GdkMemoryTexture::new($width, $height, \GdkMemoryFormat::R8G8B8X8, $address, $stride);

        $this->picture->setPaintable($this->texture);
    }
}
