# Crown Softech Upload Compress

WordPress plugin by **Crown Softech**. It compresses JPEG, PNG, and WebP images on upload and can bulk-compress an existing uploads directory. It strips image metadata, skips files under 100 KB, and rejects video uploads.

Version: 1.1.0  
License: GPL-2.0-or-later

## What it does

- JPEG and WebP quality: 75
- PNG quality: about 80 (Imagick quality 80, GD compression level 8)
- Strips metadata (Imagick `stripImage()`)
- Skips files smaller than 100 KB
- Uses Imagick when it is available, otherwise GD
- Rejects video uploads (`mp4`, `mov`, `webm`, and other common video types, plus any `video/*` MIME type)
- Recompresses generated intermediate sizes after upload

## Install

1. Copy the `crown-softech-upload-compress` folder into `wp-content/plugins/`. 
2. In WordPress admin, go to **Plugins** and activate **Crown Softech Upload Compress**.

Or with WP-CLI, from the site root:

```bash
wp plugin activate crown-softech-upload-compress
```

## WP-CLI

Bulk-compress every JPEG, PNG, and WebP file under the site uploads directory:

```bash
wp crown-softech compress-media
```

Optional directory (defaults to the WordPress uploads folder):

```bash
wp crown-softech compress-media --dir=/path/to/uploads
```

`wp crownsoft compress-media` is a compatibility alias for the same command. Prefer `wp crown-softech compress-media`.

Example output:

```text
Success: Scanned 1000 image(s); compressed 120; skipped 880; saved 12345678 bytes (11.8 MB).
```

Run as the site’s system user so new and rewritten files keep the correct owner. Raise PHP’s memory and time limits on large libraries:

```bash
php -d memory_limit=1024M -d max_execution_time=0 "$(which wp)" crown-softech compress-media
```

## Requirements

- WordPress with the media library
- PHP Imagick and/or GD with JPEG support (WebP needs Imagick or GD WebP)
- WP-CLI for the bulk command

