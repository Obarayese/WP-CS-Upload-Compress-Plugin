<?php
/**
 * Plugin Name: Crown Softech Upload Compress
 * Description: Owned by Crown Softech. Compresses JPEG/PNG/WebP on upload and via bulk optimize — strips metadata, skips tiny files. Blocks video uploads.
 * Version: 1.1.0
 * Author: Crown Softech
 * License: GPL-2.0-or-later
 * Text Domain: crown-softech-upload-compress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Crown_Softech_Upload_Compress {
	const JPEG_QUALITY = 75;
	const WEBP_QUALITY = 75;
	const PNG_QUALITY  = 80;
	const MIN_BYTES    = 102400; // 100 KB

	private static $video_ext = array(
		'mp4', 'm4v', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'webm', 'mpeg', 'mpg', '3gp', 'ogv', 'ts', 'm2ts',
	);

	public static function init() {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'reject_videos' ), 5 );
		add_filter( 'wp_handle_upload', array( __CLASS__, 'on_upload' ), 20 );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_metadata' ), 20, 2 );
		add_filter( 'jpeg_quality', array( __CLASS__, 'jpeg_quality' ) );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'jpeg_quality' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'crown-softech compress-media', array( __CLASS__, 'cli_compress_media' ) );
			// Compatibility alias. Canonical command is `wp crown-softech compress-media`.
			WP_CLI::add_command( 'crownsoft compress-media', array( __CLASS__, 'cli_compress_media' ) );
		}
	}

	public static function jpeg_quality( $q ) {
		return self::JPEG_QUALITY;
	}

	public static function reject_videos( $file ) {
		if ( empty( $file['name'] ) && empty( $file['type'] ) ) {
			return $file;
		}
		$name = isset( $file['name'] ) ? $file['name'] : '';
		$type = isset( $file['type'] ) ? strtolower( $file['type'] ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		$is_video = false;
		if ( $type && ( 0 === strpos( $type, 'video/' ) ) ) {
			$is_video = true;
		}
		if ( $ext && in_array( $ext, self::$video_ext, true ) ) {
			$is_video = true;
		}
		if ( $is_video ) {
			$file['error'] = 'This file cannot be uploaded. Video uploads are not allowed.';
		}
		return $file;
	}

	public static function on_upload( $upload ) {
		if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
			return $upload;
		}
		self::compress_file( $upload['file'] );
		return $upload;
	}

	public static function on_metadata( $metadata, $attachment_id ) {
		if ( empty( $metadata['file'] ) ) {
			return $metadata;
		}
		$upload_dir = wp_get_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$rel        = $metadata['file'];
		$subdir     = trailingslashit( dirname( $rel ) );
		if ( '.' === $subdir || './' === $subdir ) {
			$subdir = '';
		}

		$original = $base_dir . $rel;
		self::compress_file( $original );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				self::compress_file( $base_dir . $subdir . $size['file'] );
			}
		}

		if ( file_exists( $original ) ) {
			$info = @getimagesize( $original );
			if ( $info ) {
				$metadata['width']  = $info[0];
				$metadata['height'] = $info[1];
			}
			$metadata['filesize'] = function_exists( 'wp_filesize' ) ? wp_filesize( $original ) : filesize( $original );
		}

		return $metadata;
	}

	/**
	 * WP-CLI: wp crown-softech compress-media [--dir=<uploads>]
	 * Alias:  wp crownsoft compress-media
	 */
	public static function cli_compress_media( $args, $assoc_args ) {
		$dir = isset( $assoc_args['dir'] ) ? $assoc_args['dir'] : '';
		if ( ! $dir ) {
			$u   = wp_get_upload_dir();
			$dir = $u['basedir'];
		}
		$result = self::bulk_compress_directory( $dir );
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::success(
				sprintf(
					'Scanned %d image(s); compressed %d; skipped %d; saved %s bytes (%.1f MB).',
					$result['scanned'],
					$result['compressed'],
					$result['skipped'],
					$result['saved'],
					$result['saved'] / 1024 / 1024
				)
			);
		}
		return $result;
	}

	public static function bulk_compress_directory( $dir ) {
		$scanned = 0;
		$compressed = 0;
		$skipped = 0;
		$saved = 0;
		if ( ! is_dir( $dir ) ) {
			return compact( 'scanned', 'compressed', 'skipped', 'saved' );
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = $file->getPathname();
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
				continue;
			}
			$scanned++;
			$delta  = self::compress_file( $path );
			if ( $delta > 0 ) {
				$compressed++;
				$saved += $delta;
			} else {
				$skipped++;
			}
			if ( 0 === ( $scanned % 200 ) && function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}
		return compact( 'scanned', 'compressed', 'skipped', 'saved' );
	}

	public static function compress_file( $path ) {
		if ( ! is_string( $path ) || ! file_exists( $path ) || ! is_writable( $path ) ) {
			return 0;
		}
		$before = filesize( $path );
		if ( false === $before || $before < self::MIN_BYTES ) {
			return 0;
		}
		$mime = self::mime_for( $path );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return 0;
		}
		$ok = false;
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			$ok = self::compress_imagick( $path, $mime );
		}
		if ( ! $ok && function_exists( 'imagecreatefromjpeg' ) ) {
			$ok = self::compress_gd( $path, $mime );
		}
		if ( ! $ok ) {
			return 0;
		}
		clearstatcache( true, $path );
		$after = filesize( $path );
		if ( false === $after || $after >= $before ) {
			return 0;
		}
		return $before - $after;
	}

	private static function mime_for( $path ) {
		if ( function_exists( 'wp_check_filetype' ) ) {
			$ft = wp_check_filetype( $path );
			if ( ! empty( $ft['type'] ) ) {
				return $ft['type'];
			}
		}
		if ( function_exists( 'mime_content_type' ) ) {
			$m = @mime_content_type( $path );
			if ( $m ) {
				return $m;
			}
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
			'png' => 'image/png', 'webp' => 'image/webp',
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
	}

	private static function compress_imagick( $path, $mime ) {
		try {
			$img = new Imagick( $path );
			if ( method_exists( $img, 'autoOrient' ) ) {
				@$img->autoOrient();
			}
			$img->stripImage();
			if ( 'image/jpeg' === $mime ) {
				$img->setImageFormat( 'jpeg' );
				$img->setImageCompression( Imagick::COMPRESSION_JPEG );
				$img->setImageCompressionQuality( self::JPEG_QUALITY );
				$img->setInterlaceScheme( Imagick::INTERLACE_JPEG );
			} elseif ( 'image/png' === $mime ) {
				$img->setImageFormat( 'png' );
				$img->setImageCompressionQuality( self::PNG_QUALITY );
				if ( method_exists( $img, 'setOption' ) ) {
					@$img->setOption( 'png:compression-level', '9' );
				}
			} elseif ( 'image/webp' === $mime ) {
				$img->setImageFormat( 'webp' );
				$img->setImageCompressionQuality( self::WEBP_QUALITY );
			} else {
				$img->clear();
				$img->destroy();
				return false;
			}
			$tmp = $path . '.cst-tmp';
			$img->writeImage( $tmp );
			$img->clear();
			$img->destroy();
			if ( ! file_exists( $tmp ) || filesize( $tmp ) < 1 ) {
				@unlink( $tmp );
				return false;
			}
			$before = filesize( $path );
			$after  = filesize( $tmp );
			if ( $after > 0 && $after <= $before ) {
				rename( $tmp, $path );
				return true;
			}
			@unlink( $tmp );
			return false;
		} catch ( Exception $e ) {
			if ( ! empty( $tmp ) && file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
			return false;
		}
	}

	private static function compress_gd( $path, $mime ) {
		$tmp = $path . '.cst-tmp';
		$ok  = false;
		if ( 'image/jpeg' === $mime ) {
			$im = @imagecreatefromjpeg( $path );
			if ( ! $im ) {
				return false;
			}
			$ok = imagejpeg( $im, $tmp, self::JPEG_QUALITY );
			imagedestroy( $im );
		} elseif ( 'image/png' === $mime ) {
			$im = @imagecreatefrompng( $path );
			if ( ! $im ) {
				return false;
			}
			imagesavealpha( $im, true );
			$ok = imagepng( $im, $tmp, 8 );
			imagedestroy( $im );
		} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' ) ) {
			$im = @imagecreatefromwebp( $path );
			if ( ! $im ) {
				return false;
			}
			$ok = imagewebp( $im, $tmp, self::WEBP_QUALITY );
			imagedestroy( $im );
		} else {
			return false;
		}
		if ( ! $ok || ! file_exists( $tmp ) ) {
			@unlink( $tmp );
			return false;
		}
		$before = filesize( $path );
		$after  = filesize( $tmp );
		if ( $after > 0 && $after <= $before ) {
			rename( $tmp, $path );
			return true;
		}
		@unlink( $tmp );
		return false;
	}
}

if ( function_exists( 'add_filter' ) ) {
	Crown_Softech_Upload_Compress::init();
}
