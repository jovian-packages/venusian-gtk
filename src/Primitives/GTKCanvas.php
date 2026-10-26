<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Input\GTKInputEngine;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Jovian\Toolkits\GTK\Windows\GTKCanvasCoast;
use Surface\Contracts\Windows\Mail\View\CanvasWheeled;
use Surface\Contracts\Windows\Mail\View\ScrollPhase;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\DmabufExportable;
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
 *
 * On Linux with GTK 4.14+ it lends a dmabuf surface too: nothing native is made, the handle is the
 * canvas's GdkDisplay. The borrower exports each frame as a dmabuf (its framebuffer is
 * DmabufExportable); after each present the canvas builds a GdkDmabufTexture over the export and
 * shows it in the picture. The export's fd is the borrower's, borrowed by every texture built over
 * it: the borrower keeps it open until the surface is released, and the canvas drops its texture
 * then.
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

    /** The wheel over the canvas, on both axes, kinetic: gestures begin and end, a flick decelerates. */
    private \GtkEventControllerScroll $scroll;

    /** Inside a gesture the device reported a begin for; whether it has posted yet. */
    private bool $gesture = false;

    private bool $gesture_posted = false;

    private ?GTKCanvasCoast $coast = null;

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

        // The controller's handler is held by the box, which this canvas holds: a weak reference breaks the cycle.
        $self = \WeakReference::create($this);
        $this->scroll = \GtkEventControllerScroll::new(GTK_EVENT_CONTROLLER_SCROLL_BOTH_AXES | GTK_EVENT_CONTROLLER_SCROLL_KINETIC);
        $this->connect($this->scroll, 'scroll-begin', static fn () => $self->get()?->gestureBegan());
        $this->connect($this->scroll, 'scroll-end', static fn () => $self->get()?->gestureEnded());
        $this->connect($this->scroll, 'decelerate', static fn (\GtkEventControllerScroll $controller, float $vx, float $vy) => $self->get()?->decelerated($vx, $vy));
        $this->connect($this->scroll, 'scroll', static function (\GtkEventControllerScroll $controller, float $dx, float $dy) use ($self): bool {
            $canvas = $self->get();
            if (is_null($canvas)) {
                return false;
            }

            return $canvas->wheeled($dx, $dy, $controller->getUnit() === \GdkScrollUnit::SURFACE, $controller->getCurrentEvent()?->getModifierState() ?? 0);
        });
        $box->addController($this->scroll);
    }

    /** The picture the canvas's own framebuffers are shown in. */
    public function picture(): \GtkPicture
    {
        return $this->picture;
    }

    /** The controller the wheel over the canvas arrives on. */
    public function scrollController(): \GtkEventControllerScroll
    {
        return $this->scroll;
    }

    /** A press on the canvas grabs focus while it is focusable: a GtkBox takes none by itself. */
    private ?\GtkGestureClick $focus_click = null;

    /** In the capture phase, so it runs before the window's Tab move: keeps Tab while the canvas takes it. */
    private ?\GtkEventControllerKey $tab_keys = null;

    /** The controller that keeps Tab, for tests; null until the canvas was first made focusable. */
    public function tabKeys(): ?\GtkEventControllerKey
    {
        return $this->tab_keys;
    }

    /**
     * The focusable property of the box and of the child it shows (the picture, or a lent GL area):
     * a GtkBox moves Tab focus only among its children, so the child is the Tab stop. The click and
     * Tab controllers are made on the first call and read the canvas's flags, so a later call
     * changes only the properties.
     */
    protected function applyFocusable(bool $on, bool $takesTab): void
    {
        foreach ([$this->native, $this->picture, $this->area] as $widget) {
            if (! is_null($widget)) {
                g_object_set_property($widget, 'focusable', $on);
            }
        }
        // A focused widget that stops being focusable keeps focus: a canvas that stops taking focus lets go, quietly.
        $focus = $this->host()->focusTracker();
        if (! $on && $focus->within($this)) {
            $root = $this->host()->native();
            $focus->quietly(static fn () => $root->setFocus(null));
        }
        if (! is_null($this->focus_click)) {
            return;
        }
        // The controllers' handlers are held by the box, which this canvas holds: a weak reference breaks the cycle.
        $self = \WeakReference::create($this);
        $this->focus_click = \GtkGestureClick::new();
        $this->focus_click->setButton(0);
        $this->connect($this->focus_click, 'pressed', static function () use ($self): void {
            $canvas = $self->get();
            if (! is_null($canvas) && $canvas->isFocusable()) {
                // The user's move: the window's focus tracker posts it.
                $canvas->native()->grabFocus();
            }
        });
        $this->native->addController($this->focus_click);
        $this->tab_keys = \GtkEventControllerKey::new();
        $this->tab_keys->setPropagationPhase(\GtkPropagationPhase::CAPTURE);
        $this->connect($this->tab_keys, 'key-pressed', static fn (\GtkEventControllerKey $controller, int $keyval): bool => ($self->get()?->takesTab() ?? false) && in_array($keyval, [0xFF09, 0xFE20], true));
        $this->native->addController($this->tab_keys);
    }

    /**
     * The wheel over the canvas: CanvasWheeled while it watches the pointer, and the event is the
     * canvas's — handled here (true), so an enclosing scroll view never scrolls with it. A canvas
     * that was removed, is not watching, or is disabled itself or inside a group passes it on
     * (false), to scroll whatever encloses it as before.
     *
     * @param  bool  $precise  The deltas are trackpad points (GdkScrollUnit::SURFACE), not wheel notches.
     * @param  int  $state  The modifier state (GDK_*_MASK) held with it.
     */
    public function wheeled(float $dx, float $dy, bool $precise, int $state): bool
    {
        if (! $this->takesWheels()) {
            return false;
        }
        $phase = ScrollPhase::NONE;
        if ($this->gesture) {
            $phase = $this->gesture_posted ? ScrollPhase::CHANGED : ScrollPhase::BEGAN;
            $this->gesture_posted = true;
        }
        $this->session()->post(new CanvasWheeled($this->window->name(), $this->path(), $this->uuid, $dx, $dy, $precise, GTKInputEngine::modifiers($state), $phase));

        return true;
    }

    /** scroll-begin: fingers down. A coast still running ends first. */
    public function gestureBegan(): void
    {
        $this->endCoast();
        $this->gesture = true;
        $this->gesture_posted = false;
    }

    /** scroll-end: fingers up. ENDED, with no movement, for a gesture that posted. */
    public function gestureEnded(): void
    {
        $posted = $this->gesture && $this->gesture_posted;
        $this->gesture = false;
        $this->gesture_posted = false;
        if ($posted && $this->takesWheels()) {
            $this->session()->post(new CanvasWheeled($this->window->name(), $this->path(), $this->uuid, 0.0, 0.0, true, GTKInputEngine::modifiers(0), ScrollPhase::ENDED));
        }
    }

    /** decelerate: the flick's velocity in pixels per ms starts a coast the session pump ticks. */
    public function decelerated(float $vx, float $vy): void
    {
        $this->endCoast();
        if (! $this->takesWheels()) {
            return;
        }
        $this->coast = new GTKCanvasCoast($this, $vx, $vy, GTKCanvasCoast::nowMs());
        $this->session()->coast($this->coast);
    }

    /** The running coast; null when there is none. */
    public function coast(): ?GTKCanvasCoast
    {
        return $this->coast?->done() === false ? $this->coast : null;
    }

    /** End a running coast with MOMENTUM_ENDED: a new gesture or a press on the canvas. */
    public function endCoast(): void
    {
        $this->coast?->end();
        $this->coast = null;
    }

    /** A coast step: MOMENTUM or MOMENTUM_ENDED, precise, no modifiers. */
    public function coasted(float $dx, float $dy, ScrollPhase $phase): void
    {
        if ($this->takesWheels()) {
            $this->session()->post(new CanvasWheeled($this->window->name(), $this->path(), $this->uuid, $dx, $dy, true, GTKInputEngine::modifiers(0), $phase));
        }
    }

    /** A running coast ends with no mail: the canvas is going. */
    protected function releaseNative(): void
    {
        $this->coast?->drop();
        $this->coast = null;
    }

    /** Watching, not removed, enabled itself and through its groups. */
    private function takesWheels(): bool
    {
        if ($this->isRemoved() || ! $this->isWatchingPointer()) {
            return false;
        }
        for ($at = $this; ! is_null($at); $at = $at->parent()) {
            if (! $at->isEnabled()) {
                return false;
            }
        }

        return true;
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

    /**
     * A GL context, when ext-opengl is loaded to read it back and draw in the callback; then a
     * dmabuf surface on Linux where GTK imports dmabufs (4.14+).
     */
    public function surfaces(): array
    {
        return [
            ...(self::hasOpenGL() ? [SurfaceKind::GL_CONTEXT] : []),
            ...($this->importsDmabuf() ? [SurfaceKind::DMABUF] : []),
        ];
    }

    /** XBGR8888: the export's ABGR8888 bytes with the fourth one ignored, so opaque, as the canvas shows its own framebuffers. */
    private const int DRM_FORMAT_XBGR8888 = 0x34324258;

    private const int DRM_FORMAT_ABGR8888 = 0x34324241;

    private const int DRM_FORMAT_MOD_LINEAR = 0;

    /**
     * GTK can build a texture over the engine's export here: Linux, GTK 4.14+ running and bound,
     * and the canvas's display importing linear XBGR8888.
     */
    private function importsDmabuf(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! class_exists(\GdkDmabufTextureBuilder::class) || ! method_exists(\GdkDisplay::class, 'getDmabufFormats')
            || (\gtk_get_major_version() === 4 && \gtk_get_minor_version() < 14)) {
            return false;
        }

        return $this->native->getDisplay()->getDmabufFormats()->contains(self::DRM_FORMAT_XBGR8888, self::DRM_FORMAT_MOD_LINEAR);
    }

    /**
     * Swap a GtkGLArea in for the picture and hand out the context GTK made for it.
     *
     * @throws WindowException When the window has not been presented, or GTK could not make the area's context.
     */
    protected function makeSurface(SurfaceKind $kind, array $handles): array
    {
        if ($kind === SurfaceKind::DMABUF) {
            // The picture stays: the frames arrive as textures built over the borrower's export.
            return ['display' => $this->native->getDisplay()->pointer()];
        }
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
        // The shown child is the canvas's Tab stop.
        g_object_set_property($area, 'focusable', $this->isFocusable());
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
            $reason = $area->getError()?->getMessage() ?? 'GTK made no GL context for the area';
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

    /** The picture back in the area's place; after a dmabuf lend, the last imported texture goes (its fd is about to close). */
    protected function removeSurface(SurfaceKind $kind): void
    {
        if ($kind === SurfaceKind::DMABUF) {
            $this->picture->setPaintable(null);
            $this->texture = null;

            return;
        }
        if (is_null($this->area)) {
            return;
        }
        /** @var \GtkBox $box */
        $box = $this->native;
        $box->remove($this->area);
        $box->append($this->picture);
        $this->area = null;
    }

    /**
     * GL frames are copied inside the area's render callback: presenting queues one. A dmabuf
     * frame: the borrower blits into its export and waits, then the canvas shows a texture built
     * over it. Nothing when nothing was drawn since the last frame shown.
     */
    protected function presentLent(LentSurface $surface, SurfaceBorrower $borrower): static
    {
        if ($surface->kind !== SurfaceKind::DMABUF) {
            $this->area?->queueRender();

            return $this;
        }
        $frame = $borrower->framebuffer();
        $tracked = $frame instanceof DamageTrackingFramebuffer;
        if (! is_null($this->shown) && $tracked && $frame->damage() === []) {
            return $this;
        }
        if (! $borrower->presentInto($surface)) {
            return $this;
        }
        $this->shown = 0;
        $export = $frame instanceof DmabufExportable ? $frame->dmabuf() : null;
        if (! is_null($export)) {
            $this->showDmabuf($frame, $export);
        }
        if ($tracked) {
            $frame->beginEpoch();
        }

        return $this;
    }

    /**
     * A GdkDmabufTexture over the borrower's export, shown in the picture; built as an update of
     * the last one when the size is unchanged. Imported as XBGR8888 (the export's ABGR8888 bytes,
     * the fourth ignored) so the frame is opaque, as the canvas shows its own framebuffers. When
     * GTK finalizes the texture, done with the fd, the export goes back to the borrower.
     *
     * @param  array{fd: int, width: int, height: int, stride: int, offset: int, fourcc: int, modifier: int}  $export
     * @throws WindowException When GTK cannot import the dmabuf.
     */
    private function showDmabuf(DmabufExportable $frame, array $export): void
    {
        $previous = $this->texture;
        $same = ! is_null($previous) && $previous->getWidth() === $export['width'] && $previous->getHeight() === $export['height'];
        $fd = $export['fd'];
        try {
            $builder = \GdkDmabufTextureBuilder::new();
            $builder->setDisplay($this->native->getDisplay());
            $builder->setWidth($export['width']);
            $builder->setHeight($export['height']);
            $builder->setFourcc($export['fourcc'] === self::DRM_FORMAT_ABGR8888 ? self::DRM_FORMAT_XBGR8888 : $export['fourcc']);
            $builder->setModifier($export['modifier']);
            $builder->setPremultiplied(false);
            $builder->setNPlanes(1);
            $builder->setFd(0, $fd);
            $builder->setStride(0, $export['stride']);
            $builder->setOffset(0, $export['offset']);
            $builder->setUpdateTexture($same ? $previous : null);
            // GTK answers an import it cannot make with a GError, or with NULL and a critical.
            $texture = $builder->build(fn () => $frame->releaseDmabuf($fd));
        } catch (\GError|\ValueError $failure) {
            // Nothing holds the export now: it goes back at once.
            $frame->releaseDmabuf($fd);

            throw new WindowException("Canvas '{$this->path()}' cannot show the dmabuf: GTK refused to import it ({$failure->getMessage()}).", previous: $failure);
        }
        if (is_null($texture)) {
            // No texture, so GTK calls no destroy notify: the export goes back here.
            $frame->releaseDmabuf($fd);

            throw new WindowException("Canvas '{$this->path()}' cannot show the dmabuf: GTK made no texture from it.");
        }
        $this->picture->setPaintable($this->texture = $texture);
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

    /** Device pixels per application pixel on the widget's surface, fractional scales included; the integer factor before the surface exists. */
    protected function nativeScale(): float
    {
        return $this->native->getNative()?->getSurface()?->getScale() ?? (float) $this->native->getScaleFactor();
    }

    protected function applyPixels(string $rgba8, int $width, int $height, array $damage): void
    {
        $this->texture = \GdkMemoryTexture::new($width, $height, \GdkMemoryFormat::R8G8B8X8, \GBytes::new($rgba8, strlen($rgba8)), $width * 4)
            ?? throw new WindowException("Canvas '{$this->path()}' cannot show {$width}x{$height} pixels: GDK made no texture from them.");
        $this->picture->setPaintable($this->texture);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $previous = $this->texture;
        $updates = ! is_null($previous) && $damage !== [] && \gtk_get_minor_version() >= 16
            && $previous->getWidth() === $width && $previous->getHeight() === $height;

        // The address is the frame's pixels, $stride * $height bytes: GLib copies them into the GBytes.
        $bytes = \GBytes::new($address, $stride * $height)
            ?? throw new WindowException("Canvas '{$this->path()}' cannot read its pixels: GLib made no bytes from the frame's address.");
        if ($updates) {
            $region = \CairoRegion::create();
            foreach ($damage as $rect) {
                $region->unionRectangle(new \GdkRectangle($rect->x, $rect->y, $rect->width, $rect->height));
            }
            $builder = \GdkMemoryTextureBuilder::new();
            $builder->setBytes($bytes);
            $builder->setWidth($width);
            $builder->setHeight($height);
            $builder->setFormat(\GdkMemoryFormat::R8G8B8X8);
            $builder->setStride($stride);
            $builder->setUpdateTexture($previous);
            $builder->setUpdateRegion($region);
            $texture = $builder->build();
        } else {
            $texture = \GdkMemoryTexture::new($width, $height, \GdkMemoryFormat::R8G8B8X8, $bytes, $stride);
        }
        $this->texture = $texture ?? throw new WindowException("Canvas '{$this->path()}' cannot show {$width}x{$height} pixels: GDK made no texture from them.");

        $this->picture->setPaintable($this->texture);
    }
}
