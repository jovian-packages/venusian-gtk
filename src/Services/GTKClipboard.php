<?php

namespace Jovian\Toolkits\GTK\Services;

use GAsyncResult;
use GdkClipboard;
use GdkDisplay;
use GError;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Surface\Contracts\Windows\Mail\Services\ClipboardChanged;
use Surface\Windows\Services\ToolkitClipboard;
use Voyager\Contracts\Signals\NamedSignal;
use WeakReference;

/**
 * The display's clipboard. A read is async: requestText() answers in readTextAsync's callback,
 * null on GError. The clipboard's changed signal, for this app's own setText() and another app's
 * copy, posts ClipboardChanged (latest-wins).
 */
final class GTKClipboard extends ToolkitClipboard
{
    private readonly GdkClipboard $clipboard;

    public function __construct(private readonly GTKSession $session)
    {
        $this->clipboard = GdkDisplay::getDefault()->getClipboard();
        $weak = WeakReference::create($this);
        g_signal_connect($this->clipboard, 'changed', static fn () => $weak->get()?->changed());
    }

    public function setText(string $text): void
    {
        $this->clipboard->setText($text);
    }

    public function hasText(): bool
    {
        $formats = $this->clipboard->getFormats();

        return $formats->containMimeType('text/plain;charset=utf-8') || $formats->containMimeType('text/plain');
    }

    protected function readText(string $id): void
    {
        $weak = WeakReference::create($this);
        $this->clipboard->readTextAsync(null, static function (GdkClipboard $clipboard, GAsyncResult $result) use ($weak, $id): void {
            try {
                $text = $clipboard->readTextFinish($result);
            } catch (GError) {
                $text = null;
            }
            $weak->get()?->answerText($id, $text);
        });
    }

    protected function post(NamedSignal $mail): void
    {
        $this->session->post($mail);
    }

    private function changed(): void
    {
        $this->session->postLatest('clipboard.changed', new ClipboardChanged());
    }
}
