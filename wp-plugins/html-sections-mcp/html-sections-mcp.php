<?php
/**
 * Plugin Name: HTML Sections for MCP
 * Description: Adds two MCP abilities: append full width containers holding one Elementor HTML widget each to a DRAFT page, and read those widgets back with checksums. Refuses to touch published pages, the homepage, the posts page, or anything that is not a page.
 * Version:     1.1.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author:      Strouse Digital
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'html-sections',
			array(
				'label'       => 'HTML Sections',
				'description' => 'Site specific helpers for building Elementor pages from raw HTML files.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'html-sections/add-to-draft',
			array(
				'label'               => 'Add HTML sections to a draft Elementor page',
				'description'         => 'For each item in `sections`, appends one top level Elementor container (full width, zero padding) holding exactly one HTML widget whose content is `html`, stored byte for byte. Works ONLY on pages whose status is draft and that are not the homepage or posts page. mode "append" (default) keeps existing content; mode "replace" removes all existing top level elements first. Returns each new container id, widget id and the sha256 of the HTML as read back from the database.',
				'category'            => 'html-sections',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'ID of the draft page.',
						),
						'mode'     => array(
							'type'        => 'string',
							'enum'        => array( 'append', 'replace' ),
							'default'     => 'append',
							'description' => 'append keeps existing content, replace clears all top level elements first.',
						),
						'sections' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 50,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'label' => array(
										'type'        => 'string',
										'description' => 'Optional layer name shown in the Elementor Navigator (for example the source filename). Not rendered on the page.',
									),
									'html'  => array(
										'type'        => 'string',
										'description' => 'Raw HTML for the widget. Stored exactly as given.',
									),
								),
								'required'             => array( 'html' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'post_id', 'sections' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => 'html_sections_mcp_can_write',
				'execute_callback'    => 'html_sections_mcp_add',
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);

		wp_register_ability(
			'html-sections/list',
			array(
				'label'               => 'List HTML widgets on an Elementor page',
				'description'         => 'Read only. Returns the page status, slug and template, then every top level element in order with its layer name and the HTML widgets inside it (id, length, sha256 of the stored HTML).',
				'category'            => 'html-sections',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_pages' );
				},
				'execute_callback'    => 'html_sections_mcp_list',
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}
);

/**
 * Only editors who may post unfiltered HTML can write, otherwise WordPress would strip the code.
 */
function html_sections_mcp_can_write() {
	return current_user_can( 'edit_pages' ) && current_user_can( 'unfiltered_html' );
}

/**
 * Generates an Elementor style element id.
 */
function html_sections_mcp_new_id() {
	if ( class_exists( '\Elementor\Utils' ) && method_exists( '\Elementor\Utils', 'generate_random_string' ) ) {
		return \Elementor\Utils::generate_random_string();
	}
	return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
}

/**
 * Reads the decoded Elementor element tree straight from post meta.
 */
function html_sections_mcp_read_tree( $post_id ) {
	$raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	$tree = json_decode( $raw, true );
	return is_array( $tree ) ? $tree : array();
}

/**
 * Collects HTML widgets under an element, depth first, in document order.
 */
function html_sections_mcp_collect_html( array $element, array &$out ) {
	if ( isset( $element['elType'], $element['widgetType'] ) && 'widget' === $element['elType'] && 'html' === $element['widgetType'] ) {
		$html  = isset( $element['settings']['html'] ) ? (string) $element['settings']['html'] : '';
		$out[] = array(
			'widget_id' => isset( $element['id'] ) ? $element['id'] : '',
			'length'    => strlen( $html ),
			'sha256'    => hash( 'sha256', $html ),
		);
	}
	if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
		foreach ( $element['elements'] as $child ) {
			if ( is_array( $child ) ) {
				html_sections_mcp_collect_html( $child, $out );
			}
		}
	}
}

/**
 * Safety checks shared by the writer: draft page only, never the homepage or posts page.
 */
function html_sections_mcp_check_target( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'html_sections_not_found', 'Post not found.' );
	}
	if ( 'page' !== $post->post_type ) {
		return new WP_Error( 'html_sections_not_page', 'Only pages are allowed.' );
	}
	if ( 'draft' !== $post->post_status ) {
		return new WP_Error( 'html_sections_not_draft', 'Refusing to edit: page status is "' . $post->post_status . '", only drafts are allowed.' );
	}
	if ( (int) get_option( 'page_on_front' ) === (int) $post_id || (int) get_option( 'page_for_posts' ) === (int) $post_id ) {
		return new WP_Error( 'html_sections_protected', 'Refusing to edit the homepage or posts page.' );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'html_sections_forbidden', 'You cannot edit this page.' );
	}
	return $post;
}

/**
 * Execute callback for html-sections/add-to-draft.
 */
function html_sections_mcp_add( $input ) {
	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		return new WP_Error( 'html_sections_no_elementor', 'Elementor is not active.' );
	}

	$post_id  = (int) $input['post_id'];
	$mode     = isset( $input['mode'] ) ? $input['mode'] : 'append';
	$sections = $input['sections'];

	$post = html_sections_mcp_check_target( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
	if ( ! $document ) {
		return new WP_Error( 'html_sections_no_document', 'Could not load the Elementor document for this page.' );
	}

	$tree = ( 'replace' === $mode ) ? array() : html_sections_mcp_read_tree( $post_id );

	$expected = array();
	foreach ( $sections as $section ) {
		$html         = (string) $section['html'];
		$container_id = html_sections_mcp_new_id();
		$widget_id    = html_sections_mcp_new_id();

		$container_settings = array(
			'content_width' => 'full',
			'padding'       => array(
				'unit'     => 'px',
				'top'      => '0',
				'right'    => '0',
				'bottom'   => '0',
				'left'     => '0',
				'isLinked' => true,
			),
		);
		if ( ! empty( $section['label'] ) ) {
			$container_settings['_title'] = sanitize_text_field( $section['label'] );
		}

		$tree[] = array(
			'id'       => $container_id,
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => $container_settings,
			'elements' => array(
				array(
					'id'         => $widget_id,
					'elType'     => 'widget',
					'widgetType' => 'html',
					'isInner'    => false,
					'settings'   => array( 'html' => $html ),
					'elements'   => array(),
				),
			),
		);

		$expected[] = array(
			'label'        => isset( $section['label'] ) ? $section['label'] : '',
			'container_id' => $container_id,
			'widget_id'    => $widget_id,
			'sha256'       => hash( 'sha256', $html ),
			'length'       => strlen( $html ),
		);
	}

	// Save through Elementor so it records the edit mode, version, revision and clears this page's CSS file.
	$saved = $document->save( array( 'elements' => $tree ) );
	if ( false === $saved ) {
		return new WP_Error( 'html_sections_save_failed', 'Elementor refused to save the document.' );
	}

	// Read back from the database and confirm every widget is there with identical HTML.
	$stored = array();
	foreach ( html_sections_mcp_read_tree( $post_id ) as $element ) {
		if ( is_array( $element ) && isset( $element['id'] ) ) {
			$found = array();
			html_sections_mcp_collect_html( $element, $found );
			$stored[ $element['id'] ] = $found;
		}
	}

	$problems = array();
	foreach ( $expected as $i => $item ) {
		$found = isset( $stored[ $item['container_id'] ] ) ? $stored[ $item['container_id'] ] : array();
		if ( 1 !== count( $found ) ) {
			$problems[] = 'Section ' . ( $i + 1 ) . ' (' . $item['label'] . '): expected 1 HTML widget, found ' . count( $found ) . '.';
			continue;
		}
		$expected[ $i ]['stored_sha256'] = $found[0]['sha256'];
		$expected[ $i ]['exact_match']   = ( $found[0]['sha256'] === $item['sha256'] );
		if ( ! $expected[ $i ]['exact_match'] ) {
			$problems[] = 'Section ' . ( $i + 1 ) . ' (' . $item['label'] . '): stored HTML differs from input.';
		}
	}

	return array(
		'post_id'         => $post_id,
		'status'          => get_post_status( $post_id ),
		'mode'            => $mode,
		'added'           => $expected,
		'top_level_count' => count( $stored ),
		'all_exact'       => empty( $problems ),
		'problems'        => $problems,
		'edit_url'        => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
	);
}

/**
 * Execute callback for html-sections/list.
 */
function html_sections_mcp_list( $input ) {
	$post_id = (int) $input['post_id'];
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'html_sections_not_found', 'Post not found.' );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'html_sections_forbidden', 'You cannot read this page.' );
	}

	$elements    = array();
	$html_total  = 0;
	foreach ( html_sections_mcp_read_tree( $post_id ) as $index => $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$found = array();
		html_sections_mcp_collect_html( $element, $found );
		$html_total += count( $found );
		$elements[]  = array(
			'index'        => $index,
			'id'           => isset( $element['id'] ) ? $element['id'] : '',
			'elType'       => isset( $element['elType'] ) ? $element['elType'] : '',
			'label'        => isset( $element['settings']['_title'] ) ? $element['settings']['_title'] : '',
			'content_width' => isset( $element['settings']['content_width'] ) ? $element['settings']['content_width'] : '',
			'padding'      => isset( $element['settings']['padding'] ) ? $element['settings']['padding'] : null,
			'html_widgets' => $found,
		);
	}

	return array(
		'post_id'            => $post_id,
		'title'              => $post->post_title,
		'slug'               => $post->post_name,
		'status'             => $post->post_status,
		'template'           => get_post_meta( $post_id, '_wp_page_template', true ),
		'edit_mode'          => get_post_meta( $post_id, '_elementor_edit_mode', true ),
		'html_widget_count'  => $html_total,
		'top_level_elements' => $elements,
		'edit_url'           => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
	);
}
