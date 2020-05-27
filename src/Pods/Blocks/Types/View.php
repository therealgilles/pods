<?php

namespace Pods\Blocks\Types;

/**
 * View block functionality class.
 *
 * @since 2.8
 */
class View extends Base {

	/**
	 * Which is the name/slug of this block
	 *
	 * @since TBD
	 *
	 * @return string
	 */
	public function slug() {
		return 'pods-block-view';
	}

	/**
	 * Get block configuration to register with Pods.
	 *
	 * @since TBD
	 *
	 * @return array Block configuration.
	 */
	public function block() {
		return [
			'internal'        => true,
			'label'           => __( 'Pods View', 'pods' ),
			'description'     => __( 'Include a file from a theme, with caching options', 'pods' ),
			'namespace'       => 'pods',
			'renderType'      => 'php',
			'render_callback' => [ $this, 'render' ],
			'keywords'        => [
				'pods',
				'view',
				'include',
			],
		];
	}

	/**
	 * Get list of Field configurations to register with Pods for the block.
	 *
	 * @since TBD
	 *
	 * @return array List of Field configurations.
	 */
	public function fields() {
		return [
			[
				'name'    => 'view',
				'label'   => __( 'File to include from theme', 'pods' ),
				'type'    => 'text',
			],
			[
				'name'    => 'expires',
				'label'   => __( 'Expires (optional)', 'pods' ),
				'type'    => 'number',
				'default' => ( MINUTE_IN_SECONDS * 5 ),
			],
			[
				'name'    => 'cache_mode',
				'label'   => __( 'Cache Mode (optional)', 'pods' ),
				'type'    => 'text',
				'default' => 'none',
			],
		];
	}

	/**
	 * Since we are dealing with a Dynamic type of Block we need a PHP method to render it
	 *
	 * @since TBD
	 *
	 * @param array $attributes
	 *
	 * @return string
	 */
	public function render( $attributes = [] ) {
		$attributes = $this->attributes( $attributes );
		$attributes = array_map( 'trim', $attributes );

		if ( empty( $attributes['view'] ) ) {
			if ( is_admin() || wp_is_json_request() ) {
				return __( 'No preview available, please specify "View".', 'pods' );
			}

			return '';
		}

		// Prevent any previews of this block.
		if ( is_admin() || wp_is_json_request() ) {
			return __( 'No preview is available for this Pods View, you will see it on the frontend.', 'pods' );
		}

		return pods_shortcode( $attributes );
	}
}
