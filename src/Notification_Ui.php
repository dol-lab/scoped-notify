<?php
/**
 * Provides UI components to configure notification settings
 *
 * @package Scoped_Notify
 */
declare(strict_types=1);

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Use fully qualified names for WP classes
use WP_Post;
use WP_Comment;

/**
 * provides html radiogroups for network, blog and comment settings
 */
class Notification_Ui {
	use Static_Logger_Trait;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		// $logger->info( '**** Scoped_Notify_Ui ****' );
	}

	/**
	 * adds radiogroup with network settings, called by filter 'ds_child_member_profile_dot_menu_data' in theme 'defaultspace-child-member'
	 * @param string $html
	 * @return string   $html with added radiogroup
	 */
	public function add_network_settings_item( string $html ) {
		$html .= "
			<div class='card-divider'>
			" . $this->get_network_option_selector() . '
			</div>
		';
		return $html;
	}

	/**
	 * adds radiogroup with blog settings wrapped as settingsitem, called by filter 'default_space_setting' in theme 'defaultspace'
	 * @param array $settings_items
	 * @return array    $settings_items with added radiogroup
	 */
	public function add_blog_settings_item( array $settings_items ) {
		$blog_id = get_current_blog_id();
		if ( ! is_user_member_of_blog( wp_get_current_user()->ID, $blog_id ) ) {
			return $settings_items;
		}

		$sn_settings = array(
			'id'    => 'scoped-notify-blog-notification',
			'class' => 'scoped-notify-options scoped-notify-options--blog',
			'data'  => array(
				'scoped_notify_icon'     => 'fa-envelope',
				'scoped_notify_headline' => esc_html__( 'Mail Notifications', 'scoped-notify' ),
				'scoped_notify_selector' => $this->get_blog_option_selector( $blog_id ),
			),
			'html'  => fn( $d ) => "
				<a href='#'>
					<i class='fa {$d['scoped_notify_icon']} scoped-notify-icon' aria-hidden='true'></i>
					<span>{$d['scoped_notify_headline']}</span>
				</a>
				{$d['scoped_notify_selector']}
			",
		);
		array_splice( $settings_items, 1, 0, array( $sn_settings ) );
		return $settings_items;
	}

	/**
	 * adds radiogroup with blog settings as shortcode
	 * @return string html
	 * @todo "Home" should be replaced by the blog name
	 */
	public function get_notification_toggle_switch() {
		$blog_name = \get_bloginfo( 'name' );
		return "
		<div class='card'>
			<div class='card-section'>
				<div class='scoped-notify-options scoped-notify-options--blog'>
					<div class='scoped-notify-options-title'>"
						/* translators: %s blog name */
						. \sprintf( \esc_html__( 'Mail Notifications for %s', 'scoped-notify' ), $blog_name ) . '
					</div>
					' . $this->get_blog_option_selector( get_current_blog_id() ) . '
				</div>
			</div>
		</div>
		';
	}

	/**
	 * adds toggle with comment notification settings, called by filter 'ds_post_dot_menu_data' in theme 'defaultspace'
	 * @param array   $buttons
	 * @param WP_Post $post The post object.
	 * @return array    $buttons with added toggle
	 */
	public function add_comment_settings_item( array $buttons, WP_Post $post ) {
		$blog_id = get_current_blog_id();
		if ( ! is_user_member_of_blog( wp_get_current_user()->ID, $blog_id ) ) {
			return $buttons;
		}
		if ( $post->post_type !== 'post' ) {
			return $buttons;
		}

		$sn_settings = array(
			'html' => $this->get_comment_toggle( $blog_id, $post->ID ),
			'show' => true,
		);
		array_unshift( $buttons, $sn_settings );
		return $buttons;
	}

	/**
	 * create network option radiogroup
	 * @return string   html with radiogroup
	 */
	private function get_network_option_selector() {
		$current_setting = User_Preferences::get_network_preference( wp_get_current_user()->ID );
		$scope           = Scope::Network->value;
		$radioname       = uniqid( 'scoped-notify-radiogroup-user-', true );

		$options = array(
			array(
				'label'   => Notification_Preference::Posts_Only->getLabel(),
				'value'   => Notification_Preference::Posts_Only->value,
				'checked' => Notification_Preference::Posts_Only === $current_setting,
			),
			array(
				'label'   => Notification_Preference::Posts_And_Comments->getLabel(),
				'value'   => Notification_Preference::Posts_And_Comments->value,
				'checked' => Notification_Preference::Posts_And_Comments === $current_setting,
			),
			array(
				'label'   => Notification_Preference::No_Notifications->getLabel(),
				'value'   => Notification_Preference::No_Notifications->value,
				'checked' => Notification_Preference::No_Notifications === $current_setting,
			),
		);
		return "
			<div class='scoped-notify-options scoped-notify-options--network'>
				<div class='scoped-notify-options-title'>
				" . esc_html__( 'Mail Notifications', 'scoped-notify' ) . "
				</div>
				<ul
					data-scope='$scope'
					class='js-scoped-notify-radiogroup scoped-notify-options-list radio-accordion success padding-medium padding-top-0'
				>
				" . $this->get_options( $options, $radioname ) . '
				</ul>
			</div>
		';
	}

	/**
	 * create blog option radiogroup
	 * @param int $blog_id
	 * @return string   html with radiogroup
	 */
	private function get_blog_option_selector( int $blog_id ) {
		$user_id         = wp_get_current_user()->ID;
		$current_setting = User_Preferences::get_blog_preference( $user_id, $blog_id );
		$default_setting = User_Preferences::get_network_preference( $user_id );
		$scope           = Scope::Blog->value;
		$radioname       = uniqid( 'scoped-notify-radiogroup-blog-' . $blog_id . '-', true );

		$options = array(
			array(
				'label'   => Notification_Preference::Posts_Only->getLabel(),
				'value'   => Notification_Preference::Posts_Only->value,
				'checked' => Notification_Preference::Posts_Only === $current_setting,
			),
			array(
				'label'   => Notification_Preference::Posts_And_Comments->getLabel(),
				'value'   => Notification_Preference::Posts_And_Comments->value,
				'checked' => Notification_Preference::Posts_And_Comments === $current_setting,
			),
			array(
				'label'   => Notification_Preference::No_Notifications->getLabel(),
				'value'   => Notification_Preference::No_Notifications->value,
				'checked' => Notification_Preference::No_Notifications === $current_setting,
			),
			array(
				/* translators:  %1$s $default_setting */
				'label'   => \sprintf( \__( 'Use Default (%1$s)', 'scoped-notify' ), $default_setting->getLabel() ),
				'value'   => 'use-default',
				'checked' => is_null( $current_setting ),
			),
		);
		return "
			<ul
				data-scope='$scope'
				data-blog-id='$blog_id'
				class='js-scoped-notify-radiogroup scoped-notify-options-list radio-accordion success padding-medium padding-top-0'
			>
			" . $this->get_options( $options, $radioname ) . '
			</ul>
		';
	}

	/**
	 * get list of radioitems
	 * @param array  $options
	 * @param string $radioname
	 * @return string   html with radiogroup
	 */
	private function get_options( array $options, string $radioname ) {
		$html = '';
		foreach ( $options as $option ) {
			$radio_id = uniqid( 'scoped-notify-radioitem-', true );
			$checked  = $option['checked'] ? 'checked=checked' : '';
			$value    = $option['value'];
			$label    = $option['label'];

			$html .= "
				<li class='radio-accordion-item'>
					<div class='radio'>
						<label class='label-wrapper scoped-notify-radio-label' for='$radio_id'>
							<input
								type='radio'
								id='$radio_id'
								class='radio-input'
								name='$radioname'
								value='$value'
								$checked
							/>
							<span>$label</span>
							<label for='$radio_id' class='radio-label flex-spacer-left'>
								<span class='show-for-sr'>$label</span>
							</label>
						</label>
					</div>
				</li>
			";
		}
		return $html;
	}


	/**
	 * html for comment toggle switch
	 * @param int $blog_id
	 * @param int $post_id
	 * @return string   html with toggle
	 */
	private function get_comment_toggle( int $blog_id, int $post_id ) {
		$current_setting = User_Preferences::get_post_toggle_state( wp_get_current_user()->ID, $blog_id, $post_id );
		$scope           = Scope::Post->value;
		$toggle_id       = uniqid( 'scoped-notify-toggle-', true );
		$label           = esc_html__( 'Notify me', 'scoped-notify' );
		$checked         = $current_setting ? 'checked=checked' : '';

		return "
			<label class='icon-left label-wrapper' for='$toggle_id'>
				<i class='fa fa-envelope' aria-hidden='true'></i>
				<span>$label</span>
				<div class='switch small success' title=''>
					<input
						class='switch-input js-scoped-notify-comment-toggle'
						id='$toggle_id'
						data-scope='$scope'
						data-blog-id='$blog_id'
						data-post-id='$post_id'
						type='checkbox'
						$checked
					>
					<label class='switch-paddle' for='$toggle_id'>
						<span class='show-for-sr'>$label</span>
					</label>
				</div>
			</label>
		";
	}
}
