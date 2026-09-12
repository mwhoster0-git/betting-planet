<?php
/**
 * Minified asset URL helper.
 *
 * .min.css / .min.js files are generated at release time (see
 * release-ecs-free.py / release-ecs-pro.py) and are not committed to the
 * plugin's git repository — only the SVN/zip release build ships them.
 * Falls back to the unminified file when SCRIPT_DEBUG is on, or when the
 * .min file doesn't exist yet (e.g. running straight from git checkout).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * @param string $url  Full enqueue URL for a .css or .js file.
 * @param string $path Filesystem path to the same file (to check the .min
 *                      file actually exists before pointing at it).
 */
function ecs_asset_url( string $url, string $path ): string {
	if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
		return $url;
	}

	if ( ! preg_match( '/\.(css|js)$/', $path ) || str_ends_with( $path, '.min.css' ) || str_ends_with( $path, '.min.js' ) ) {
		return $url;
	}

	$min_path = preg_replace( '/\.(css|js)$/', '.min.$1', $path );
	if ( ! file_exists( $min_path ) ) {
		return $url;
	}

	return preg_replace( '/\.(css|js)$/', '.min.$1', $url );
}
