<?php
/**
 * Render phpdoc-parser JSON into a static developer reference.
 *
 * Usage: php bin/render.php <in.json> <out-dir> <owner/repo> <ref>
 */

$in    = $argv[1] ?? null;
$dir   = $argv[2] ?? null;
$slug  = $argv[3] ?? null;
$ref   = $argv[4] ?? 'master';

if ( null === $in || null === $dir || null === $slug ) {
	fwrite( STDERR, "Usage: php bin/render.php <in.json> <out-dir> <owner/repo> <ref>\n" );
	exit( 1 );
}

$data = json_decode( (string) file_get_contents( $in ), true );

if ( ! is_array( $data ) ) {
	fwrite( STDERR, sprintf( "Could not read %s\n", $in ) );
	exit( 1 );
}

/**
 * Collect every symbol, flattening hooks out of their declaring function.
 *
 * @param array $data Parsed files.
 * @return array Symbols keyed by type.
 */
function collect( array $data ) {
	$out = array(
		'function' => array(),
		'hook'     => array(),
		'class'    => array(),
	);

	foreach ( $data as $file ) {
		$path = $file['path'];

		foreach ( $file['functions'] ?? array() as $function ) {
			$function['path'] = $path;

			$out['function'][] = $function;

			foreach ( $function['hooks'] ?? array() as $hook ) {
				$hook['path']    = $path;
				$hook['fired_in'] = $function['name'];

				$out['hook'][] = $hook;
			}
		}

		foreach ( $file['classes'] ?? array() as $class ) {
			$class['path'] = $path;

			$out['class'][] = $class;

			foreach ( $class['methods'] ?? array() as $method ) {
				foreach ( $method['hooks'] ?? array() as $hook ) {
					$hook['path']     = $path;
					$hook['fired_in'] = $class['name'] . '::' . $method['name'] . '()';

					$out['hook'][] = $hook;
				}
			}
		}
	}

	// A hook fired from more than one place appears once, carrying every site.
	$hooks = array();

	foreach ( $out['hook'] as $hook ) {
		$name = $hook['name'];

		if ( isset( $hooks[ $name ] ) ) {
			$hooks[ $name ]['sites'][] = $hook;
			continue;
		}

		$hook['sites']  = array( $hook );
		$hooks[ $name ] = $hook;
	}

	$out['hook'] = array_values( $hooks );

	foreach ( $out as $type => $items ) {
		usort(
			$items,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$out[ $type ] = $items;
	}

	return $out;
}

/**
 * Reduce a symbol name to a filename-safe slug.
 *
 * @param string $name
 * @return string
 */
function slugify( $name ) {
	$slug = strtolower( (string) $name );
	$slug = str_replace( '::', '-', $slug );
	$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );

	return trim( (string) $slug, '-' );
}

/**
 * Escape text for HTML output.
 *
 * @param string $text
 * @return string
 */
function e( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Pull the tags of one name out of a doc block.
 *
 * @param array  $item
 * @param string $name Tag name, e.g. `param`.
 * @return array
 */
function tags( array $item, $name ) {
	$found = array();

	foreach ( $item['doc']['tags'] ?? array() as $tag ) {
		if ( $name === ( $tag['name'] ?? '' ) ) {
			$found[] = $tag;
		}
	}

	return $found;
}

/**
 * Pull the first tag of one name out of a doc block.
 *
 * @param array  $item
 * @param string $name Tag name.
 * @return array|null
 */
function first_tag( array $item, $name ) {
	$tags = tags( $item, $name );

	return $tags ? $tags[0] : null;
}

/**
 * Join a tag's declared types into one string.
 *
 * @param array|null $tag
 * @return string
 */
function types( $tag ) {
	$types = $tag['types'] ?? array();

	return $types ? implode( '|', $types ) : '';
}

/**
 * Build a call signature from the declared arguments, preferring documented types.
 *
 * @param array $item Function or method.
 * @return string
 */
function signature( array $item ) {
	$params = array();

	foreach ( tags( $item, 'param' ) as $tag ) {
		$params[ $tag['variable'] ?? '' ] = types( $tag );
	}

	$parts = array();

	foreach ( $item['arguments'] ?? array() as $argument ) {
		if ( ! is_array( $argument ) ) {
			continue;
		}

		$name = $argument['name'];
		$type = $argument['type'] ?: ( $params[ $name ] ?? '' );
		$part = $type ? $type . ' ' . $name : $name;

		if ( null !== ( $argument['default'] ?? null ) ) {
			$part .= ' = ' . $argument['default'];
		}

		$parts[] = $part;
	}

	return $item['name'] . '( ' . ( $parts ? implode( ', ', $parts ) . ' ' : '' ) . ')';
}

/**
 * Build a GitHub link to the lines a symbol was parsed from.
 *
 * @param string $path     Path relative to the repository root.
 * @param int    $line     First line.
 * @param int    $end_line Last line.
 * @param string $slug     owner/repo.
 * @param string $ref      Branch, tag or commit.
 * @return string
 */
function source_link( $path, $line, $end_line, $slug, $ref ) {
	return sprintf(
		'https://github.com/%s/blob/%s/%s#L%d-L%d',
		$slug,
		$ref,
		$path,
		$line,
		$end_line
	);
}

/**
 * Wrap a page body in the shared chrome.
 *
 * @param string $title
 * @param string $subtitle
 * @param string $body     Rendered HTML.
 * @param int    $depth    Directory depth below the site root, for relative links.
 * @return string
 */
function page( $title, $subtitle, $body, $depth = 0 ) {
	$up = str_repeat( '../', $depth );

	return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<link rel="stylesheet" href="{$up}assets/style.css">
</head>
<body>
<header class="masthead">
	<a class="home" href="{$up}index.html">Theme My Login</a>
	<nav>
		<a href="{$up}functions.html">Functions</a>
		<a href="{$up}hooks.html">Hooks</a>
		<a href="{$up}classes.html">Classes</a>
	</nav>
</header>
<main>
<h1>{$title}</h1>
<p class="subtitle">{$subtitle}</p>
{$body}
</main>
<footer>Generated from source docblocks. Do not edit by hand.</footer>
</body>
</html>
HTML;
}

/**
 * Render description, long description and `@since`, shared by every symbol type.
 *
 * @param array $item
 * @return string
 */
function prose( array $item ) {
	$html = '';

	if ( ! empty( $item['doc']['description'] ) ) {
		$html .= '<p class="lede">' . e( $item['doc']['description'] ) . "</p>\n";
	}

	if ( ! empty( $item['doc']['long_description'] ) ) {
		$long = strip_tags( $item['doc']['long_description'], '<p><code><a><ul><ol><li><strong><em>' );
		$html .= '<div class="long">' . $long . "</div>\n";
	}

	$since = first_tag( $item, 'since' );

	if ( $since && ! empty( $since['content'] ) ) {
		$html .= '<p class="since">Since <code>' . e( $since['content'] ) . "</code></p>\n";
	}

	return $html;
}

/**
 * Render the documented parameters as a table.
 *
 * @param array $item
 * @return string
 */
function params_table( array $item ) {
	$params = tags( $item, 'param' );

	if ( ! $params ) {
		return '';
	}

	$rows = '';

	foreach ( $params as $param ) {
		$rows .= sprintf(
			"<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>\n",
			e( $param['variable'] ?? '' ),
			e( types( $param ) ),
			e( $param['content'] ?? '' )
		);
	}

	return "<h2>Parameters</h2>\n<table><thead><tr><th>Name</th><th>Type</th><th>Description</th></tr></thead><tbody>\n{$rows}</tbody></table>\n";
}

/**
 * Render the documented return value.
 *
 * @param array $item
 * @return string
 */
function returns_block( array $item ) {
	$return = first_tag( $item, 'return' );

	if ( ! $return ) {
		return '';
	}

	return sprintf(
		"<h2>Return</h2>\n<p><code>%s</code> %s</p>\n",
		e( types( $return ) ),
		e( $return['content'] ?? '' )
	);
}

/**
 * Render the functions a symbol calls, linking the ones documented here.
 *
 * @param array $item
 * @param array $items All collected symbols.
 * @return string
 */
function uses_block( array $item, $items ) {
	$uses = array();

	foreach ( $item['uses']['functions'] ?? array() as $used ) {
		$uses[] = $used['name'];
	}

	if ( ! $uses ) {
		return '';
	}

	$known = array_column( $items['function'], 'name' );
	$links = array();

	foreach ( array_unique( $uses ) as $name ) {
		$links[] = in_array( $name, $known, true )
			? sprintf( '<a href="../function/%s.html"><code>%s()</code></a>', slugify( $name ), e( $name ) )
			: sprintf( '<code>%s()</code>', e( $name ) );
	}

	sort( $links );

	return "<h2>Uses</h2>\n<p class=\"uses\">" . implode( ' ', $links ) . "</p>\n";
}

$items = collect( $data );

@mkdir( $dir, 0755, true );
@mkdir( $dir . '/assets', 0755, true );

foreach ( array( 'function', 'hook', 'class' ) as $type ) {
	@mkdir( $dir . '/' . $type, 0755, true );
}

// Detail pages.
foreach ( $items['function'] as $function ) {
	$body = '<pre class="signature"><code>' . e( signature( $function ) ) . "</code></pre>\n"
		. prose( $function )
		. params_table( $function )
		. returns_block( $function )
		. uses_block( $function, $items )
		. sprintf(
			"<h2>Source</h2>\n<p><a href=\"%s\">%s, line %d</a></p>\n",
			e( source_link( $function['path'], $function['line'], $function['end_line'], $slug, $ref ) ),
			e( $function['path'] ),
			$function['line']
		);

	file_put_contents(
		sprintf( '%s/function/%s.html', $dir, slugify( $function['name'] ) ),
		page( $function['name'] . '()', 'Function', $body, 1 )
	);
}

foreach ( $items['hook'] as $hook ) {
	$sites = '';

	foreach ( $hook['sites'] as $site ) {
		$sites .= sprintf(
			"<li>Fired in <code>%s</code> &mdash; <a href=\"%s\">%s, line %d</a></li>\n",
			e( $site['fired_in'] ),
			e( source_link( $site['path'], $site['line'], $site['end_line'], $slug, $ref ) ),
			e( $site['path'] ),
			$site['line']
		);
	}

	$body = '<pre class="signature"><code>' . e( $hook['name'] ) . "</code></pre>\n"
		. prose( $hook )
		. params_table( $hook )
		. "<h2>Fired from</h2>\n<ul class=\"sites\">\n" . $sites . "</ul>\n";

	file_put_contents(
		sprintf( '%s/hook/%s.html', $dir, slugify( $hook['name'] ) ),
		page( $hook['name'], ucfirst( $hook['type'] ), $body, 1 )
	);
}

foreach ( $items['class'] as $class ) {
	$body = prose( $class );

	if ( ! empty( $class['extends'] ) ) {
		$body .= '<p>Extends <code>' . e( $class['extends'] ) . "</code></p>\n";
	}

	$properties = '';

	foreach ( $class['properties'] ?? array() as $property ) {
		$properties .= sprintf(
			"<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>\n",
			e( $property['name'] ),
			e( $property['visibility'] . ( $property['static'] ? ' static' : '' ) ),
			e( $property['doc']['description'] ?? '' )
		);
	}

	if ( $properties ) {
		$body .= "<h2>Properties</h2>\n<table><thead><tr><th>Name</th><th>Visibility</th><th>Description</th></tr></thead><tbody>\n{$properties}</tbody></table>\n";
	}

	$methods = '';

	foreach ( $class['methods'] ?? array() as $method ) {
		$methods .= sprintf(
			"<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>\n",
			e( signature( $method ) ),
			e( $method['visibility'] . ( $method['static'] ? ' static' : '' ) ),
			e( $method['doc']['description'] ?? '' )
		);
	}

	if ( $methods ) {
		$body .= "<h2>Methods</h2>\n<table><thead><tr><th>Signature</th><th>Visibility</th><th>Description</th></tr></thead><tbody>\n{$methods}</tbody></table>\n";
	}

	$body .= sprintf(
		"<h2>Source</h2>\n<p><a href=\"%s\">%s, line %d</a></p>\n",
		e( source_link( $class['path'], $class['line'], $class['end_line'], $slug, $ref ) ),
		e( $class['path'] ),
		$class['line']
	);

	file_put_contents(
		sprintf( '%s/class/%s.html', $dir, slugify( $class['name'] ) ),
		page( $class['name'], 'Class', $body, 1 )
	);
}

// Index pages, each filterable in the browser.
$labels = array(
	'function' => array( 'Functions', 'functions.html' ),
	'hook'     => array( 'Hooks', 'hooks.html' ),
	'class'    => array( 'Classes', 'classes.html' ),
);

foreach ( $labels as $type => $label ) {
	$rows = '';

	foreach ( $items[ $type ] as $item ) {
		$meta = 'hook' === $type
			? ucfirst( $item['type'] )
			: ( 'function' === $type ? 'Function' : 'Class' );

		$rows .= sprintf(
			"<tr><td><a href=\"%s/%s.html\"><code>%s</code></a></td><td class=\"kind\">%s</td><td>%s</td></tr>\n",
			$type,
			slugify( $item['name'] ),
			e( $item['name'] ),
			e( $meta ),
			e( $item['doc']['description'] ?? '' )
		);
	}

	$body = <<<HTML
<input type="search" id="filter" placeholder="Filter&hellip;" autocomplete="off">
<table id="index"><tbody>
{$rows}</tbody></table>
<script>
const filter = document.getElementById('filter');
const rows = [...document.querySelectorAll('#index tbody tr')];
filter.addEventListener('input', () => {
	const q = filter.value.toLowerCase();
	rows.forEach(r => { r.hidden = q && !r.textContent.toLowerCase().includes(q); });
});
</script>
HTML;

	file_put_contents(
		$dir . '/' . $label[1],
		page( $label[0], sprintf( '%d in total', count( $items[ $type ] ) ), $body )
	);
}

$body = sprintf(
	'<div class="cards">
	<a class="card" href="functions.html"><strong>%d</strong><span>Functions</span></a>
	<a class="card" href="hooks.html"><strong>%d</strong><span>Hooks</span></a>
	<a class="card" href="classes.html"><strong>%d</strong><span>Classes</span></a>
</div>
<p>Every page here is generated from the docblocks in the plugin source. Each entry links
back to the exact lines it was parsed from.</p>',
	count( $items['function'] ),
	count( $items['hook'] ),
	count( $items['class'] )
);

file_put_contents( $dir . '/index.html', page( 'Developer Reference', 'Generated from ' . $slug . ' @ ' . substr( $ref, 0, 7 ), $body ) );

file_put_contents( $dir . '/assets/style.css', file_get_contents( __DIR__ . '/style.css' ) );

printf(
	"%d functions, %d hooks, %d classes -> %s\n",
	count( $items['function'] ),
	count( $items['hook'] ),
	count( $items['class'] ),
	$dir
);
