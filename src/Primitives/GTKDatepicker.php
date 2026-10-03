<?php

namespace Jovian\Toolkits\GTK\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKDatepicker;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A date picker over GtkCalendar. Dates are calendar days (years 1-9999, GDateTime's range):
 * the picker reads and writes the year, month and day, and reports midnight of that day in
 * PHP's default timezone. A calendar always shows a day: with no date it opens on today (and a
 * null date set later leaves it where it is), while date() stays null until the user picks a
 * day or code sets one. Clicking a day emits day-selected; the month and year arrows (and the
 * scroll wheel) move the selected day with only notify::day/month/year. Any of them schedules
 * one idle check, which posts DateChanged once with the settled day when it differs from date().
 */
class GTKDatepicker extends TKDatepicker implements GTKView
{
    use GTKPrimitive;

    protected ?int $sync_source = null;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param DateTimeImmutable|null $date
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?DateTimeImmutable $date)
    {
        if (! is_null($date)) {
            self::guardYear($date);
        }
        parent::__construct($name, $window, $parent, $placement, $date);
        $this->adoptNative(\GtkCalendar::new());
        $this->applyDate($date);
        foreach (['day-selected', 'notify::day', 'notify::month', 'notify::year'] as $signal) {
            $this->connect($this->native, $signal, fn () => $this->scheduleSync());
        }
    }

    /**
     * @param DateTimeImmutable|null $date
     * @return $this
     * @throws WindowException When the year is outside 1-9999; nothing changes.
     */
    public function setDate(?DateTimeImmutable $date): static
    {
        $this->live();
        if (! is_null($date)) {
            self::guardYear($date);
        }

        return parent::setDate($date);
    }

    /**
     * One idle check per burst of calendar notifications: a month step changes the day and the
     * month in turn, and only the settled day is the user's.
     * @return void
     */
    protected function scheduleSync(): void
    {
        $this->sync_source ??= g_idle_add(function (): bool {
            $this->sync_source = null;
            $this->syncFromCalendar();

            return G_SOURCE_REMOVE;
        });
    }

    protected function syncFromCalendar(): void
    {
        $day = $this->widgetCalendar()->getDate();
        $picked = sprintf('%04d-%02d-%02d', $day->getYear(), $day->getMonth(), $day->getDayOfMonth());
        // A code-driven date is stored before it is written, so its own notifications find it equal.
        if ($this->date?->format('Y-m-d') === $picked) {
            return;
        }
        $this->nativeDateChanged(new DateTimeImmutable("{$picked} 00:00:00"));
        $this->post(new DateChanged($this->window->name(), $this->path(), $this->uuid, $this->date));
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->sync_source)) {
            g_source_remove($this->sync_source);
            $this->sync_source = null;
        }
    }

    /**
     * @param DateTimeImmutable $date
     * @return void
     * @throws WindowException
     */
    protected static function guardYear(DateTimeImmutable $date): void
    {
        $year = (int) $date->format('Y');
        if ($year < 1 || $year > 9999) {
            throw new WindowException("A date picker shows years 1-9999, got {$year}.");
        }
    }

    protected function applyDate(?DateTimeImmutable $date): void
    {
        if (is_null($date)) {
            return;
        }
        $day = \GDateTime::newLocal((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'), 0, 0, 0.0);
        $this->quietly(fn () => $this->widgetCalendar()->selectDay($day));
    }

    protected function widgetCalendar(): \GtkCalendar
    {
        /** @var \GtkCalendar */
        return $this->native;
    }
}
