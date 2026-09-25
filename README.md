# Grist

Image compression for WaterMill WordPress sites, instead of Smush and similar plugins. It's a must-use plugin with no settings screen: settings are constants in `wp-config.php`.

## What it does

- **JPEG:** converted to WebP. WordPress encodes the full size and every image size once, at `GRIST_JPEG_QUALITY`.
- **PNG:** every file is reduced to a dithered 256-colour palette, which keeps transparency and doesn't band gradients. A file is only replaced if the result is smaller.
- **Originals:** when WordPress would serve the upload as it is, Grist writes a compressed `-min` copy and keeps the upload as the attachment's original. That's the same thing WordPress does when it scales a large image. Regenerating always starts from the untouched upload, so nothing is compressed twice.
- **Tracking:** every file (full size, each image size, Crop Thumbnails re-crops) records its bytes before and after, and a status. "Before" means what WordPress would have made without Grist: the upload itself for the full size, or a throwaway encode at WordPress's default quality for the image sizes. The throwaway encode is never written to disk.
- **wp-admin:**
  - An "Image compression" dashboard widget with totals and savings.
  - A Compression column in Media → Library (list view).
  - A "Not compressed" filter.
  - A **Compress** bulk action.
- **GIF:** left alone, so animations keep working. GIFs are recorded as unsupported.

## Settings

All optional. These are the defaults:

```php
define( 'GRIST_JPEG_QUALITY', 80 );   // 1-100. Also used for WebP output.
define( 'GRIST_JPEG_TO_WEBP', true ); // false: JPEGs stay JPEG, still compressed.
define( 'GRIST_PNG_LOSSY', true );    // false: PNGs stay lossless.
define( 'GRIST_PNG_COLOURS', 256 );   // 2-256.
define( 'GRIST_PNG_DITHER', true );   // false: slightly smaller, but gradients band.
define( 'GRIST_MAX_SIZE', 2560 );     // Longest side for full-size images. false: never scale.
```

Settings apply to new uploads. To apply them to existing images, regenerate them with the bulk action or `wp media regenerate`.

## Install

```sh
composer config repositories.grist vcs https://github.com/watermilldigital/grist
composer require watermilldigital/grist:^1.0
```

It needs the same `wordpress-muplugin` installer path and `mu-plugins/autoloader.php` as [Millstone](https://github.com/watermilldigital/millstone#install). Grist also needs PHP's GD extension with WebP support.

To compress an existing library after installing, run `wp media regenerate --yes`, or use the widget's "Show them" link with the Compress bulk action.

## Development

```sh
composer install
composer check                                          # phpstan + phpcs
wp eval-file wp-content/mu-plugins/grist/tests/smoke.php # end to end, on a real install
```

The smoke test uploads generated images, checks every file was compressed and recorded, then deletes them.

Release by tagging (`git tag v1.1.0 && git push origin v1.1.0`), then run `composer update watermilldigital/grist` in each project.

## License

Copyright © WaterMill Digital. Licensed under [GPL-2.0-or-later](LICENSE), the same licence as WordPress.
