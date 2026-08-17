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

/**
 * provides html radio-groups for network, blog and comment settings
 */
class Notification_Ui {
	use Static_Logger_Trait;

	/**
	 * Constructor.
	 */
	public function __construct() {}

	/**
	 * Renders the standalone notification settings on the WordPress user profile screen.
	 * Hooked to 'show_user_profile' and 'edit_user_profile' so the network default and the
	 * current-blog preference are reachable without the 'defaultspace' theme.
	 *
	 * @param \WP_User $user The profile being edited.
	 * @return void
	 */
	public static function render_profile_settings( \WP_User $user ) {
		// Only the profile owner and super admins may see/change these settings.
		if ( get_current_user_id() !== $user->ID && ! is_super_admin() ) {
			return;
		}

		$blog_id        = get_current_blog_id();
		$is_blog_member = is_user_member_of_blog( $user->ID, $blog_id );

		$title         = esc_html__( 'Email Notifications', 'scoped-notify' );
		$network_label = esc_html__( 'Default for all sites', 'scoped-notify' );
		$blog_name     = (string) get_blog_option( $blog_id, 'blogname' );
		$blog_name     = mb_strimwidth( $blog_name, 0, 40, '…' );
		/* translators: %s: site/blog title. */
		$blog_label      = sprintf( esc_html__( 'This site (%s)', 'scoped-notify' ), esc_html( $blog_name ) );
		$network_options = self::get_network_option_selector( $user->ID );

		echo '<h2 data-added-by="scoped-notify">' . $title . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "<table class='form-table' role='presentation'><tbody>";
		echo "<tr><th scope='row'>$network_label</th><td>$network_options</td></tr>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $is_blog_member ) {
			$blog_options = self::get_blog_option_selector( $blog_id, $user->ID );
			echo "<tr><th scope='row'>$blog_label</th><td>$blog_options</td></tr>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';
	}

	public static function get_current_network_setting( int $uid ): string {
		return User_Preferences::get_network_preference( $uid )->get_label();
	}

	/**
	 * create network option radiogroup
	 * @return string   html with radiogroup
	 */
	public static function get_network_option_selector( int $uid ) {
		$current_setting = User_Preferences::get_network_preference( $uid );

		$scope     = Scope::Network->value;
		$radioname = uniqid( 'scoped-notify-radiogroup-user-', true );

		$options = array(
			array(
				'label'   => Notification_Preference::Posts_Only->get_label(),
				'value'   => Notification_Preference::Posts_Only->value,
				'checked' => Notification_Preference::Posts_Only === $current_setting,
			),
			array(
				'label'   => Notification_Preference::Posts_And_Comments->get_label(),
				'value'   => Notification_Preference::Posts_And_Comments->value,
				'checked' => Notification_Preference::Posts_And_Comments === $current_setting,
			),
			array(
				'label'   => Notification_Preference::No_Notifications->get_label(),
				'value'   => Notification_Preference::No_Notifications->value,
				'checked' => Notification_Preference::No_Notifications === $current_setting,
			),
		);
		return "
			<div class='scoped-notify-options scoped-notify-options--network'>
				<ul
					data-scope='$scope'
					class='js-scoped-notify-radiogroup scoped-notify-options-list m-0 radio-accordion success pt-0'
				>
				" . self::get_options( $options, $radioname ) . '
				</ul>
				<div class="callout warning mt-4" data-closable style="display: none;">
					<div class="callout-content pr-3">Empty</div>
					<button class="close-button" aria-label="Dismiss alert" type="button" data-close>
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
			</div>
		';
	}

	/**
	 * create blog option radiogroup
	 * @param int      $blog_id  blog whose preferences to show.
	 * @param int|null $user_id  user whose preferences to show; defaults to the current user.
	 * @return string   html with radiogroup
	 */
	public static function get_blog_option_selector( int $blog_id, ?int $user_id = null ) {
		$user_id         = $user_id ?? wp_get_current_user()->ID;
		$current_setting = User_Preferences::get_blog_preference( $user_id, $blog_id );
		$default_setting = User_Preferences::get_network_preference( $user_id );
		$scope           = Scope::Blog->value;
		$radioname       = uniqid( 'scoped-notify-radiogroup-blog-' . $blog_id . '-', true );

		$options = array(
			array(
				'label'   => Notification_Preference::Posts_Only->get_label(),
				'value'   => Notification_Preference::Posts_Only->value,
				'checked' => Notification_Preference::Posts_Only === $current_setting,
			),
			array(
				'label'   => Notification_Preference::Posts_And_Comments->get_label(),
				'value'   => Notification_Preference::Posts_And_Comments->value,
				'checked' => Notification_Preference::Posts_And_Comments === $current_setting,
			),
			array(
				'label'   => Notification_Preference::No_Notifications->get_label(),
				'value'   => Notification_Preference::No_Notifications->value,
				'checked' => Notification_Preference::No_Notifications === $current_setting,
			),
			array(
				/* translators:  %1$s $default_setting */
				'label'   => \sprintf( \__( 'Use Default (%1$s)', 'scoped-notify' ), $default_setting->get_label() ),
				'value'   => 'use-default',
				'checked' => is_null( $current_setting ),
			),
		);
		return "
			<ul
				data-scope='$scope'
				data-blog-id='$blog_id'
				class='js-scoped-notify-radiogroup scoped-notify-options-list radio-accordion success p-3 pt-0'
			>
			" . self::get_options( $options, $radioname ) . '
			</ul>
		';
	}

	/**
	 * get list of radioitems
	 * @param array  $options
	 * @param string $radioname
	 * @return string   html with radiogroup
	 */
	private static function get_options( array $options, string $radioname ) {
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
	 * What a per-post comment switch says, wherever it is drawn.
	 *
	 * There is no markup for it here: a theme that offers the switch draws it
	 * itself and saves through `ScopedNotify.save()` (see js/scoped-notify.js).
	 * This is only the wording, so it stays translated in one place.
	 *
	 * @return string
	 */
	public static function comment_toggle_label(): string {
		return __( 'Notify me', 'scoped-notify' );
	}
}
