<?php

namespace Jovian\Toolkits\GTK;

use GtkAboutDialog;
use GtkSettings;
use Surface\Contracts\Toolkits\Styling\ColorScheme;
use Surface\Toolkits\ToolkitSession;
use Surface\Toolkits\Menus\AboutPanel;
use Voyager\Contracts\Core\FrameworkCore;

/**
 * @property Gtk|null $application
 */
class GtkSession extends ToolkitSession
{
    /** GTK's About dialog while it is open. */
    protected ?GtkAboutDialog $about_box = null;

    public function __construct(
        FrameworkCore $app,
        protected readonly string $application_id,
        protected readonly bool $unique = false,
    ) {
        parent::__construct($app);
    }

    protected function bootstrapToolkitLibrary(): void
    {
        $this->application = new Gtk($this->application_id, $this->unique);
    }

    /**
     * GTK has one switch for it: the application prefers the dark variant of its theme or the
     * light one. The system's choice is left as GTK read it.
     */
    protected function applyColorScheme(ColorScheme $scheme): void
    {
        if ($scheme === ColorScheme::SYSTEM) {
            return;
        }

        g_object_set_property(GtkSettings::getDefault(), 'gtk-application-prefer-dark-theme', $scheme === ColorScheme::DARK);
    }

    public function application(): Gtk
    {
        return $this->application;
    }

    /**
     * GTK's About dialog with the application's fields, not modal. Asked again while it is
     * open, the same dialog comes forward with the new fields. Credits are its comments: the
     * dialog's own credits are a list of names.
     */
    public function showAbout(AboutPanel $panel, ?\GtkWindow $over = null): GtkAboutDialog
    {
        $box = $this->aboutDialog($panel, $over);
        $box->present();

        return $box;
    }

    /**
     * The About dialog with these fields, not shown.
     */
    public function aboutDialog(AboutPanel $panel, ?\GtkWindow $over = null): GtkAboutDialog
    {
        if (is_null($this->about_box)) {
            $this->about_box = GtkAboutDialog::new();
            $this->about_box->setModal(false);
            g_signal_connect($this->about_box, 'close-request', function (): bool {
                $this->about_box = null;

                return false;    // GTK destroys it
            });
        }

        $box = $this->about_box;
        $box->setTransientFor($over);
        $box->setProgramName($panel->name);
        $box->setVersion($panel->version);
        $box->setCopyright($panel->copyright);
        $box->setComments($panel->credits);

        return $box;
    }

    public function aboutBox(): ?GtkAboutDialog
    {
        return $this->about_box;
    }
}
