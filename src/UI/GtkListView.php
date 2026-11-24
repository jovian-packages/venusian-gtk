<?php

namespace Jovian\Toolkits\GTK\UI;

use GObject;
use Surface\Toolkits\UI\TKListView;
use Surface\Contracts\Toolkits\UI\ListRow;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;
use Jovian\Toolkits\GTK\UI\Concerns\GtkItemView;

/**
 * A GtkListView in a scrolled window, its rows set up once and bound as they are shown.
 */
class GtkListView extends TKListView
{
    use GtkItemView, GtkNative {
        GtkItemView::hasFocus insteadof GtkNative;
        GtkItemView::nativeFocus insteadof GtkNative;
    }

    /**
     * Each set-up row's parts, by the list item's object id; the list item is kept with them.
     * @var array<int, array{item: \GtkListItem, row: \GtkBox, picture: \GtkPicture, badge: \GtkLabel, title: \GtkLabel, icons: \GtkBox, detail: \GtkLabel, action: \GtkButton}>
     */
    protected array $rows = [];

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
                unset($owner->rows[spl_object_id($item)]);
            }
        });
        $view = \GtkListView::new($this->buildModel(), $factory);
        $this->connect($view, 'activate', static function (GObject $view, int $position) use ($self): void {
            $self->get()?->activated($position);
        });
        $this->scrollAround($view);
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
        $self = \WeakReference::create($this);
        $row_item = \WeakReference::create($item);
        $this->connect($action, 'clicked', static function () use ($self, $row_item): void {
            $clicked = $row_item->get();
            if (! is_null($clicked)) {
                $self->get()?->clickedActionIn($clicked);
            }
        });

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
