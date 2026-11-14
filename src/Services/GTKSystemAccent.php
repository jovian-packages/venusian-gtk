<?php

namespace Jovian\Toolkits\GTK\Services;

use GSettings;
use GSettingsSchemaSource;
use Surface\Contracts\Toolkits\Services\SystemAccent;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\NutsAndBolts\Color;

/**
 * GNOME's accent (org.gnome.desktop.interface accent-color, GNOME 47 and later), as libadwaita draws it:
 * the colours are Raven's table (look.c:648-664). null when the desktop has no such key.
 */
final class GTKSystemAccent implements SystemAccent
{
    private const string SCHEMA = 'org.gnome.desktop.interface';

    private const string KEY = 'accent-color';

    /** @var array<string, string> */
    private const array ACCENTS = [
        'blue' => '#3584e4', 'teal' => '#2190a4', 'green' => '#3a944a', 'yellow' => '#c88800', 'orange' => '#ed5b00',
        'red' => '#e62d42', 'pink' => '#d56199', 'purple' => '#9141ac', 'slate' => '#6f8396',
    ];

    /** @throws ToolkitRuntimeException When the key holds a name GNOME does not give an accent. */
    public function color(): ?Color
    {
        $schema = GSettingsSchemaSource::getDefault()?->lookup(self::SCHEMA, true);
        if ($schema === null || ! $schema->hasKey(self::KEY)) {
            return null;
        }
        $name = GSettings::new(self::SCHEMA)->getString(self::KEY);
        $hex = self::ACCENTS[strtolower((string) $name)]
            ?? throw new ToolkitRuntimeException(sprintf("%s %s is '%s'; GNOME names %s.", self::SCHEMA, self::KEY, $name, implode(', ', array_keys(self::ACCENTS))));

        return Color::hex($hex);
    }
}
