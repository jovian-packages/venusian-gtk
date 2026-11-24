<?php

namespace Jovian\Toolkits\GTK\UI;

use GObject;
use Surface\Toolkits\UI\TKCollectionView;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;
use Jovian\Toolkits\GTK\UI\Concerns\GtkItemView;
use Surface\Contracts\Toolkits\UI\CollectionItem;

/**
 * A GtkGridView in a scrolled window, its rows set up once and bound as they are shown.
 */
class GtkCollectionView extends TKCollectionView
{
    use GtkItemView, GtkNative {
        GtkItemView::hasFocus insteadof GtkNative;
        GtkItemView::nativeFocus insteadof GtkNative;
    }

    protected const int CAPTION = 24;

    /**
     * Each set-up cell's parts, by the list item's object id; the list item is kept with them.
     * @var array<int, array{item: \GtkListItem, picture: \GtkPicture, caption: \GtkLabel, icons: \GtkBox, action: \GtkButton, icon: \GtkImage}>
     */
    protected array $cells = [];

    protected function bootstrapNative(): void
    {
        $self = \WeakReference::create($this);
        $factory = \GtkSignalListItemFactory::new();
        $this->connect($factory, 'setup', static function (GObject $factory, \GtkListItem $item) use ($self): void {
            $self->get()?->setUp($item);
        });
        $this->connect($factory, 'bind', static function (GObject $factory, \GtkListItem $item) use ($self): void {
            $self->get()?->bind($item);
        });
        $this->connect($factory, 'teardown', static function (GObject $factory, \GtkListItem $item) use ($self): void {
            $owner = $self->get();
            if (! is_null($owner)) {
                unset($owner->cells[spl_object_id($item)]);
            }
        });
        $view = \GtkGridView::new($this->buildModel(), $factory);
        $this->connect($view, 'activate', static function (GObject $view, int $position) use ($self): void {
            $self->get()?->activated($position);
        });
        $view->setMinColumns(1);
        $view->setMaxColumns(24);
        $view->setSingleClickActivate(true);
        // Single-click activation selects the cell under the pointer on hover, and GTK leaves it
        // selected once the pointer goes: the highlight goes with the pointer.
        $hover = \GtkEventControllerMotion::new();
        $this->connect($hover, 'leave', static function () use ($self): void {
            $self->get()?->pointerLeft();
        });
        $view->addController($hover);
        $this->scrollAround($view);
    }

    /**
     * A click picks a cell and is done: nothing stays highlighted and the same cell posts
     * again, as AppKit's collection view does.
     */
    protected function activated(int $position): void
    {
        parent::activated($position);
        $this->selection->setSelected(GTK_INVALID_LIST_POSITION);
    }

    protected function pointerLeft(): void
    {
        $this->selection->setSelected(GTK_INVALID_LIST_POSITION);
    }

    protected function applyCellSize(int $width, int $height): void
    {
        foreach ($this->cells as $cell) {
            $cell['picture']->setSizeRequest($width, max(1, $height - self::CAPTION));
        }
    }

    protected function setUp(\GtkListItem $item): void
    {
        $picture = self::picture(null, \GtkContentFit::COVER);
        $picture->addCssClass('surface-item-image');
        $picture->setSizeRequest($this->cell_width, max(1, $this->cell_height - self::CAPTION));
        $caption = \GtkLabel::new(null);
        $caption->setEllipsize(\PangoEllipsizeMode::END);
        $box = \GtkBox::new(\GtkOrientation::VERTICAL, 4);
        $box->append($picture);
        $box->append($caption);

        $icon = \GtkImage::newFromIconName(null);
        $icon->setPixelSize(24);
        $action = \GtkButton::newWithLabel('');
        $action->setHasFrame(false);
        $action->setChild($icon);
        $action->setValign(\GtkAlign::CENTER);
        $self = \WeakReference::create($this);
        $row_item = \WeakReference::create($item);
        $this->connect($action, 'clicked', static function () use ($self, $row_item): void {
            $clicked = $row_item->get();
            if (! is_null($clicked)) {
                $self->get()?->clickedActionIn($clicked);
            }
        });

        // The top-right corner: the status icons, then the action button.
        $icons = \GtkBox::new(\GtkOrientation::HORIZONTAL, 4);
        $icons->setValign(\GtkAlign::CENTER);
        $corner = \GtkBox::new(\GtkOrientation::HORIZONTAL, 4);
        $corner->append($icons);
        $corner->append($action);
        $corner->setHalign(\GtkAlign::END);
        $corner->setValign(\GtkAlign::START);
        $corner->setMarginTop(6);
        $corner->setMarginEnd(6);

        $overlay = \GtkOverlay::new();
        $overlay->setChild($box);
        $overlay->addOverlay($corner);
        $item->setChild($overlay);
        $this->cells[spl_object_id($item)] = ['item' => $item, 'picture' => $picture, 'caption' => $caption, 'icons' => $icons, 'action' => $action, 'icon' => $icon];
    }

    protected function bind(\GtkListItem $item): void
    {
        $cell = $this->cells[spl_object_id($item)] ?? null;
        $data = $this->items[$item->getPosition()] ?? null;
        if (is_null($cell) || ! $data instanceof CollectionItem) {
            return;
        }
        $cell['picture']->setFilename($data->image);
        $cell['caption']->setText($data->caption);
        while (! is_null($old = $cell['icons']->getFirstChild())) {
            $cell['icons']->remove($old);
        }
        foreach ($data->icons as $file) {
            $status = \GtkImage::newFromIconName(null);
            $status->setFromFile($file);
            $status->setPixelSize(16);
            $status->addCssClass('surface-item-status');
            $cell['icons']->append($status);
        }
        $cell['icon']->setFromFile($data->action);
        $cell['action']->setVisible(! is_null($data->action));
    }
}
