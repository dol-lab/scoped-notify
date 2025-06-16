<?php
/**
 * Scoped_Notify_Core
 *
 * @package Scoped_Notify
 */

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
	public function __construct(  ) {
		// $logger->info( '**** Scoped_Notify_Ui ****' );
	}

	/**
	 * adds radiogroup with network settings, called by filter 'ds_child_member_profile_dot_menu_data' in theme 'defaultspace-child-member'
	 * @param array 	$buttons
	 * @return array	$buttons with added radiogroup
	 */
	public function add_network_settings_item( $buttons ) {
		$sn_settings = array(
			'html' => $this->get_network_option_selector(),
			'show' => true,
		);
		array_unshift($buttons, $sn_settings);
		return $buttons;
	}

	/**
	 * adds radiogroup with blog settings wrapped as settingsitem, called by filter 'default_space_setting' in theme 'defaultspace'
	 * @param array 	$settings_items
	 * @return array	$settings_items with added radiogroup
	 */
	public function add_blog_settings_item( $settings_items ) {
		$sn_settings = array(
			'id'    => 'scoped-notify-blog-notification',
			'data'  => array(
				'scoped_notify_icon'     	=> 'fa-envelope',
				'scoped_notify_headline'	=> esc_html__( 'Notify Me', 'scoped-notify' ),
				'scoped_notify_selector'	=> $this->get_blog_option_selector( get_current_blog_id() ),
			),
			'html'  => fn( $d) => "
				<a href='#'>
					<i class='fa {$d['scoped_notify_icon']} scoped-notify-icon' aria-hidden='true'></i>
					<span>{$d['scoped_notify_headline']}</span>
				</a>
				{$d['scoped_notify_selector']}
			",
		);
		array_splice( $settings_items, 1, 0, [ $sn_settings ] );
		return $settings_items;
	}

	/**
	 * adds radiogroup with blog settings as shortcode
	 * @return array
	 */
	public function get_notification_toggle_switch() {
		return $this->get_blog_option_selector( get_current_blog_id() );
	}

	/**
	 * adds radiogroup with comment settings, called by filter 'ds_post_dot_menu_data' in theme 'defaultspace'
	 * @param array 	$buttons
	 * @param WP_Post	$post The post object.
	 * @return array	$buttons with added radiogroup
	 */
	public function add_comment_settings_item( $buttons, $post ) {
		$blog_id = get_current_blog_id();
		$sn_settings = array(
			'html' => $this->get_comment_option_selector($blog_id, $post->ID),
			//'show' => 'page' !== $post->post_type,
			'show' => true,
		);
		array_unshift($buttons, $sn_settings);
		return $buttons;
	}

	/**
	 * create network option radiogroup
	 * @return string	html with radiogroup
	 */
	private function get_network_option_selector() {
		$current_setting = User_Preferences::get_network_preference( wp_get_current_user()->ID );
		$scope           = Scope::Network->value;
		$random				= substr( md5( mt_rand() ), 0, 7 );
		$radioname			= "scoped-notify-group-user-" . $random;

		$options = array(
			array(
				'label'   => esc_html__( 'Posts', 'scoped-notify' ),
				'value'   => 'posts-only',
				'checked' => Notification_Preference::Posts_Only === $current_setting,
			),
			array(
				'label'   => esc_html__( 'Posts and Comments', 'scoped-notify' ),
				'value'   => 'posts-and-comments',
				'checked' => Notification_Preference::Posts_And_Comments === $current_setting,
			),
			array(
				'label'   => esc_html__( 'No Notifications', 'scoped-notify' ),
				'value'   => 'no-notifications',
				'checked' => Notification_Preference::No_Notifications === $current_setting,
			),
			array(
				'label'		=> esc_html__( 'Use system default', 'scoped-notify' ),
				'value'		=> 'use-default',
				'checked'	=> is_null($current_setting),
			),
		);
		return "
			<div>
			" . esc_html__( 'Global notification settings', 'scoped-notify' ) . "
			</div>
			<ul
				data-scope='$scope'
				class='scoped-notify-options radio-accordion icons icon-left success'
			>
			" . $this->get_options( $options, $radioname ) . "
			</ul>
		";
	}

	/**
	 * create blog option radiogroup
	 * @param int 	$blog_id
	 * @return string	html with radiogroup
	 */
	private function get_blog_option_selector( $blog_id ) {
		$current_setting	= User_Preferences::get_blog_preference( wp_get_current_user()->ID, $blog_id );
		$scope				= Scope::Blog->value;
		$random				= substr( md5( mt_rand() ), 0, 7 );
		$radioname			= "scoped-notify-group-blog-" . $random . "-" . $blog_id;

		$options = array(
			array(
				'label'   => esc_html__( 'Posts', 'scoped-notify' ),
				'value'   => 'posts-only',
				'checked' => Notification_Preference::Posts_Only === $current_setting,
			),
			array(
				'label'   => esc_html__( 'Posts and Comments', 'scoped-notify' ),
				'value'   => 'posts-and-comments',
				'checked' => Notification_Preference::Posts_And_Comments === $current_setting,
			),
			array(
				'label'   => esc_html__( 'No Notifications', 'scoped-notify' ),
				'value'   => 'no-notifications',
				'checked' => Notification_Preference::No_Notifications === $current_setting,
			),
			array(
				'label'		=> esc_html__( 'Use profile default', 'scoped-notify' ),
				'value'		=> 'use-default',
				'checked'	=> is_null($current_setting),
			),
		);
		return "
			<ul
				data-scope='$scope'
				data-blog-id='$blog_id'
				class='scoped-notify-options radio-accordion icons icon-left success'
			>
			" . $this->get_options( $options, $radioname ) . "
			</ul>
		";
	}

	/**
	 * create comment option radiogroup
	 * @param int 	$blog_id
	 * @param int 	$post_id
	 * @return string	html with radiogroup
	 */
	private function get_comment_option_selector( $blog_id, $post_id ) {
		$current_setting	= User_Preferences::get_post_preference( wp_get_current_user()->ID, $blog_id, $post_id );
		$scope				= Scope::Post->value;
		$random				= substr( md5( mt_rand() ), 0, 7 );
		$radioname		 	= "scoped-notify-group-post-" . $random . "-" . $post_id;

		$options = array(
			array(
				'label'		=> esc_html__( 'Notifications for Comments', 'scoped-notify' ),
				'value'		=> 'yes-notifications',
				'checked' => Notification_Preference::Posts_And_Comments === $current_setting,
			),
			array(
				'label'   => esc_html__( 'No Notifications', 'scoped-notify' ),
				'value'   => 'no-notifications',
				'checked' => Notification_Preference::No_Notifications === $current_setting,
			),
			array(
				'label'		=> esc_html__( 'Use space default', 'scoped-notify' ),
				'value'		=> 'use-default',
				'checked'	=> is_null($current_setting),
			),
		);
		return "
			<ul
				data-scope='$scope'
				data-blog-id='$blog_id'
				data-post-id='$post_id'
				class='scoped-notify-options radio-accordion icons icon-left success'
			>
			" . $this->get_options( $options, $radioname ) . "
			</ul>
		";
	}

	/**
	 * create options
	 * @param array 	$options
	 * @param string 	$radioname
	 * @return string	html with radiogroup
	 */
	private function get_options( $options, $radioname ) {
		$html = '';
		foreach ( $options as $option ) {
			$random		= substr( md5( mt_rand() ), 0, 7 );
			$checked	= $option['checked'] ? 'checked=checked' : '';
			$value		= $option['value'];
			$label		= $option['label'];

			$html .= "
				<li class='radio-accordion-item'>
					<div class='radio'>
						<label class='label-wrapper' for='scoped-notify-$random'>
							<input
								type='radio'
								id='scoped-notify-$random'
								class='radio-input'
								name='$radioname'
								value='$value'
								$checked
							/>
							<span>$label</span>
							<label for='scoped-notify-$random' class='radio-label flex-spacer-left'>
								<span class='show-for-sr'>$label</span>
							</label>
						</label>
					</div>
				</li>
			";
		}
		return $html;
	}
}
