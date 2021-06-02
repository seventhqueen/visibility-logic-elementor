<?php

namespace Stax\VisibilityLogic;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Upgrades
 *
 * @package Stax\VisibilityLogic
 */
class Upgrades extends Singleton {

	/**
	 * Option name that gets saved in the options database table
	 *
	 * @var string
	 */
	private $option_name = 'stax_visibility_db_version';

	/**
	 * Current plugin version
	 *
	 * @var string
	 */
	private $version = STAX_VISIBILITY_VERSION;

	/**
	 * Keep track if the data was updated during current request
	 *
	 * @var bool
	 */
	private $updated = null;

	/**
	 * Upgrade versions and method callbacks
	 *
	 * @var array
	 */
	private $upgrades = [
		'1.3.0' => [
			'method'  => '_upgrade_130',
			'confirm' => false,
		],
	];

	/**
	 * Upgrades constructor.
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'admin_notices', [ $this, 'admin_notice' ], 20 );
	}

	/**
	 * Show admin notice to update database
	 */
	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->is_new_update() ) {

			$this->process_update_action();

			if ( $this->updated === null ) {
				$url = wp_nonce_url( add_query_arg( 'stax_visibility_db_update', '' ), 'action' );
				?>

				<div class="notice stax-visibility-notice">
					<div class="stax-visibility-inner-message">
						<div class="stax-visibility-message-center">
							<h3><?php _e( 'Database update required', 'visibility-logic-elementor' ); ?></h3>
							<p>
								<?php
								echo wp_kses_post(
									sprintf(
										__( '<strong>STAX Visibility Logic</strong> needs to update your database to the latest version.', 'visibility-logic-elementor' ),
										esc_url( $url )
									)
								);
								?>
							</p>
						</div>
						<div class="stax-visibility-msg-button-right">
							<?php echo wp_kses_post( sprintf( __( '<a href="%s">Update now</a>', 'visibility-logic-elementor' ), esc_url( $url ) ) ); ?>
						</div>

					</div>
				</div>

				<style>
					.stax-visibility-notice {
						border-left-color: #262cbd;
					}

					.stax-visibility-notice .stax-visibility-inner-message {
						display: flex;
						flex-wrap: wrap;
						justify-items: center;
						justify-content: space-between;
					}

					.stax-visibility-notice .stax-visibility-inner-message h3 {
						margin: .5em 0;
					}

					.stax-visibility-notice .stax-visibility-inner-message .stax-visibility-msg-button-right {
						display: flex;
						align-items: center;
					}

					.stax-visibility-notice .stax-visibility-inner-message .stax-visibility-msg-button-right a {
						background-image: linear-gradient(180deg, #262cbd, #3d42cc);
						color: #fff;
						border-radius: 4px;
						padding: 8px 12px;
						text-decoration: none;
						display: inline-block;
					}
				</style>
				<?php
			}
		}
	}

	/**
	 * Check if we have a new version update
	 *
	 * @return bool
	 */
	private function is_new_update() {
		$old_upgrades    = get_option( $this->option_name ) ?: [ '1.2.0' ];
		$current_version = $this->version;

		foreach ( $this->upgrades as $version => $upgrade ) {
			if ( ! isset( $old_upgrades[ $version ] ) && version_compare( $current_version, $version, '>=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Handle all the versions upgrades
	 */
	public function run() {
		$updated         = false;
		$old_upgrades    = get_option( $this->option_name, [] );
		$errors          = false;
		$current_version = $this->version;

		foreach ( $this->upgrades as $version => $upgrade ) {

			// Run manual updates only when requested.
			if ( isset( $upgrade['confirm'] ) && $upgrade['confirm'] ) {
				if ( ! isset( $_REQUEST['stax_visibility_db_update'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'action' ) ) {
					continue;
				}
			}

			if ( ! isset( $old_upgrades[ $version ] ) && version_compare( $current_version, $version, '>=' ) ) {

				// Run the upgrade.
				$method         = $upgrade['method'];
				$upgrade_result = $this->$method();

				// Early exit the loop if an error occurs.
				if ( true === $upgrade_result ) {
					$old_upgrades[ $version ] = true;
					$updated                  = true;
				} else {
					$errors = true;
					break;
				}

			}
		}

		if ( ! $updated ) {
			return;
		}

		if ( false === $errors ) {
			$this->updated = true;
		}

		// Save successful upgrades.
		update_option( $this->option_name, $old_upgrades );

	}

	/**
	 * Call the upgrade function and conditionally show admin notice
	 */
	private function process_update_action() {

		$this->run();

		if ( true === $this->updated ) {
			echo '<div class="notice notice-success">' .
			     '<p>' .
			     esc_html__( 'Awesome, Visibility Logic database is now at the latest version!', 'visibility-logic-elementor' ) .
			     '</p>
         		</div>';
		} elseif ( false === $this->updated ) {
			echo '<div class="notice notice-warning">' .
			     '<p>' .
			     esc_html__( 'Something went wrong, please check logs.', 'visibility-logic-elementor' ) .
			     '</p>
         		</div>';
		}

	}

	/**
	 * @return bool
	 */
	private function _upgrade_130() {
		global $wpdb;

		$r = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->postmeta} WHERE meta_key = '%s'",
				'_elementor_data'
			)
		);

		foreach ( $r as $item ) {
			$data = @json_decode( $item->meta_value, true );

			if ( ! is_array( $data ) || empty( $data ) ) {
				continue;
			}

			foreach ( $data as &$data_item ) {
				$data_item = $this->_upgrade_130_recursive( $data_item );
			}

			update_post_meta( $item->post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		}

		return true;
	}

	/**
	 * Recursieve function for upgrade 130
	 *
	 * @param array $item
	 * @return array
	 */
	private function _upgrade_130_recursive( $item ) {
		if ( 'column' !== $item['elType'] ) {
			if ( isset( $item['settings']['ecl_role_visible'] ) && ! empty( $item['settings']['ecl_role_visible'] ) ) {
				$item['settings'][ self::SECTION_PREFIX . 'user_role_conditions' ] = $item['settings']['ecl_role_visible'];
				$item['settings'][ self::SECTION_PREFIX . 'show_hide' ]            = 'yes';
			} elseif ( isset( $item['settings']['ecl_role_hidden'] ) && ! empty( $item['settings']['ecl_role_hidden'] ) ) {
				$item['settings'][ self::SECTION_PREFIX . 'user_role_conditions' ] = $item['settings']['ecl_role_hidden'];
				$item['settings'][ self::SECTION_PREFIX . 'show_hide' ]            = '';
			}

			if ( isset( $item['settings']['ecl_enabled'] ) && $item['settings']['ecl_enabled'] ) {
				$item['settings'][ self::SECTION_PREFIX . 'enabled' ]           = 'yes';
				$item['settings'][ self::SECTION_PREFIX . 'user_role_enabled' ] = 'yes';
			}
		}

		if ( isset( $item['elements'] ) && ! empty( $item['elements'] ) ) {
			foreach ( $item['elements'] as &$sub_item ) {
				$sub_item = $this->_upgrade_130_recursive( $sub_item );
			}
		}

		return $item;
	}
}

Upgrades::instance();
