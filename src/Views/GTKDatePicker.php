<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkCalendar as GtkCalendarWidget;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Bindings\Gtk\Runtime\Bridge;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\DatePicker;
use Surface\NativeWindows\Windowable;

/**
 * A Surface day picker over a GtkCalendar. Month is 0-based on the
 * widget and 1-based on the contract — the translation lives here.
 * day-selected fires for programmatic setDay too, so applying keeps
 * Surface's own setDate() from echoing back as mail.
 */
class GTKDatePicker extends DatePicker
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        ?string $date,
        public readonly GtkCalendarWidget $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $date);

        if (! is_null($this->year) && ! is_null($this->month) && ! is_null($this->day)) {
            $this->writeNative($this->year, $this->month, $this->day);
        }

        Bridge::connect($native->handle, 'day-selected', function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireChanged(
                    $this->native->getYear(),
                    $this->native->getMonth() + 1,
                    $this->native->getDay(),
                );
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

    protected function applyDate(int $year, int $month, int $day): void
    {
        $this->applying = true;
        $this->writeNative($year, $month, $day);
        $this->applying = false;
    }

    protected function writeNative(int $year, int $month, int $day): void
    {
        $this->native->setYear($year);
        $this->native->setMonth($month - 1);
        $this->native->setDay($day);
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
    }

    protected function applyBackground(Color $color): void {}
}
