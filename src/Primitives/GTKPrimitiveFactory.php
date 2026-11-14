<?php

namespace Jovian\Toolkits\GTK\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Menus\ContextMenu;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\Primitives\PrimitiveFactory;
use Surface\Contracts\Toolkits\Primitives\TKButton;
use Surface\Contracts\Toolkits\Primitives\TKCanvas;
use Surface\Contracts\Toolkits\Primitives\TKCheckbox;
use Surface\Contracts\Toolkits\Primitives\TKColumn;
use Surface\Contracts\Toolkits\Primitives\TKComboBox;
use Surface\Contracts\Toolkits\Primitives\TKDatepicker;
use Surface\Contracts\Toolkits\Primitives\TKDropdown;
use Surface\Contracts\Toolkits\Primitives\TKFixed;
use Surface\Contracts\Toolkits\Primitives\TKGrid;
use Surface\Contracts\Toolkits\Primitives\TKImage;
use Surface\Contracts\Toolkits\Primitives\TKLabel;
use Surface\Contracts\Toolkits\Primitives\TKLink;
use Surface\Contracts\Toolkits\Primitives\TKMenuButton;
use Surface\Contracts\Toolkits\Primitives\TKPrimitiveGroup as GroupContract;
use Surface\Contracts\Toolkits\Primitives\TKProgressBar;
use Surface\Contracts\Toolkits\Primitives\TKRow;
use Surface\Contracts\Toolkits\Primitives\TKScrollBar;
use Surface\Contracts\Toolkits\Primitives\TKScrollView;
use Surface\Contracts\Toolkits\Primitives\TKSeparator;
use Surface\Contracts\Toolkits\Primitives\TKSlider;
use Surface\Contracts\Toolkits\Primitives\TKSpinner;
use Surface\Contracts\Toolkits\Primitives\TKSplitView;
use Surface\Contracts\Toolkits\Primitives\TKTabView;
use Surface\Contracts\Toolkits\Primitives\TKTable;
use Surface\Contracts\Toolkits\Primitives\TKSegmentedControl;
use Surface\Contracts\Toolkits\Primitives\TKCollectionView;
use Surface\Contracts\Toolkits\Primitives\TKListView;
use Surface\Contracts\Toolkits\Primitives\TKTextArea;
use Surface\Contracts\Toolkits\Primitives\TKTextInput;
use Surface\Contracts\Toolkits\Primitives\TKToggle;
use Surface\Contracts\Toolkits\Primitives\TKToggleButton;
use Surface\Contracts\Toolkits\Primitives\TKTree;
use Surface\Contracts\Toolkits\Primitives\TKVideo;
use Surface\Contracts\Toolkits\Primitives\TKWebView;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * Mints GTK primitives for one window. Each mint takes the host's pending placement (or the
 * next slot for the window's content container) and builds the concrete and its widget.
 * Every kind exists on GTK; a video throws where GTK has no media backend.
 */
class GTKPrimitiveFactory implements PrimitiveFactory
{
    public function __construct(
        protected readonly GTKWindow $window,
    ) {}

    public function mintLabel(GroupContract $host, string $name, string $text): TKLabel
    {
        return new GTKLabel($name, $this->window, self::parent($host), self::placement($host), $text);
    }

    public function mintLink(GroupContract $host, string $name, string $text, string $url): TKLink
    {
        return new GTKLink($name, $this->window, self::parent($host), self::placement($host), $text, $url);
    }

    public function mintButton(GroupContract $host, string $name, string $label): TKButton
    {
        return new GTKButton($name, $this->window, self::parent($host), self::placement($host), $label);
    }

    public function mintImage(GroupContract $host, string $name, ?string $file): TKImage
    {
        return new GTKImage($name, $this->window, self::parent($host), self::placement($host), $file);
    }

    public function mintCanvas(GroupContract $host, string $name): TKCanvas
    {
        return new GTKCanvas($name, $this->window, self::parent($host), self::placement($host));
    }

    public function mintSeparator(GroupContract $host, string $name, bool $horizontal): TKSeparator
    {
        return new GTKSeparator($name, $this->window, self::parent($host), self::placement($host), $horizontal);
    }

    public function mintSpinner(GroupContract $host, string $name): TKSpinner
    {
        return new GTKSpinner($name, $this->window, self::parent($host), self::placement($host));
    }

    public function mintProgressBar(GroupContract $host, string $name, ?float $fraction): TKProgressBar
    {
        return new GTKProgressBar($name, $this->window, self::parent($host), self::placement($host), $fraction);
    }

    public function mintTextInput(GroupContract $host, string $name, string $value, ?string $placeholder, bool $secret): TKTextInput
    {
        return new GTKTextInput($name, $this->window, self::parent($host), self::placement($host), $value, $placeholder, $secret);
    }

    public function mintTextArea(GroupContract $host, string $name, string $value): TKTextArea
    {
        return new GTKTextArea($name, $this->window, self::parent($host), self::placement($host), $value);
    }

    public function mintCheckbox(GroupContract $host, string $name, string $label, bool $checked): TKCheckbox
    {
        return new GTKCheckbox($name, $this->window, self::parent($host), self::placement($host), $label, $checked);
    }

    public function mintToggle(GroupContract $host, string $name, bool $on): TKToggle
    {
        return new GTKToggle($name, $this->window, self::parent($host), self::placement($host), $on);
    }

    public function mintToggleButton(GroupContract $host, string $name, string $label, bool $pressed): TKToggleButton
    {
        return new GTKToggleButton($name, $this->window, self::parent($host), self::placement($host), $label, $pressed);
    }

    public function mintSlider(GroupContract $host, string $name, float $min, float $max, float $value): TKSlider
    {
        return new GTKSlider($name, $this->window, self::parent($host), self::placement($host), $min, $max, $value);
    }

    public function mintDropdown(GroupContract $host, string $name, array $options, int $selected): TKDropdown
    {
        return new GTKDropdown($name, $this->window, self::parent($host), self::placement($host), $options, $selected);
    }

    public function mintScrollBar(GroupContract $host, string $name, bool $vertical): TKScrollBar
    {
        return new GTKScrollBar($name, $this->window, self::parent($host), self::placement($host), $vertical);
    }

    public function mintComboBox(GroupContract $host, string $name, string $value, array $options): TKComboBox
    {
        return new GTKComboBox($name, $this->window, self::parent($host), self::placement($host), $value, $options);
    }

    public function mintMenuButton(GroupContract $host, string $name, string $label, ContextMenu $menu): TKMenuButton
    {
        return new GTKMenuButton($name, $this->window, self::parent($host), self::placement($host), $label, $menu);
    }

    public function mintDatepicker(GroupContract $host, string $name, ?DateTimeImmutable $date): TKDatepicker
    {
        return new GTKDatepicker($name, $this->window, self::parent($host), self::placement($host), $date);
    }

    public function mintTable(GroupContract $host, string $name, array $columns, array $rows): TKTable
    {
        return new GTKTable($name, $this->window, self::parent($host), self::placement($host), $columns, $rows);
    }

    public function mintSegmentedControl(GroupContract $host, string $name, array $segments, int $selected): TKSegmentedControl
    {
        return new GTKSegmentedControl($name, $this->window, self::parent($host), self::placement($host), $segments, $selected);
    }

    public function mintCollectionView(GroupContract $host, string $name, array $items): TKCollectionView
    {
        return new GTKCollectionView($name, $this->window, self::parent($host), self::placement($host), $items);
    }

    public function mintListView(GroupContract $host, string $name, array $items): TKListView
    {
        return new GTKListView($name, $this->window, self::parent($host), self::placement($host), $items);
    }

    public function mintTree(GroupContract $host, string $name, array $columns, array $nodes): TKTree
    {
        return new GTKTree($name, $this->window, self::parent($host), self::placement($host), $columns, $nodes);
    }

    public function mintVideo(GroupContract $host, string $name, ?string $file): TKVideo
    {
        return new GTKVideo($name, $this->window, self::parent($host), self::placement($host), $file);
    }

    /**
     * GTK 4's web view is WebKitGTK 6 (Linux), which ext-gtk does not bind; on macOS there is none.
     * @throws ToolkitRuntimeException
     */
    public function mintWebView(GroupContract $host, string $name): TKWebView
    {
        throw new ToolkitRuntimeException('TKWebView needs WebKitGTK 6, which ext-gtk does not bind; on macOS GTK 4 has no web engine.');
    }

    public function mintColumn(?GroupContract $host, string $name, int $spacing, int $padding): TKColumn
    {
        return new GTKColumn($name, $this->window, self::parent($host), self::placement($host), $spacing, $padding);
    }

    public function mintRow(?GroupContract $host, string $name, int $spacing, int $padding): TKRow
    {
        return new GTKRow($name, $this->window, self::parent($host), self::placement($host), $spacing, $padding);
    }

    public function mintGrid(?GroupContract $host, string $name, int $spacing, int $padding): TKGrid
    {
        return new GTKGrid($name, $this->window, self::parent($host), self::placement($host), $spacing, $padding);
    }

    public function mintFixed(?GroupContract $host, string $name): TKFixed
    {
        return new GTKFixed($name, $this->window, self::parent($host), self::placement($host));
    }

    public function mintScrollView(GroupContract $host, string $name): TKScrollView
    {
        return new GTKScrollView($name, $this->window, self::parent($host), self::placement($host));
    }

    public function mintSplit(GroupContract $host, string $name, bool $horizontal): TKSplitView
    {
        return new GTKSplitView($name, $this->window, self::parent($host), self::placement($host), $horizontal);
    }

    public function mintTabs(GroupContract $host, string $name): TKTabView
    {
        return new GTKTabView($name, $this->window, self::parent($host), self::placement($host));
    }

    /**
     * @param GroupContract|null $host
     * @return Placement
     * @throws ToolkitRuntimeException When the host needs at() and none is pending.
     */
    protected static function placement(?GroupContract $host): Placement
    {
        return $host?->takePlacement() ?? Placement::next();
    }

    /**
     * @param GroupContract|null $host
     * @return TKPrimitiveGroup|null
     * @throws ToolkitRuntimeException When the host is not a Surface container.
     */
    protected static function parent(?GroupContract $host): ?TKPrimitiveGroup
    {
        if (is_null($host) || $host instanceof TKPrimitiveGroup) {
            return $host;
        }

        throw new ToolkitRuntimeException("'{$host->path()}' is a ".get_debug_type($host).', not a Surface container.');
    }
}
