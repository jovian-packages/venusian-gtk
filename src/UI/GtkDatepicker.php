<?php

namespace Jovian\Toolkits\GTK\UI;

use GDateTime;
use GtkPopover;
use GtkCalendar;
use WeakReference;
use GtkMenuButton;
use DateTimeImmutable;
use GtkLabel as NativeLabel;
use Surface\Toolkits\UI\TKDatepicker;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A menu button showing the day, with a GtkCalendar in its popover: the size of the other
 * toolkits' pickers. The calendar's month and year arrows change the day, and month, day and
 * year in turn, so one idle check per burst reads the day it settled on.
 */
class GtkDatepicker extends TKDatepicker
{
    use GtkNative {
        releaseNative as private releaseWidget;
    }

    private GtkCalendar $calendar;

    private NativeLabel $shown;

    private ?int $sync = null;

    protected function bootstrapNative(): void
    {
        $this->calendar = GtkCalendar::new();
        $this->shown = NativeLabel::new('');

        $popover = GtkPopover::new();
        $popover->setChild($this->calendar);

        $button = GtkMenuButton::new();
        $button->setChild($this->shown);
        $button->setPopover($popover);
        $this->adoptNative($button);
        $this->applyDate($this->date());

        $self = WeakReference::create($this);
        $changed = static function () use ($self): void {
            $self->get()?->syncSoon();
        };
        foreach (['day-selected', 'notify::day', 'notify::month', 'notify::year'] as $signal) {
            $this->connect($this->calendar, $signal, $changed);
        }
    }

    protected function applyDate(?DateTimeImmutable $date): void
    {
        $day = $this->shownDay();
        $local = GDateTime::newLocal((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'), 12, 0, 0.0);
        if (! is_null($local)) {
            $this->calendar->selectDay($local);
        }
        $this->shown->setText($day->format('Y-m-d'));
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->sync)) {
            g_source_remove($this->sync);
            $this->sync = null;
        }
        /** @var GtkMenuButton $button */
        $button = $this->native();
        $button->popdown();
        $this->releaseWidget();
    }

    private function syncSoon(): void
    {
        if ($this->isWriting() || $this->isRemoved() || ! is_null($this->sync)) {
            return;
        }

        $self = WeakReference::create($this);
        $this->sync = g_idle_add(static function () use ($self): bool {
            $self->get()?->syncNow();

            return G_SOURCE_REMOVE;
        });
    }

    private function syncNow(): void
    {
        $this->sync = null;
        if ($this->isRemoved()) {
            return;
        }

        $day = $this->calendar->getDate();
        $this->picked($day->getYear(), $day->getMonth(), $day->getDayOfMonth());
        $this->shown->setText($this->shownDay()->format('Y-m-d'));
    }
}
