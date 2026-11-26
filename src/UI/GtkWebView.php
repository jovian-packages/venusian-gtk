<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GtkOrientation;
use Surface\Toolkits\UI\TKWebView;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A web view that shows nothing: GTK 4 has no web view of its own, and the engine for one
 * (WebKitGTK, Linux only) is not bound by ext-gtk yet. Web pages need WebKitGTK bindings in
 * ext-gtk (planned for Linux).
 *
 * supported() is false, so topside posts WebViewFailed (TKWebView::NO_ENGINE) for every load
 * itself and the view can offer something else, a link out. The native is an empty GtkBox: it
 * lays out, fills and takes a size like any primitive, and draws nothing.
 */
class GtkWebView extends TKWebView
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(GtkBox::new(GtkOrientation::VERTICAL, 0));
    }

    public function supported(): bool
    {
        return false;
    }

    public function url(): ?string
    {
        return null;
    }

    public function title(): ?string
    {
        return null;
    }

    public function isLoading(): bool
    {
        return false;
    }

    /**
     * Never reached: with supported() false, topside answers a load with WebViewFailed itself.
     */
    protected function applyLoad(string $url): void {}

    /**
     * Never reached: see applyLoad().
     */
    protected function applyLoadHtml(string $html, ?string $baseUrl): void {}

    /**
     * Never reached: see applyLoad().
     */
    protected function applyReload(): void {}

    /**
     * Never reached: see applyLoad().
     */
    protected function applyStop(): void {}
}
