# jovian/venusian-gtk

GTK 4 toolkit driver for the Venusian Surface bridge.

## Canvas

`GTKCanvas` shows a `TKCanvas`'s framebuffer as a `GdkMemoryTexture` on a `GtkPicture`, in the `R8G8B8X8` layout (the fourth byte ignored, so opaque), stretched over the widget. Needs GTK 4.14.

An ext-fb framebuffer is piped. `present()` copies the framebuffer's memory by address, and on GTK 4.16 or newer the texture after the first is built with `GdkMemoryTextureBuilder` and an update region, so only the damage is read. No pixel byte passes through PHP. A framebuffer held in PHP (the native driver) takes the same path from a string.

With ext-opengl loaded the canvas lends a GL context to the `opengl` engine (jovian/venusian-opengl). The native view is a `GtkBox` holding the picture; lending swaps a `GtkGLArea` in, and the engine draws in the context GTK made for it. `present()` queues a render, and the area's render callback copies the engine's frame into the area's framebuffer on the GPU. Present the window first: before it is realized there is no context to lend.

```php
$canvas = $window->column('m')->canvas('view')->fill();
$window->present();
$engine = app('drawing')->renderer('opengl', ['output' => $canvas]);
$engine->frame(fn ($g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 40, 40, Color::rgb(255, 128, 0)));
$canvas->present();
```
