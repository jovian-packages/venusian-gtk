<?php

namespace Jovian\Toolkits\GTK\Primitives;

use GObject;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKItemView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\CollectionItem;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Windows\Primitives\TKCollectionView;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A GtkGridView in a GtkScrolledWindow (the primitive's widget), activating on a single click.
 * The hover highlight single-click activation shows leaves with the pointer.
 * Each cell the factory sets up is a GtkOverlay: a covering GtkPicture above a one-line caption,
 * with the status icons (each on a dark disc) and a frameless image button over the top-right
 * corner. Cells bind by position.
 */
class GTKCollectionView extends TKCollectionView implements GTKView
{
    use GTKPrimitive;
    use GTKItemView {
        activated as itemActivated;
    }

    protected const int CAPTION = 24;

    protected \GtkGridView $grid;

    /**
     * Each set-up cell's parts, by the list item's object id; the list item is kept with them.
     * @var array<int, array{item: \GtkListItem, picture: \GtkPicture, caption: \GtkLabel, icons: \GtkBox, action: \GtkButton, icon: \GtkImage}>
     */
    protected array $cells = [];

    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $items)
    {
        parent::__construct($name, $window, $parent, $placement, $items);
        $factory = \GtkSignalListItemFactory::new();
        $this->connect($factory, 'setup', fn (GObject $factory, \GtkListItem $item) => $this->setUp($item));
        $this->connect($factory, 'bind', fn (GObject $factory, \GtkListItem $item) => $this->bind($item));
        $this->connect($factory, 'teardown', function (GObject $factory, \GtkListItem $item): void {
            unset($this->cells[spl_object_id($item)]);
        });
        $this->grid = \GtkGridView::new($this->buildModel(), $factory);
        $this->grid->setMinColumns(1);
        $this->grid->setMaxColumns(24);
        $this->grid->setSingleClickActivate(true);
        $this->connect($this->grid, 'activate', fn (\GtkGridView $grid, int $position) => $this->activated($position));
        // Single-click activation selects the cell under the pointer on hover, and GTK leaves it
        // selected once the pointer goes: the highlight goes with the pointer.
        $hover = \GtkEventControllerMotion::new();
        $this->connect($hover, 'leave', fn () => $this->pointerLeft());
        $this->grid->addController($hover);
        $this->scrollAround($this->grid);
    }

    public function gridView(): \GtkGridView
    {
        return $this->grid;
    }

    /**
     * A click picks a cell and is done: the selection is cleared at once, so no cell stays
     * highlighted and the same cell posts again, as AppKit's collection view does.
     */
    protected function activated(int $position): void
    {
        $this->itemActivated($position);
        $this->selection->setSelected(GTK_INVALID_LIST_POSITION);
    }

    /** The pointer left the grid: no cell stays highlighted for it. */
    protected function pointerLeft(): void
    {
        $this->selection->setSelected(GTK_INVALID_LIST_POSITION);
    }

    public function focusWidget(): \GtkWidget
    {
        return $this->grid;
    }

    /**
     * @return array{item: \GtkListItem, picture: \GtkPicture, caption: \GtkLabel, icons: \GtkBox, action: \GtkButton, icon: \GtkImage}|null
     */
    public function cellOf(\GtkListItem $item): ?array
    {
        return $this->cells[spl_object_id($item)] ?? null;
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
        $this->connect($action, 'clicked', fn () => $this->actionClicked($item));

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
