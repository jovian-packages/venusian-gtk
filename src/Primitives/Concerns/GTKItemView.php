<?php

namespace Jovian\Toolkits\GTK\Primitives\Concerns;

use Surface\Contracts\Windows\Mail\View\ItemActionClicked;
use Surface\Contracts\Windows\Mail\View\ItemActivated;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Styling\ScrollPosition;

/**
 * An item view's model and scrolling: a GtkStringList of one empty string per item (rows bind
 * by position, so an insert or removal is one splice), under a GtkSingleSelection that never
 * picks a row by itself, inside a GtkScrolledWindow whose vertical adjustment posts
 * ViewScrolled (latest-only) while watched. The item views' shared CSS goes in once per display.
 */
trait GTKItemView
{
    protected \GtkStringList $model;

    protected \GtkSingleSelection $selection;

    protected \GtkScrolledWindow $scrolled;

    private static ?\GtkCssProvider $item_css = null;

    /**
     * The selection model the view shows, over one row per item.
     */
    public function selection(): \GtkSingleSelection
    {
        return $this->selection;
    }

    protected function buildModel(): \GtkSingleSelection
    {
        $this->model = \GtkStringList::new(array_fill(0, count($this->items), ''));
        $this->selection = \GtkSingleSelection::new($this->model);
        $this->selection->setAutoselect(false);
        $this->selection->setCanUnselect(true);
        if (is_null(self::$item_css)) {
            self::$item_css = \GtkCssProvider::new();
            self::$item_css->loadFromString(
                '.surface-item-badge { background-color: @accent_bg_color; color: @accent_fg_color; border-radius: 4px; padding: 0 4px; margin: 2px; font-size: 9px; font-weight: bold; }'
                .' .surface-item-detail { font-size: 11px; opacity: 0.65; }'
                .' .surface-item-title { font-weight: 600; }'
                .' .surface-item-disabled { opacity: 0.5; }'
                .' .surface-item-image { border-radius: 8px; }'
                .' .surface-item-status { background-color: rgba(0, 0, 0, 0.55); border: 1px solid rgba(255, 255, 255, 0.7); border-radius: 15px; padding: 6px; }'
            );
            gtk_style_context_add_provider_for_display(\GdkDisplay::getDefault(), self::$item_css, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
        }

        return $this->selection;
    }

    protected function scrollAround(\GtkWidget $view): void
    {
        $this->scrolled = \GtkScrolledWindow::new();
        $this->scrolled->setPolicy(\GtkPolicyType::NEVER, \GtkPolicyType::AUTOMATIC);
        $this->scrolled->setChild($view);
        $this->adoptNative($this->scrolled);
        $this->connect($this->scrolled->getVadjustment(), 'value-changed', fn () => $this->scrolledNow());
    }

    protected function applyItems(array $items): void
    {
        $this->quietly(fn () => $this->model->splice(0, $this->model->getNItems(), array_fill(0, count($items), '')));
    }

    protected function applyInserted(int $at, array $items): void
    {
        $this->model->splice($at, 0, array_fill(0, count($items), ''));
    }

    protected function applyRemoved(int $at, int $count): void
    {
        $this->model->splice($at, $count, []);
    }

    /** A splice of the one row rebinds it. */
    protected function applyUpdated(int $index, object $item): void
    {
        $this->model->splice($index, 1, ['']);
    }

    protected function nativeScrollPosition(): ScrollPosition
    {
        $horizontal = $this->scrolled->getHadjustment();
        $vertical = $this->scrolled->getVadjustment();

        return ScrollPosition::of(
            $horizontal->getValue(), $horizontal->getPageSize(), $horizontal->getUpper(),
            $vertical->getValue(), $vertical->getPageSize(), $vertical->getUpper(),
        );
    }

    protected function applyScrollTo(float $x, float $y): void
    {
        $vertical = $this->scrolled->getVadjustment();
        $horizontal = $this->scrolled->getHadjustment();
        $this->quietly(function () use ($x, $y, $vertical, $horizontal): void {
            $horizontal->setValue(ScrollPosition::offset($x, $horizontal->getPageSize(), $horizontal->getUpper()));
            $vertical->setValue(ScrollPosition::offset($y, $vertical->getPageSize(), $vertical->getUpper()));
        });
    }

    protected function scrolledNow(): void
    {
        if ($this->applying || $this->removed || ! $this->watching_scroll) {
            return;
        }
        $this->session()->postLatest(
            "view.scrolled.{$this->window->name()}.{$this->path()}",
            new ViewScrolled($this->window->name(), $this->path(), $this->uuid, $this->nativeScrollPosition()),
        );
    }

    protected function activated(int $position): void
    {
        if ($this->enabled($position)) {
            $this->post(new ItemActivated($this->window->name(), $this->path(), $this->uuid, $position));
        }
    }

    protected function actionClicked(\GtkListItem $item): void
    {
        $position = $item->getPosition();
        if ($position !== GTK_INVALID_LIST_POSITION && $this->enabled($position)) {
            $this->post(new ItemActionClicked($this->window->name(), $this->path(), $this->uuid, $position));
        }
    }

    protected static function picture(?string $file, \GtkContentFit $fit): \GtkPicture
    {
        $picture = \GtkPicture::new();
        $picture->setContentFit($fit);
        $picture->setCanShrink(true);
        $picture->setFilename($file);

        return $picture;
    }
}
