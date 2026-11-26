<?php

namespace Jovian\Toolkits\GTK;

use ReflectionException;
use RuntimeException;
use Voyager\Contracts\Core\FrameworkCore;
use Surface\Toolkits\Drivers\ToolkitAdapterDriver;
use Jovian\Toolkits\GTK\Menus\GtkMenuBar;
use Surface\Contracts\Toolkits\UI\TKRow;
use Surface\Contracts\Toolkits\UI\TKFixed;
use Surface\Contracts\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\UI\TKButton;
use Surface\Contracts\Toolkits\UI\TKColumn;
use Surface\Contracts\Toolkits\UI\TKToggle;
use Surface\Contracts\Toolkits\UI\TKCheckbox;
use Surface\Contracts\Toolkits\UI\TKDropdown;
use Surface\Contracts\Toolkits\UI\TKTextInput;
use Surface\Contracts\Toolkits\UI\TKLink;
use Surface\Contracts\Toolkits\UI\TKComboBox;
use Surface\Contracts\Toolkits\UI\TKDatepicker;
use Jovian\Toolkits\GTK\UI\GtkTextInput;
use Jovian\Toolkits\GTK\UI\GtkLink;
use Jovian\Toolkits\GTK\UI\GtkComboBox;
use Jovian\Toolkits\GTK\UI\GtkDatepicker;
use Jovian\Toolkits\GTK\UI\GtkRow;
use Jovian\Toolkits\GTK\UI\GtkFixed;
use Jovian\Toolkits\GTK\UI\GtkLabel;
use Jovian\Toolkits\GTK\UI\GtkButton;
use Jovian\Toolkits\GTK\UI\GtkColumn;
use Jovian\Toolkits\GTK\UI\GtkToggle;
use Jovian\Toolkits\GTK\UI\GtkCheckbox;
use Jovian\Toolkits\GTK\UI\GtkDropdown;
use Surface\Contracts\Toolkits\UI\TKTable;
use Jovian\Toolkits\GTK\UI\GtkTable;
use Surface\Contracts\Toolkits\UI\TKListView;
use Jovian\Toolkits\GTK\UI\GtkListView;
use Surface\Contracts\Toolkits\UI\TKCollectionView;
use Jovian\Toolkits\GTK\UI\GtkCollectionView;
use Surface\Contracts\Toolkits\UI\TKTree;
use Jovian\Toolkits\GTK\UI\GtkTree;
use Surface\Contracts\Toolkits\UI\TKTextArea;
use Jovian\Toolkits\GTK\UI\GtkTextArea;
use Surface\Contracts\Toolkits\UI\TKWebView;
use Jovian\Toolkits\GTK\UI\GtkWebView;
use Surface\Contracts\Toolkits\UI\TKScrollView;
use Jovian\Toolkits\GTK\UI\GtkScrollView;
use Surface\Contracts\Toolkits\UI\TKTabView;
use Jovian\Toolkits\GTK\UI\GtkTabView;
use Surface\Contracts\Toolkits\UI\TKSplitView;
use Jovian\Toolkits\GTK\UI\GtkSplitView;
use Surface\Contracts\Toolkits\UI\TKGrid;
use Jovian\Toolkits\GTK\UI\GtkGrid;
use Surface\Contracts\Toolkits\UI\TKSeparator;
use Jovian\Toolkits\GTK\UI\GtkSeparator;
use Surface\Contracts\Toolkits\UI\TKProgressBar;
use Jovian\Toolkits\GTK\UI\GtkProgressBar;
use Surface\Contracts\Toolkits\UI\TKSpinner;
use Jovian\Toolkits\GTK\UI\GtkSpinner;
use Surface\Contracts\Toolkits\UI\TKSlider;
use Jovian\Toolkits\GTK\UI\GtkSlider;
use Surface\Contracts\Toolkits\UI\TKToggleButton;
use Jovian\Toolkits\GTK\UI\GtkToggleButton;
use Surface\Contracts\Toolkits\UI\TKSegmentedControl;
use Jovian\Toolkits\GTK\UI\GtkSegmentedControl;
use Surface\Contracts\Toolkits\UI\TKImage;
use Jovian\Toolkits\GTK\UI\GtkImage;
use Surface\Contracts\Toolkits\UI\TKOverlay;
use Jovian\Toolkits\GTK\UI\GtkOverlay;

class GtkAdapterDriver extends ToolkitAdapterDriver
{
    protected string $window = GtkWindow::class;

    protected ?string $menu_bar = GtkMenuBar::class;

    protected array $primitives = [
        TKColumn::class => GtkColumn::class,
        TKRow::class => GtkRow::class,
        TKFixed::class => GtkFixed::class,
        TKOverlay::class => GtkOverlay::class,
        TKLabel::class => GtkLabel::class,
        TKButton::class => GtkButton::class,
        TKCheckbox::class => GtkCheckbox::class,
        TKToggle::class => GtkToggle::class,
        TKDropdown::class => GtkDropdown::class,
        TKTextInput::class => GtkTextInput::class,
        TKLink::class => GtkLink::class,
        TKComboBox::class => GtkComboBox::class,
        TKDatepicker::class => GtkDatepicker::class,
        TKSeparator::class => GtkSeparator::class,
        TKProgressBar::class => GtkProgressBar::class,
        TKSpinner::class => GtkSpinner::class,
        TKSlider::class => GtkSlider::class,
        TKToggleButton::class => GtkToggleButton::class,
        TKSegmentedControl::class => GtkSegmentedControl::class,
        TKImage::class => GtkImage::class,
        TKScrollView::class => GtkScrollView::class,
        TKTabView::class => GtkTabView::class,
        TKSplitView::class => GtkSplitView::class,
        TKGrid::class => GtkGrid::class,
        TKTable::class => GtkTable::class,
        TKListView::class => GtkListView::class,
        TKCollectionView::class => GtkCollectionView::class,
        TKTree::class => GtkTree::class,
        TKTextArea::class => GtkTextArea::class,
        TKWebView::class => GtkWebView::class,
    ];

    public function __construct(
        FrameworkCore $app
    ) {
        if (!extension_loaded('gtk')) {
            throw new RuntimeException("ext-gtk missing. Install it with 'pie install php-io-extensions/gtk'");
        }

        // GTK runs on NSApplication on macOS: only AppKit brings the process forward on present.
        if (device_os_family() === 'mac' && ! extension_loaded('appkit')) {
            throw new RuntimeException("GTK on macOS requires ext-appkit. Install it with 'pie install php-io-extensions/appkit'");
        }

        parent::__construct($app);
    }

    /**
     * @throws ReflectionException
     */
    protected function instanceSession(): void
    {
        $this->session ??= new GtkSession(
            $this->app,
            $this->applicationId(),
            (bool) config('gtk.unique_primary_instance', false),
        );
    }

    /**
     * @throws ReflectionException
     */
    protected function applicationId(): string
    {
        $id = config('gtk.application_id');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException("config/gtk.php has no application_id; add 'application_id' => env('APP_ID', 'com.venusian.app').");
        }

        return $id;
    }
}
