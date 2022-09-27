<?php
/**
 * Custom Facebook Feed block with live preview.
 *
 * @since 2.3
 */
namespace CustomFacebookFeed;

use CustomFacebookFeed\Helpers\Util;
use CustomFacebookFeed\Builder\CFF_Db;
use CustomFacebookFeed\Builder\CFF_Feed_Builder;

class CFF_Blocks {

	/**
	 * Indicates if current integration is allowed to load.
	 *
	 * @since 1.8
	 *
	 * @return bool
	 */
	public function allow_load() {
		return function_exists( 'register_block_type' );
	}

	/**
	 * Loads an integration.
	 *
	 * @since 2.3
	 */
	public function load() {
		$this->hooks();
	}

	/**
	 * Integration hooks.
	 *
	 * @since 2.3
	 */
	protected function hooks() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Register Custom Facebook Feed Gutenberg block on the backend.
	 *
	 * @since 2.3
	 */
	public function register_block() {

		wp_register_style(
			'cff-blocks-styles',
			trailingslashit( CFF_PLUGIN_URL ) . 'assets/css/cff-blocks.css',
			array( 'wp-edit-blocks' ),
			CFFVER
		);

		$attributes = array(
			'shortcodeSettings' => array(
				'type' => 'string',
			),
			'noNewChanges' => array(
				'type' => 'boolean',
			),
			'executed' => array(
				'type' => 'boolean',
			)
		);

		register_block_type(
			'cff/cff-feed-block',
			array(
				'attributes'      => $attributes,
				'render_callback' => array( $this, 'get_feed_html' ),
			)
		);
	}

	/**
	 * Load Custom Facebook Feed Gutenberg block scripts.
	 *
	 * @since 2.3
	 */
	public function enqueue_block_editor_assets() {
		$access_token = get_option('cff_access_token');

		wp_enqueue_style( 'cff-blocks-styles' );
		wp_enqueue_script(
			'cff-feed-block',
			trailingslashit( CFF_PLUGIN_URL ) . 'assets/js/cff-blocks.js',
			array( 'wp-blocks', 'wp-i18n', 'wp-element' ),
			CFFVER,
			true
		);

		$shortcodeSettings = '';

		$i18n = array(
			'addSettings'         => esc_html__( 'Add Settings', 'custom-facebook-feed' ),
			'shortcodeSettings'   => esc_html__( 'Shortcode Settings', 'custom-facebook-feed' ),
			'example'             => esc_html__( 'Example', 'custom-facebook-feed' ),
			'preview'             => esc_html__( 'Apply Changes', 'custom-facebook-feed' ),

		);

		if ( ! empty( $_GET['cff_wizard'] ) ) {
			$shortcodeSettings = 'feed="' . (int)sanitize_text_field( wp_unslash( $_GET['cff_wizard'] ) ) . '"';
		}

		wp_localize_script(
			'cff-feed-block',
			'cff_block_editor',
			array(
				'wpnonce'  => wp_create_nonce( 'facebook-blocks' ),
				'canShowFeed' => ! empty( $access_token ),
				'configureLink' => get_admin_url() . '?page=cff-top',
				'shortcodeSettings'    => $shortcodeSettings,
				'i18n'     => $i18n,
			)
		);


		\cff_main_pro()->enqueue_styles_assets();
		\cff_main_pro()->enqueue_scripts_assets();
	}

	/**
	 * Get form HTML to display in a Custom Facebook Feed Gutenberg block.
	 *
	 * @param array $attr Attributes passed by Custom Facebook Feed Gutenberg block.
	 *
	 * @since 2.3
	 *
	 * @return string
	 */
	public function get_feed_html( $attr ) {
		$feeds_count = CFF_Db::feeds_count();
		$shortcode_settings = isset( $attr['shortcodeSettings'] ) ? $attr['shortcodeSettings'] : '';
		if ( $feeds_count <= 0 ) {
			return $this->plain_block_design( empty( cff_main_pro()->cff_license_handler->get_license_key ) ? 'inactive' : 'expired' );
		}

		$return = '';
		$return .= $this->get_license_expired_notice();

		if ( empty( $cff_statuses['support_legacy_shortcode'] ) ) {
			if ( empty( $shortcode_settings ) || strpos( $shortcode_settings, 'feed=' ) === false ){
				$feeds = \CustomFacebookFeed\Builder\CFF_Feed_Builder::get_feed_list();
				if ( ! empty( $feeds[0]['id'] ) ) {
					$shortcode_settings = 'feed="' . (int) $feeds[0]['id'] . '"';
				}
			}
		}

		$shortcode_settings = str_replace(array( '[custom-facebook-feed', ']' ), '', $shortcode_settings);

		$return .= do_shortcode( '[custom-facebook-feed '.$shortcode_settings.']' );

		return $return;

	}

	public function get_license_expired_notice() {
		// Check that the license exists and the user hasn't already clicked to ignore the message
		if ( empty( cff_main_pro()->cff_license_handler->get_license_key ) ) {
			return $this->get_license_expired_notice_content( 'inactive' );
		}
		// If license not expired then return;
		if ( !cff_main_pro()->cff_license_handler->is_license_expired ) {
			return;
		}
		// Grace period ended?
		if ( ! cff_main_pro()->cff_license_handler->is_license_grace_period_ended( true ) ) {
			return;
		}
		
		return $this->get_license_expired_notice_content();
	}

	/**
	 * Output the license expired notice content on top of the embed block 
	 * 
	 * @since 4.4.0
	 */
	public function get_license_expired_notice_content( $license_state = 'expired' ) {
		if ( !is_admin() && !defined( 'REST_REQUEST' ) ) {
			return;
		}

		$icons = CFF_Feed_Builder::builder_svg_icons();

		$output = '<div class="cff-block-license-expired-notice-ctn cff-bln-license-state-'. $license_state .'">';
			$output .= '<div class="cff-blen-header">';
				$output .= $icons['eye2'];
				$output .= '<span>' . __('Only Visible to WordPress Admins', 'custom-facebook-feed') . '</span>';
			$output .= '</div>';
			$output .= '<div class="cff-blen-resolve">';
				$output .= '<div class="cff-left">';
					$output .= $icons['info'];
					if ( $license_state == 'inactive' ) {
						$output .= '<span>' . __('Your license key is inactive. Activate it to enable Pro features.', 'custom-facebook-feed') . '</span>';
					} else {
						$output .= '<span>' . __('Your license has expired! Renew it to reactivate Pro features.', 'custom-facebook-feed') . '</span>';
					}
				$output .= '</div>';
				$output .= '<div class="cff-right">';
					$output .= '<a href="'. cff_main_pro()->cff_license_handler->get_renew_url( $license_state ) .'" target="_blank">'. __('Resolve Now', 'custom-facebook-feed') .'</a>';
					$output .= $icons['chevronRight'];
				$output .= '</div>';
			$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Plain block design when theres no feeds.
	 * 
	 * @since 4.4.0
	 */
	public function plain_block_design( $license_state = 'expired' ) {
		if ( !is_admin() && !defined( 'REST_REQUEST' ) ) {
			return;
		}
		$other_plugins = $this->get_others_plugins();
		$should_display_license_notice = cff_main_pro()->cff_license_handler->should_disable_pro_features;
		$icons = CFF_Feed_Builder::builder_svg_icons();
		$output = '<div class="cff-license-expired-plain-block-wrapper '. $license_state .'">';

		if ( $should_display_license_notice ) :
			$output .= '<div class="cff-lepb-header">
				<div class="sb-left">';
					$output .= $icons['info'];
					if ( $license_state == 'expired' ) {
						$output .= sprintf('<p>%s</p>', __('Your license has expired! Renew it to reactivate Pro features.', 'custom-facebook-feed'));
					} else {
						$output .= sprintf('<p>%s</p>', __('Your license key is inactive. Activate it to enable Pro features.', 'custom-facebook-feed'));
					}
			$output .= '</div>
				<div class="sb-right">
					<a href="'. cff_main_pro()->cff_license_handler->get_renew_url( $license_state ) .'">
						Resolve Now
						'. $icons['chevronRight'] .'
					</a>
				</div>
			</div>';
		endif;
			$output .= '<div class="cff-lepb-body">
				'. $icons['blockEditorCFFLogo'] .'
				<p class="cff-block-body-title">Get started with your first feed from <br/> your Instagram profile</p>';
		
		$output .= sprintf(
					'<a href="%s" class="cff-btn cff-btn-blue">%s '. $icons['chevronRight'] .'</a>', 
					admin_url('admin.php?page=cff-feed-builder'), 
					__('Create a Facebook Feed', 'custom-facebook-feed')
				);
		$output .= '</div>
			<div class="cff-lepd-footer">
				<p class="cff-lepd-footer-title">Did you know? </p>
				<p>You can add posts from '. $other_plugins .' using our free plugins</p>
			</div>
		</div>';

		return $output;
	}


	/**
	 * Get other Smash Balloon plugins list
	 * 
	 * @since 4.4.0
	 */
	public function get_others_plugins() {
		$active_plugins = Util::get_sb_active_plugins_info();

		$other_plugins = array(
			'is_instagram_installed' => array(
				'title' => 'Instagram',
				'url'	=> 'https://smashballoon.com/instagram-feed/?utm_campaign=youtube-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
			),
			'is_facebook_installed' => array(
				'title' => 'Facebook',
				'url'	=> 'https://smashballoon.com/custom-facebook-feed/?utm_campaign=youtube-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
			),
			'is_twitter_installed' => array(
				'title' => 'Twitter',
				'url'	=> 'https://smashballoon.com/custom-twitter-feeds/?utm_campaign=youtube-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
			),
			'is_youtube_installed' => array(
				'title' => 'YouTube',
				'url'	=> 'https://smashballoon.com/youtube-feed/?utm_campaign=youtube-pro&utm_source=block-feed-embed&utm_medium=did-you-know',
			),
		);

		if ( ! empty( $active_plugins ) ) {
			foreach ( $active_plugins as $name => $plugin ) {
				if ( $plugin != false ) {
					unset( $other_plugins[$name] );
				}
			}
		}

		$other_plugins_html = array();
		foreach( $other_plugins as $plugin ) {
			$other_plugins_html[] = '<a href="'. $plugin['url'] .'">'. $plugin['title'] .'</a>';
		}
		
		return \implode(", ", $other_plugins_html);
	}

	/**
	 * Checking if is Gutenberg REST API call.
	 *
	 * @since 2.3
	 *
	 * @return bool True if is Gutenberg REST API call.
	 */
	public static function is_gb_editor() {

		// TODO: Find a better way to check if is GB editor API call.
		return defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_REQUEST['context'] ) && 'edit' === $_REQUEST['context']; // phpcs:ignore
	}

}
