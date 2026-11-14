<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use GObject;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKItemView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Primitives\ListRow;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Toolkits\Primitives\TKListView;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A GtkListView in a GtkScrolledWindow (the primitive's widget); a double click, Enter or Return
 * activates a row (GTK's own activate). Each row the factory sets up: a GtkOverlay of a covering
 * 3:2 GtkPicture with the badge label over its top-left corner, then a column of the title line
 * (semibold, ellipsized, status icons after it), the detail (wrapped, three lines at most,
 * ellipsized) and the action button at the bottom right. A disabled row is drawn at half opacity
 * and posts nothing. Rows bind by position.
 */
class GTKListView extends TKListView implements GTKView
{
    use GTKPrimitive;
    use GTKItemView;

    protected \GtkListView $list;

    /**
     * Each set-up row's parts, by the list item's object id; the list item is kept with them.
     * @var array<int, array{item: \GtkListItem, row: \GtkBox, picture: \GtkPicture, badge: \GtkLabel, title: \GtkLabel, icons: \GtkBox, detail: \GtkLabel, action: \GtkButton}>
     */
    protected array $rows = [];

    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $items)
    {
        parent::__construct($name, $window, $parent, $placement, $items);
        $factory = \GtkSignalListItemFactory::new();
        $this->connect($factory, 'setup', fn (GObject $factory, \GtkListItem $item) => $this->setUp($item));
        $this->connect($factory, 'bind', fn (GObject $factory, \GtkListItem $item) => $this->bind($item));
        $this->connect($factory, 'teardown', function (GObject $factory, \GtkListItem $item): void {
            unset($this->rows[spl_object_id($item)]);
        });
        $this->list = \GtkListView::new($this->buildModel(), $factory);
        $this->connect($this->list, 'activate', fn (\GtkListView $list, int $position) => $this->activated($position));
        $this->scrollAround($this->list);
    }

    public function listView(): \GtkListView
    {
        return $this->list;
    }

    public function focusWidget(): \GtkWidget
    {
        return $this->list;
    }

    /**
     * @return array{item: \GtkListItem, row: \GtkBox, picture: \GtkPicture, badge: \GtkLabel, title: \GtkLabel, icons: \GtkBox, detail: \GtkLabel, action: \GtkButton}|null
     */
    public function rowOf(\GtkListItem $item): ?array
    {
        return $this->rows[spl_object_id($item)] ?? null;
    }

    protected function applyRowHeight(int $height): void
    {
        foreach ($this->rows as $row) {
            self::sizeRow($row, $height);
        }
    }

    /**
     * @param array{row: \GtkBox, picture: \GtkPicture} $row
     */
    protected static function sizeRow(array $row, int $height): void
    {
        $inner = max(1, $height - 16);
        $row['row']->setSizeRequest(-1, $height);
        $row['picture']->setSizeRequest((int) round($inner * 1.5), $inner);
    }

    protected function setUp(\GtkListItem $item): void
    {
        $picture = self::picture(null, \GtkContentFit::COVER);
        $picture->addCssClass('surface-item-image');
        $badge = \GtkLabel::new(null);
        $badge->addCssClass('surface-item-badge');
        $badge->setHalign(\GtkAlign::START);
        $badge->setValign(\GtkAlign::START);
        $image = \GtkOverlay::new();
        $image->setChild($picture);
        $image->addOverlay($badge);
        $image->setValign(\GtkAlign::CENTER);

        $title = \GtkLabel::new(null);
        $title->addCssClass('surface-item-title');
        $title->setXalign(0.0);
        $title->setEllipsize(\PangoEllipsizeMode::END);
        $title->setHexpand(true);
        $icons = \GtkBox::new(\GtkOrientation::HORIZONTAL, 4);
        $line = \GtkBox::new(\GtkOrientation::HORIZONTAL, 6);
        $line->append($title);
        $line->append($icons);

        $detail = \GtkLabel::new(null);
        $detail->addCssClass('surface-item-detail');
        $detail->setXalign(0.0);
        $detail->setWrap(true);
        $detail->setLines(3);
        $detail->setEllipsize(\PangoEllipsizeMode::END);
        $detail->setVexpand(true);
        $detail->setValign(\GtkAlign::START);

        $action = \GtkButton::newWithLabel('');
        $action->setHalign(\GtkAlign::END);
        $this->connect($action, 'clicked', fn () => $this->actionClicked($item));

        $text = \GtkBox::new(\GtkOrientation::VERTICAL, 3);
        $text->setHexpand(true);
        foreach ([$line, $detail, $action] as $part) {
            $text->append($part);
        }
        $row = \GtkBox::new(\GtkOrientation::HORIZONTAL, 12);
        foreach (['setMarginStart', 'setMarginEnd'] as $margin) {
            $row->{$margin}(8);
        }
        $row->setMarginTop(8);
        $row->setMarginBottom(6);
        $row->append($image);
        $row->append($text);
        $item->setChild($row);

        $parts = ['item' => $item, 'row' => $row, 'picture' => $picture, 'badge' => $badge, 'title' => $title, 'icons' => $icons, 'detail' => $detail, 'action' => $action];
        self::sizeRow($parts, $this->row_height);
        $this->rows[spl_object_id($item)] = $parts;
    }

    protected function bind(\GtkListItem $item): void
    {
        $row = $this->rows[spl_object_id($item)] ?? null;
        $data = $this->items[$item->getPosition()] ?? null;
        if (is_null($row) || ! $data instanceof ListRow) {
            return;
        }
        $row['picture']->setFilename($data->image);
        $row['badge']->setText($data->badge ?? '');
        $row['badge']->setVisible(! is_null($data->badge));
        $row['title']->setText($data->title);
        while (! is_null($old = $row['icons']->getFirstChild())) {
            $row['icons']->remove($old);
        }
        foreach ($data->icons as $file) {
            $icon = \GtkImage::newFromIconName(null);
            $icon->setFromFile($file);
            $icon->setPixelSize(14);
            $row['icons']->append($icon);
        }
        $row['detail']->setText($data->detail);
        $row['action']->setLabel($data->action ?? '');
        $row['action']->setVisible(! is_null($data->action));
        $row['action']->setSensitive($data->enabled);
        $data->enabled ? $row['row']->removeCssClass('surface-item-disabled') : $row['row']->addCssClass('surface-item-disabled');
    }
}
