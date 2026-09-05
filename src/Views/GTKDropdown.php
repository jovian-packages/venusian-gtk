<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkDropDown as GtkDropDownWidget;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkStringList;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Bindings\Gtk\Runtime\Bridge;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Dropdown;
use Surface\NativeWindows\Windowable;

/**
 * A Surface dropdown over a GtkDropDown backed by a GtkStringList,
 * listening on notify::selected so the pick is settled when read. The
 * applying flag keeps Surface's own select() from echoing back as mail.
 */
class GTKDropdown extends Dropdown
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        array $options,
        int $selected,
        public readonly GtkDropDownWidget $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $options, $selected);

        Bridge::connect($native->handle, 'notify::selected', function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireSelected($this->native->getSelected());
            }
        });
    }

    protected function widget(): GtkWidget
    {
        return $this->native;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyOptions(array $options, int $selected): void
    {
        $this->applying = true;
        $this->native->setModel(GtkStringList::new($options));
        if ($selected >= 0) {
            $this->native->setSelected($selected);
        }
        $this->applying = false;
    }

    protected function applySelected(int $selected): void
    {
        if ($selected < 0) {
            return;
        }

        $this->applying = true;
        $this->native->setSelected($selected);
        $this->applying = false;
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
    }

    /**
     * The button face is the theme's — the colour is ignored rather than
     * fought through CSS this slice.
     */
    protected function applyBackground(Color $color): void {}
}
