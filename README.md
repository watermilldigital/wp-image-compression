<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/logo-dark.svg">
    <img src=".github/logo-light.svg" alt="WaterMill" width="220" height="30">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/watermilldigital/wp-image-compression/tags"><img src="https://img.shields.io/badge/version-v2.1.0-blue" alt="Version"></a>
  <img src="https://img.shields.io/badge/php-%5E8.4-777bb4" alt="PHP ^8.4">
  <img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" alt="License: GPL-2.0-or-later">
</p>

<p align="center"><strong>Smaller images, no settings screen: JPEG to WebP, lossy PNG, and a record of every byte saved.</strong></p>

# WP Image Compression

Image compression for WaterMill WordPress sites, instead of Smush and similar plugins. It's a must-use plugin with no settings screen: settings are constants in `wp-config.php`.

## What it does

- **JPEG:** converted to WebP. WordPress encodes the full size and every image size once, at `WP_IMAGE_COMPRESSION_JPEG_QUALITY`.
- **PNG:** every file is reduced to a dithered 256-colour palette, which keeps transparency and doesn't band gradients. A file is only replaced if the result is smaller.
- **Originals:** when WordPress would serve the upload as it is, WP Image Compression writes a compressed `-min` copy and keeps the upload as the attachment's original. That's the same thing WordPress does when it scales a large image. Regenerating always starts from the untouched upload, so nothing is compressed twice.
- **Tracking:** every file (full size, each image size, Crop Thumbnails re-crops) records its bytes before and after, and a status. "Before" means what WordPress would have made without WP Image Compression: the upload itself for the full size, or a throwaway encode at WordPress's default quality for the image sizes. The throwaway encode is never written to disk.
- **wp-admin:**
  - An "Image compression" dashboard widget with totals and savings.
  - A Compression column in Media → Library (list view).
  - A "Not compressed" filter.
  - A **Compress** bulk action.
- **GIF:** left alone, so animations keep working. GIFs are recorded as unsupported.

## Settings

All optional. These are the defaults:

```php
define( 'WP_IMAGE_COMPRESSION_JPEG_QUALITY', 80 );   // 1-100. Also used for WebP output.
define( 'WP_IMAGE_COMPRESSION_JPEG_TO_WEBP', true ); // false: JPEGs stay JPEG, still compressed.
define( 'WP_IMAGE_COMPRESSION_PNG_LOSSY', true );    // false: PNGs stay lossless.
define( 'WP_IMAGE_COMPRESSION_PNG_COLOURS', 256 );   // 2-256.
define( 'WP_IMAGE_COMPRESSION_PNG_DITHER', true );   // false: slightly smaller, but gradients band.
define( 'WP_IMAGE_COMPRESSION_MAX_SIZE', 2560 );     // Longest side for full-size images. false: never scale.
```

Settings apply to new uploads. To apply them to existing images, regenerate them with the bulk action or `wp media regenerate`.

## Install

```sh
composer config repositories.wp-image-compression vcs https://github.com/watermilldigital/wp-image-compression
composer require watermilldigital/wp-image-compression:^2.0
```

It needs the same `wordpress-muplugin` installer path and `mu-plugins/autoloader.php` as [WP Base](https://github.com/watermilldigital/wp-base#install). WP Image Compression also needs PHP's GD extension with WebP support.

To compress an existing library after installing, run `wp media regenerate --yes`, or use the widget's "Show them" link with the Compress bulk action.

## Development

```sh
composer install
composer check   # phpstan + phpcs
```

The end-to-end smoke test uploads generated images, checks every file was compressed and recorded, then deletes them. `tests/` isn't in the released package, so run it from this clone against a site that has the plugin installed:

```sh
wp eval-file tests/smoke.php --path=/path/to/site/wp
```

It tests the copy the site loads, not this clone's `src/`. To test unreleased changes, put this clone's files in the site's `mu-plugins/wp-image-compression/` first.

Release by bumping the version badge at the top of this README, then tagging (`git tag v2.1.0 && git push origin v2.1.0`). The badge is static because shields.io can't read tags from a private repo. After tagging, run `composer update watermilldigital/wp-image-compression` in each project.

## License

Copyright © WaterMill Digital. Licensed under [GPL-2.0-or-later](LICENSE), the same licence as WordPress.
