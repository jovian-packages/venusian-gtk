<?php

namespace Jovian\Toolkits\GTK\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\PrimitiveFactory;
use Surface\Contracts\Windows\Primitives\TKButton;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use Surface\Contracts\Windows\Primitives\TKCheckbox;
use Surface\Contracts\Windows\Primitives\TKColumn;
use Surface\Contracts\Windows\Primitives\TKDatepicker;
use Surface\Contracts\Windows\Primitives\TKDropdown;
use Surface\Contracts\Windows\Primitives\TKFixed;
use Surface\Contracts\Windows\Primitives\TKGrid;
use Surface\Contracts\Windows\Primitives\TKImage;
use Surface\Contracts\Windows\Primitives\TKLabel;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup as GroupContract;
use Surface\Contracts\Windows\Primitives\TKProgressBar;
use Surface\Contracts\Windows\Primitives\TKRow;
use Surface\Contracts\Windows\Primitives\TKScrollView;
use Surface\Contracts\Windows\Primitives\TKSeparator;
use Surface\Contracts\Windows\Primitives\TKSlider;
use Surface\Contracts\Windows\Primitives\TKSpinner;
use Surface\Contracts\Windows\Primitives\TKTable;
use Surface\Contracts\Windows\Primitives\TKTextArea;
use Surface\Contracts\Windows\Primitives\TKTextInput;
use Surface\Contracts\Windows\Primitives\TKToggle;
use Surface\Contracts\Windows\Primitives\TKToggleButton;
use Surface\Contracts\Windows\Primitives\TKVideo;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;

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

    public function mintDatepicker(GroupContract $host, string $name, ?DateTimeImmutable $date): TKDatepicker
    {
        return new GTKDatepicker($name, $this->window, self::parent($host), self::placement($host), $date);
    }

    public function mintTable(GroupContract $host, string $name, array $columns, array $rows): TKTable
    {
        return new GTKTable($name, $this->window, self::parent($host), self::placement($host), $columns, $rows);
    }

    public function mintVideo(GroupContract $host, string $name, ?string $file): TKVideo
    {
        return new GTKVideo($name, $this->window, self::parent($host), self::placement($host), $file);
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

    /**
     * @param GroupContract|null $host
     * @return Placement
     * @throws WindowException When the host needs at() and none is pending.
     */
    protected static function placement(?GroupContract $host): Placement
    {
        return $host?->takePlacement() ?? Placement::next();
    }

    /**
     * @param GroupContract|null $host
     * @return TKPrimitiveGroup|null
     * @throws WindowException When the host is not a Surface container.
     */
    protected static function parent(?GroupContract $host): ?TKPrimitiveGroup
    {
        if (is_null($host) || $host instanceof TKPrimitiveGroup) {
            return $host;
        }

        throw new WindowException("'{$host->path()}' is a ".get_debug_type($host).', not a Surface container.');
    }
}
