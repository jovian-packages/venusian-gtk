# Venusian GTK

[![Tests](https://github.com/jovian-packages/venusian-gtk/actions/workflows/tests.yml/badge.svg)](https://github.com/jovian-packages/venusian-gtk/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/jovian/venusian-gtk.svg)](https://packagist.org/packages/jovian/venusian-gtk)
[![Total Downloads](https://img.shields.io/packagist/dt/jovian/venusian-gtk.svg)](https://packagist.org/packages/jovian/venusian-gtk)
[![License](https://img.shields.io/packagist/l/jovian/venusian-gtk.svg)](LICENSE)

## About Venusian GTK

> **Note:** This repository contains the GTK toolkit driver for the Venusian framework. If you want to build an application using Venusian, visit the main [Venusian skeleton repository](https://github.com/VenusianPHP/venusian).

Venusian GTK is the GTK 4 toolkit driver for Venusian Topside: it connects PHP to `GtkApplication`, opens its windows, builds their menu bars and turns Topside's views into real GTK widgets through [ext-gtk](https://github.com/php-io-extensions/gtk), all in the Laravel-like syntax you already know.

Want a Linux desktop app that feels at home on GNOME and beyond? Write your view once with Topside and Venusian GTK gives you the real thing: native buttons, text inputs and areas, tables, lists and trees, split and tab views, dialogs, the clipboard, and a menu bar whose Edit items, accelerators and enabled states behave the way GTK users expect.

Need video? A video view plays a file or an https URL through the player you pick in config: GStreamer's playbin through `jovian/venusian-gstreamer`, drawn straight into a `GtkPicture`. GTK's own media player is the default driver; on macOS it has no media backend and plays nothing.

Need more than widgets? A canvas shows your framebuffer as a GTK texture with no pixel passing through PHP, and lends its surface to Venusian's rendering engines, so OpenGL and Vulkan draw straight into your window. And with HumanInput installed, the keyboard, the pointer and the wheel of your GTK windows read from the same app.

Linux first, macOS too. Venusian GTK runs wherever GTK 4 does, from a desktop to a Raspberry Pi; on macOS it runs on `NSApplication` alongside [ext-appkit](https://github.com/php-io-extensions/appkit). Prefer another toolkit? Reach for `jovian/venusian-qt`, `jovian/venusian-appkit` or `jovian/venusian-raven` and keep the same views.

> **Built with AI assistance.** This package was developed LLM-assisted — not vibe-coded. Generated code was deliberately prompted and reviewed by a professional software engineer. See [AI-assisted development](#ai-assisted-development).

## Learning Venusian GTK

Venusian GTK has documentation and guides on the [Venusian website](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-gtk/overview), starting with the [overview](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-gtk/overview): the [session](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-gtk/guide/session), [windows and menus](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-gtk/guide/windows-and-menus), [primitives](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-gtk/guide/primitives) and [input](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-gtk/guide/input).

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Contributing

Thank you for considering contributing to Venusian GTK! The contribution guide can be found in the [Venusian documentation](https://venusian.projectsaturnstudios.com/docs/contributions).

## Code of Conduct

In order to ensure that the Venusian community is welcoming to all, please review and abide by the [Code of Conduct](https://venusian.projectsaturnstudios.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/jovian-packages/venusian-gtk/security/policy) on how to report security vulnerabilities.

## License

Venusian GTK is open-sourced software licensed under the [MIT license](LICENSE).
