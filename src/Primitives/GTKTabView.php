<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use GtkLabel;
use GtkNotebook;
use GtkWidget;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Mail\Views\TabSelected;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\Primitives\TKColumn;
use Surface\Contracts\Toolkits\Primitives\TKPrimitive;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Toolkits\Primitives\TKTabView;

/**
 * A tab view over GtkNotebook, one page per column, its tab a GtkLabel. switch-page fires for
 * every change of page, before the current page changes: GTK's own on the first append, on
 * removing the current page, and when the current page hides, as well as the user's. The page
 * comes from the signal's argument, and only the user's switches, away from a visible current
 * page, post TabSelected.
 */
class GTKTabView extends TKTabView implements GTKContainer
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @throws ToolkitRuntimeException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $notebook = GtkNotebook::new();
        $this->adoptNative($notebook);
        $this->connect($notebook, 'switch-page', function (GtkNotebook $notebook, GtkWidget $page): void {
            // GTK switches by itself when the current page hides or none is current; the user
            // switches away from a visible current page.
            $current = $notebook->getCurrentPage();
            $outgoing = $current < 0 ? null : $notebook->getNthPage($current);
            if (is_null($outgoing) || ! $outgoing->getVisible()) {
                return;
            }
            $name = $this->pageNamed($page);
            if (! $this->removed && ! is_null($name)) {
                $this->post(new TabSelected($this->window->name(), $this->path(), $this->uuid, $name));
            }
        });
    }

    public function native(): GtkNotebook
    {
        /** @var GtkNotebook */
        return $this->native;
    }

    public function removeNative(TKPrimitive $child): void
    {
        $number = $this->native()->pageNum(self::nativeOf($child));
        if ($number >= 0) {
            $this->quietly(fn () => $this->native()->removePage($number));
        }
    }

    protected function insertPage(TKColumn $page, string $title, ?int $index): void
    {
        $this->quietly(fn () => is_null($index)
            ? $this->native()->appendPage(self::nativeOf($page), GtkLabel::new($title))
            : $this->native()->insertPage(self::nativeOf($page), GtkLabel::new($title), $index));
    }

    protected function applyTitle(TKColumn $page, string $title): void
    {
        $this->native()->setTabLabelText(self::nativeOf($page), $title);
    }

    protected function applySelect(TKColumn $page): void
    {
        $number = $this->native()->pageNum(self::nativeOf($page));
        $this->quietly(fn () => $this->native()->setCurrentPage($number));
    }

    protected function nativeSelected(): ?string
    {
        $current = $this->native()->getCurrentPage();
        $page = $current < 0 ? null : $this->native()->getNthPage($current);

        return is_null($page) ? null : $this->pageNamed($page);
    }

    /**
     * The name of the page whose column is $widget.
     *
     * @param GtkWidget $widget
     * @return string|null
     */
    protected function pageNamed(GtkWidget $widget): ?string
    {
        foreach ($this->children as $name => $page) {
            if (self::nativeOf($page) === $widget) {
                return $name;
            }
        }

        return null;
    }
}
