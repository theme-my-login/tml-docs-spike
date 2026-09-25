<?php
/**
 * Parse a plugin's tracked PHP files into phpdoc-parser's JSON model.
 *
 * Usage: php bin/parse.php <repo-root> <out.json> [file ...]
 *
 * phpdoc-parser's own CLI takes a directory and recurses it blindly, which means
 * parsing every vendored copy of WordPress. This drives parse_files() off an
 * explicit list instead. Named files are parsed alone and merged into an existing
 * out.json by path; with none, every tracked PHP file is parsed.
 */

$root = $argv[1] ?? null;
$out  = $argv[2] ?? null;

if ( null === $root || null === $out ) {
	fwrite( STDERR, "Usage: php bin/parse.php <repo-root> <out.json> [file ...]\n" );
	exit( 1 );
}

$resolved = realpath( $root );

if ( false === $resolved ) {
	fwrite( STDERR, sprintf( "No such directory: %s\n", $root ) );
	exit( 1 );
}

$root        = rtrim( $resolved, '/' );
$incremental = count( $argv ) > 3;
$files       = array_slice( $argv, 3 );

if ( ! $incremental ) {
	$status = 0;
	exec( sprintf( 'git -C %s ls-files -- "*.php"', escapeshellarg( $root ) ), $files, $status );

	if ( 0 !== $status ) {
		fwrite( STDERR, sprintf( "Not a git checkout: %s\n", $root ) );
		exit( 1 );
	}
}

// vendor/ and node_modules/ are gitignored everywhere in this family, so only
// test and tooling directories need excluding.
$files = array_values(
	array_filter(
		array_map(
			function ( $file ) use ( $root ) {
				return $root . '/' . ltrim( str_replace( $root, '', $file ), '/' );
			},
			$files
		),
		function ( $file ) {
			return is_file( $file ) && ! preg_match( '#/(tests|tools)/#', $file );
		}
	)
);

if ( ! $files ) {
	fwrite( STDERR, "No PHP files to parse.\n" );
	exit( 1 );
}

// phpdoc-parser is a WP-CLI command; these stand in for the framework it expects.
class WP_CLI_Command {}

class WP_CLI {
	public static function line() {}
}

require __DIR__ . '/../.parser/vendor/autoload.php';
require __DIR__ . '/../.parser/lib/class-command.php';

foreach ( glob( __DIR__ . '/../.parser/lib/*.php' ) as $import ) {
	require_once $import;
}

$parsed = WP_Parser\parse_files( $files, $root );

if ( $incremental ) {
	$existing = file_exists( $out ) ? json_decode( file_get_contents( $out ), true ) : array();
	$by_path  = array_column( $existing, null, 'path' );

	foreach ( $parsed as $file ) {
		$by_path[ $file['path'] ] = $file;
	}

	ksort( $by_path );

	$parsed = array_values( $by_path );
}

file_put_contents( $out, json_encode( $parsed, JSON_PRETTY_PRINT ) );

printf( "%d files -> %s\n", count( $parsed ), $out );
