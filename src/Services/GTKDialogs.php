<?php

namespace Jovian\Toolkits\GTK\Services;

use GAsyncResult;
use GCancellable;
use GError;
use GFile;
use GListModel;
use GListStore;
use GtkAlertDialog;
use GtkFileDialog;
use GtkFileFilter;
use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Services\Alert;
use Surface\Contracts\Windows\Services\DialogCapability;
use Surface\Contracts\Windows\Services\OpenRequest;
use Surface\Contracts\Windows\Services\SaveRequest;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Windows\Services\ToolkitDialogs;
use Voyager\Contracts\Signals\NamedSignal;
use WeakReference;

/**
 * GtkAlertDialog and GtkFileDialog, modal to their parent. Each request holds a GCancellable: the
 * parent's close cancels it, and the late callback finds the id gone. An alert with a style, a
 * suppression box or a title is a GTKComposedAlert: GtkAlertDialog has none of the three.
 */
final class GTKDialogs extends ToolkitDialogs
{
    /**
     * The open dialogs by request id: the native and its cancellable (none for a composed alert).
     * @var array<string, array{native: GtkAlertDialog|GtkFileDialog|GTKComposedAlert, cancellable: ?GCancellable}>
     */
    private array $open = [];

    public function __construct(GTKBridgeDriver $driver, private readonly GTKSession $session)
    {
        parent::__construct($driver);
    }

    /** GtkFileDialog has no hidden-files switch. */
    public function supports(DialogCapability $capability): bool
    {
        return $capability === DialogCapability::AlertTitle;
    }

    /** The native of an open request, or null. */
    public function native(string $id): GtkAlertDialog|GtkFileDialog|GTKComposedAlert|null
    {
        return $this->open[$id]['native'] ?? null;
    }

    protected function showAlert(string $id, ToolkitWindow $parent, Alert $alert): void
    {
        if (! is_null($alert->style) || ! is_null($alert->suppression) || ! is_null($alert->title)) {
            $weak = WeakReference::create($this);
            $composed = new GTKComposedAlert($this->parentWindow($parent), $alert, static function (int $button, bool $suppressed) use ($weak, $id): void {
                $weak->get()?->ended($id, $button, $suppressed);
            });
            $this->open[$id] = ['native' => $composed, 'cancellable' => null];

            return;
        }
        // gtk_alert_dialog_new() is a printf and is not bound: the message is a property, never a format.
        /** @var GtkAlertDialog $dialog */
        $dialog = g_object_new_with_properties('GtkAlertDialog', ['message'], [$alert->message]);
        if (! is_null($alert->detail)) {
            $dialog->setDetail($alert->detail);
        }
        $dialog->setButtons(array_map(self::buttonLabel(...), $alert->buttons));
        $dialog->setDefaultButton($alert->default);
        $dialog->setCancelButton($alert->cancel);
        $dialog->setModal(true);
        $cancellable = GCancellable::new();
        $this->open[$id] = ['native' => $dialog, 'cancellable' => $cancellable];
        $weak = WeakReference::create($this);
        $cancel = $alert->cancel;
        $dialog->choose($this->parentWindow($parent), $cancellable, static function (GtkAlertDialog $dialog, GAsyncResult $result) use ($weak, $id, $cancel): void {
            try {
                $button = $dialog->chooseFinish($result);
            } catch (GError) {
                $button = $cancel;
            }
            $weak->get()?->ended($id, $button);
        });
    }

    protected function showOpen(string $id, ToolkitWindow $parent, OpenRequest $request): void
    {
        $dialog = $this->fileDialog($request->title, $request->directory, $request->filters);
        [$cancellable, $callback] = $this->request($id, $dialog);
        $window = $this->parentWindow($parent);
        match (true) {
            $request->multiple => $dialog->openMultiple($window, $cancellable, $callback(static fn (GtkFileDialog $d, GAsyncResult $r): array => self::paths($d->openMultipleFinish($r)))),
            $request->folders => $dialog->selectFolder($window, $cancellable, $callback(static fn (GtkFileDialog $d, GAsyncResult $r): array => [$d->selectFolderFinish($r)->getPath()])),
            default => $dialog->open($window, $cancellable, $callback(static fn (GtkFileDialog $d, GAsyncResult $r): array => [$d->openFinish($r)->getPath()])),
        };
    }

    protected function showSave(string $id, ToolkitWindow $parent, SaveRequest $request): void
    {
        $dialog = $this->fileDialog($request->title, $request->directory, $request->filters);
        if (! is_null($request->name)) {
            $dialog->setInitialName($request->name);
        }
        [$cancellable, $callback] = $this->request($id, $dialog);
        $dialog->save($this->parentWindow($parent), $cancellable, $callback(static fn (GtkFileDialog $d, GAsyncResult $r): array => [$d->saveFinish($r)->getPath()]));
    }

    /** A button label as written: GtkAlertDialog's buttons read one _ as a mnemonic marker, two as an _. */
    public static function buttonLabel(string $label): string
    {
        return str_replace('_', '__', $label);
    }

    protected function dismiss(string $id): void
    {
        $request = $this->open[$id] ?? null;
        unset($this->open[$id]);
        if (is_null($request)) {
            return;
        }
        if ($request['native'] instanceof GTKComposedAlert) {
            $request['native']->dismiss();
        }
        $request['cancellable']?->cancel();
    }

    protected function post(NamedSignal $mail): void
    {
        $this->session->post($mail);
    }

    /**
     * The native answered: let go of it, then answer. An id the parent's close already ended answers nothing.
     * @param int|list<?string> $value
     */
    private function ended(string $id, int|array $value, bool $suppressed = false): void
    {
        if (! isset($this->open[$id])) {
            return;
        }
        unset($this->open[$id]);
        is_int($value)
            ? $this->answerAlert($id, $value, $suppressed)
            : $this->answerFiles($id, array_values(array_filter($value, static fn (?string $path): bool => ! is_null($path))));
    }

    /** @param array<string, list<string>> $filters one GtkFileFilter each, in order */
    private function fileDialog(?string $title, ?string $directory, array $filters): GtkFileDialog
    {
        $dialog = GtkFileDialog::new();
        if (! is_null($title)) {
            $dialog->setTitle($title);
        }
        if ($filters !== []) {
            $store = GListStore::new('GtkFileFilter');
            foreach ($filters as $name => $patterns) {
                $filter = GtkFileFilter::new();
                $filter->setName($name);
                foreach ($patterns as $pattern) {
                    $filter->addPattern($pattern);
                }
                $store->append($filter);
            }
            $dialog->setFilters($store);
        }
        $folder = self::folder($directory);
        if (! is_null($folder)) {
            $dialog->setInitialFolder(GFile::newForPath($folder));
        }
        $dialog->setModal(true);

        return $dialog;
    }

    /**
     * Record the request and give the callback maker: a finish that throws GError (dismissed,
     * cancelled, failed) answers [].
     * @return array{GCancellable, \Closure(\Closure(GtkFileDialog, GAsyncResult): list<?string>): \Closure}
     */
    private function request(string $id, GtkFileDialog $dialog): array
    {
        $cancellable = GCancellable::new();
        $this->open[$id] = ['native' => $dialog, 'cancellable' => $cancellable];
        $weak = WeakReference::create($this);

        return [$cancellable, static fn (\Closure $finish): \Closure => static function (GtkFileDialog $dialog, GAsyncResult $result) use ($weak, $id, $finish): void {
            try {
                $paths = $finish($dialog, $result);
            } catch (GError) {
                $paths = [];
            }
            $weak->get()?->ended($id, $paths);
        }];
    }

    /** @return list<?string> the local paths of a list model of GFile */
    private static function paths(GListModel $files): array
    {
        $paths = [];
        for ($i = 0, $n = $files->getNItems(); $i < $n; $i++) {
            $file = $files->getItem($i);
            $paths[] = $file instanceof GFile ? $file->getPath() : null;
        }

        return $paths;
    }

    private function parentWindow(ToolkitWindow $parent): \GtkWindow
    {
        /** @var GTKWindow $parent the parent check made it a window of this driver */
        return $parent->native();
    }
}
