<?php
/**
 * Plugin Name:       wp-directives
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      5.6
 * Author:            Gutenberg Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-directives
 */

// Check if Gutenberg plugin is active
if (!function_exists('is_plugin_active')) {
	include_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if (!is_plugin_active('gutenberg/gutenberg.php')) {
	// Show an error message
	add_action('admin_notices', function () {
		echo sprintf(
			'<div class="error"><p>%s</p></div>',
			__(
				'This plugin requires the Gutenberg plugin to be installed and activated.',
				'wp-directives'
			)
		);
	});

	// Deactivate the plugin
	deactivate_plugins(plugin_basename(__FILE__));
	return;
}

function wp_directives_loader()
{
	// Load the Admin page.
	require_once plugin_dir_path(__FILE__) . '/src/admin/admin-page.php';
}
add_action('plugins_loaded', 'wp_directives_loader');

/**
 * Add default settings upon activation.
 */
function wp_directives_activate()
{
	add_option('wp_directives_plugin_settings', [
		'client_side_transitions' => false,
	]);
}
register_activation_hook(__FILE__, 'wp_directives_activate');

/**
 * Delete settings on uninstall.
 */
function wp_directives_uninstall()
{
	delete_option('wp_directives_plugin_settings');
}
register_uninstall_hook(__FILE__, 'wp_directives_uninstall');

/**
 * Register the scripts
 */
function wp_directives_register_scripts()
{
	wp_register_script(
		'wp-directive-vendors',
		plugins_url('build/vendors.js', __FILE__),
		[],
		'1.0.0',
		true
	);
	wp_register_script(
		'wp-directive-runtime',
		plugins_url('build/runtime.js', __FILE__),
		['wp-directive-vendors'],
		'1.0.0',
		true
	);

	// For now we can always enqueue the runtime. We'll figure out how to
	// conditionally enqueue directives later.
	wp_enqueue_script('wp-directive-runtime');
}
add_action('wp_enqueue_scripts', 'wp_directives_register_scripts');

function wp_directives_add_wp_link_attribute($block_content)
{
	$site_url = parse_url(get_site_url());
	$w = new WP_HTML_Tag_Processor($block_content);
	while ($w->next_tag('a')) {
		if ($w->get_attribute('target') === '_blank') {
			break;
		}

		$link = parse_url($w->get_attribute('href'));
		if (!isset($link['host']) || $link['host'] === $site_url['host']) {
			$classes = $w->get_attribute('class');
			if (
				str_contains($classes, 'query-pagination') ||
				str_contains($classes, 'page-numbers')
			) {
				$w->set_attribute(
					'wp-link',
					'{ "prefetch": true, "scroll": false }'
				);
			} else {
				$w->set_attribute('wp-link', '{ "prefetch": true }');
			}
		}
	}
	return (string) $w;
}
// We go only through the Query Loops and the template parts until we find a better solution.
add_filter(
	'render_block_core/query',
	'wp_directives_add_wp_link_attribute',
	10,
	1
);
add_filter(
	'render_block_core/template-part',
	'wp_directives_add_wp_link_attribute',
	10,
	1
);

function wp_directives_client_site_transitions_meta_tag()
{
	if (apply_filters('client_side_transitions', false)) {
		echo '<meta itemprop="wp-client-side-transitions" content="active">';
	}
}
add_action('wp_head', 'wp_directives_client_site_transitions_meta_tag', 10, 0);

function wp_directives_client_site_transitions_option()
{
	$options = get_option('wp_directives_plugin_settings');
	return $options['client_side_transitions'];
}
add_filter(
	'client_side_transitions',
	'wp_directives_client_site_transitions_option',
	9
);

add_action('init', function () {
	register_block_type(__DIR__ . '/build/blocks/tabs');
});

add_filter('render_block_bhe/tabs', function ($content) {
	wp_enqueue_script(
		'bhe/tabs',
		plugin_dir_url(__FILE__) . 'build/blocks/tabs/view.js'
	);
	return $content;
});

add_filter(
	'render_block',
	function ($block_content, $block, $instance) {
		// Append the `wp-inner-block` attribute for inner blocks of interactive
		// blocks.
		if (isset($instance->parsed_block['markAsInnerBlock'])) {
			$w = new WP_HTML_Tag_Processor($block_content);
			$w->next_tag();
			$w->set_attribute('wpx-non-interactive', '');
			$block_content = (string) $w;
		}

		// Return if it's not interactive;
		if (!block_has_support($instance->block_type, ['interactivity'])) {
			return $block_content;
		}

		// Add the `wp-interactive-block` attribute if it's interactive.
		$w = new WP_HTML_Tag_Processor($block_content);
		$w->next_tag();
		$w->set_attribute('wpx-interactive', '');

		return (string) $w;
	},
	10,
	3
);

/**
 * Add a flag to mark inner blocks of interactive blocks.
 */
function bhe_inner_blocks($parsed_block, $source_block, $parent_block)
{
	if (
		isset($parent_block) &&
		block_has_support($parent_block->block_type, ['interactivity'])
	) {
		$parsed_block['markAsInnerBlock'] = true;
	}
	return $parsed_block;
}
add_filter('render_block_data', 'bhe_inner_blocks', 10, 3);
