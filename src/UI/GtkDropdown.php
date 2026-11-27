<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GObject;
use GtkListItem;
use GtkOrientation;
use GtkStringList;
use GtkStringObject;
use WeakReference;
use GtkSignalListItemFactory;
use GtkImage as NativeImage;
use GtkLabel as NativeLabel;
use GtkDropDown as NativeDropDown;
use Surface\Toolkits\UI\TKDropdown;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkDropDown over a GtkStringList. notify::selected tells topside the chosen index. GTK
 * also emits it when the model is replaced; that comes while $rebuilding and is not the
 * user's, so it is not passed on.
 *
 * With icons, the drop-down draws its items through list-item factories: each a box of the
 * option's image, its label and the check GTK's own factory draws (measured: a label at xalign 0,
 * then object-select-symbolic, hidden on the button, in the list shown on the selected row only);
 * the button's item without the label while icon-only. A new pair goes in whenever the icons or
 * icon-only change, so the items bound already are drawn again. Once in, the pair stays: setting
 * a drop-down's factory back to null leaves it none, and nothing is drawn. Icon-only also gives
 * the drop-down's button (its documented CSS node, dropdown > button) the theme's flat class: no
 * border or background, the theme's own hover.
 */
class GtkDropdown extends TKDropdown
{
    use GtkNative {
        releaseNative as private releaseGtkNative;
    }

    /** Between an option's image and its label: a button's. */
    private const int ICON_SPACING = 6;

    private bool $rebuilding = false;

    /** The factories are this drop-down's, not GTK's own. */
    private bool $drawn = false;

    /** @var array<int, NativeImage> the list's checks, by the position they are bound to; dropped on unbind */
    private array $checks = [];

    protected function bootstrapNative(): void
    {
        $dropdown = NativeDropDown::newFromStrings($this->options());
        $dropdown->setSelected(self::position($this->selected()));
        $this->adoptNative($dropdown);

        $self = WeakReference::create($this);
        $this->connect($dropdown, 'notify::selected', static function () use ($self): void {
            $dropdown = $self->get();
            $dropdown?->checkSelected();
            if (! is_null($dropdown) && ! $dropdown->rebuilding) {
                $selected = $dropdown->dropdownNative()->getSelected();
                $dropdown->chosen($selected === GTK_INVALID_LIST_POSITION ? -1 : $selected);
            }
        });
    }

    protected function applyOptions(array $options, int $selected): void
    {
        $dropdown = $this->dropdownNative();

        $this->rebuilding = true;
        try {
            $dropdown->setModel(GtkStringList::new($options));
            $dropdown->setSelected(self::position($selected));
        } finally {
            $this->rebuilding = false;
        }
    }

    protected function applySelected(int $index): void
    {
        $this->dropdownNative()->setSelected($index);
    }

    protected function applyIcons(array $icons): void
    {
        $this->drawItems();
    }

    protected function applyIconOnly(bool $on): void
    {
        $button = $this->dropdownNative()->getFirstChild();
        $on ? $button?->addCssClass('flat') : $button?->removeCssClass('flat');
        $this->drawItems();
    }

    /**
     * GTK's own items until there are icons or it is icon-only; from then on the button's factory
     * and the list's.
     */
    private function drawItems(): void
    {
        if (! $this->drawn && $this->icons() === [] && ! $this->isIconOnly()) {
            return;
        }

        $this->drawn = true;
        $this->checks = [];
        $dropdown = $this->dropdownNative();
        $dropdown->setFactory($this->factory(! $this->isIconOnly(), false));
        $dropdown->setListFactory($this->factory(true, true));
    }

    /** The list's check on the selected row only, as GTK's own factory shows it. */
    private function checkSelected(): void
    {
        foreach ($this->checks as $position => $check) {
            $check->setOpacity($position === $this->dropdownNative()->getSelected() ? 1.0 : 0.0);
        }
    }

    /**
     * Items drawn as an option's image, then its label when $labelled, then GTK's check, shown
     * on the selected row when $checked (the list's) and hidden otherwise (the button's).
     */
    private function factory(bool $labelled, bool $checked): GtkSignalListItemFactory
    {
        $self = WeakReference::create($this);
        $factory = GtkSignalListItemFactory::new();
        $this->connect($factory, 'setup', static function (GObject $factory, GtkListItem $item) use ($labelled, $checked): void {
            $row = GtkBox::new(GtkOrientation::HORIZONTAL, self::ICON_SPACING);
            $row->append(NativeImage::newFromIconName(null));
            if ($labelled) {
                $label = NativeLabel::new(null);
                $label->setXalign(0.0);
                $row->append($label);
            }
            $check = NativeImage::newFromIconName('object-select-symbolic');
            $check->setVisible($checked);
            $row->append($check);
            $item->setChild($row);
        });
        $this->connect($factory, 'unbind', static function (GObject $factory, GtkListItem $item) use ($self, $checked): void {
            if ($checked && ! is_null($dropdown = $self->get())) {
                unset($dropdown->checks[$item->getPosition()]);
            }
        });
        $this->connect($factory, 'bind', static function (GObject $factory, GtkListItem $item) use ($self, $checked): void {
            $dropdown = $self->get();
            $option = $item->getItem();
            $row = $item->getChild();
            if (is_null($dropdown) || ! $option instanceof GtkStringObject || ! $row instanceof GtkBox) {
                return;
            }
            $image = $row->getFirstChild();
            $icon = $dropdown->iconOf($item->getPosition());
            if ($image instanceof NativeImage) {
                // The file when there is one, else the theme's icon, as on a button.
                if (! is_null($icon['file'] ?? null)) {
                    $image->setFromFile($icon['file']);
                } else {
                    $image->setFromIconName($icon['symbol'] ?? null);
                }
                $image->setVisible(! is_null($icon));
            }
            $label = $image?->getNextSibling();
            if ($label instanceof NativeLabel) {
                $label->setText($option->getString());
            }
            for ($check = $image; ! is_null($check?->getNextSibling()); $check = $check->getNextSibling());
            if ($checked && $check instanceof NativeImage) {
                $dropdown->checks[$item->getPosition()] = $check;
                $check->setOpacity($item->getPosition() === $dropdown->dropdownNative()->getSelected() ? 1.0 : 0.0);
            }
        });

        return $factory;
    }

    protected function releaseNative(): void
    {
        $this->checks = [];
        $this->releaseGtkNative();
    }

    private function dropdownNative(): NativeDropDown
    {
        /** @var NativeDropDown */
        return $this->native();
    }

    private static function position(int $index): int
    {
        return $index < 0 ? GTK_INVALID_LIST_POSITION : $index;
    }
}
