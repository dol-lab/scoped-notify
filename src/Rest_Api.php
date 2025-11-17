<?php
/**
 * TODO
 * @phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing
 *
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

use WP_REST_Response;

class Rest_Api {
	use Static_Logger_Trait;

	const NAMESPACE = 'scoped-notify/v1';

	const ROUTE_SETTINGS = '/settings';
	const ROUTE_NTFY_CONFIG = '/ntfy-config';
	const ROUTE_NTFY_GET = '/ntfy-config/(?P<blog_id>\d+)';

	/**
	 * register REST API routes
	 */
	public static function register_routes(): void {
		register_rest_route(
			route_namespace: self::NAMESPACE,
			route: self::ROUTE_SETTINGS,
			args: array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => self::set_user_preferences( ... ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			),
		);

		register_rest_route(
			route_namespace: self::NAMESPACE,
			route: self::ROUTE_NTFY_CONFIG,
			args: array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => self::save_ntfy_config( ... ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			),
		);

		register_rest_route(
			route_namespace: self::NAMESPACE,
			route: self::ROUTE_NTFY_GET,
			args: array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => self::get_ntfy_config( ... ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			),
		);
	}

	/**
	 * set user preferences
	 * @todo: allow super-admin to set preferences for other users -> send user_id as param.
	 */
	private static function set_user_preferences( \WP_REST_Request $request ): WP_REST_Response {
		$logger = self::logger();

		try {
			// check if given scope exists
			$scope = Scope::tryFrom( $request['scope'] );
			if ( null === $scope ) {
				$logger->warning( 'scope ' . urlencode( $request['scope'] ) . ' does not exist' );
				return self::return_error();
			}

			$fields = array(
				// @todo: pass the user-id via $request. Only super-admins can then set preferences for other users.
				'user_id' => wp_get_current_user()->ID,
			);

			if ( Scope::Blog === $scope || Scope::Post === $scope ) {
				$fields['blog_id'] = (int) $request['blogId'];
			}

			if ( Scope::Post === $scope ) {
				$fields['post_id'] = (int) $request['postId'];
			}

			// check if preference should be set back to default
			if ( 'use-default' === $request['value'] ) {
				$args = array(
					'scope'  => $scope,
					'fields' => $fields,
				);

				$res = User_Preferences::remove( ...$args );
			} else {
				if ( ( 'post' === $scope->value ) && ( 'activate-notifications' === $request['value'] ) ) {
					$preference = Notification_Preference::Posts_And_Comments;
				} elseif ( ( 'post' === $scope->value ) && ( 'deactivate-notifications' === $request['value'] ) ) {
					$preference = Notification_Preference::No_Notifications;
				} elseif ( 'post' === $scope->value ) {
					$logger->warning( 'notification preference ' . urlencode( $request['value'] ) . ' does not exist for scope post' );
					return self::return_error();
				} else {
					$preference = Notification_Preference::tryFrom( $request['value'] );
					if ( null === $preference ) {
						$logger->warning( 'notification preference ' . urlencode( $request['value'] ) . ' does not exist for scope ' . $scope->value );
						return self::return_error();
					}
				}
				$args = array(
					'scope'  => $scope,
					'pref'   => $preference,
					'fields' => $fields,
				);

				$res = User_Preferences::set( ...$args );
			}

			if ( $res ) {
				global $wpdb;
				$resolver          = new Notification_Resolver( $wpdb );
				$opposing_settings = $resolver->get_opposing_more_specific( $scope, $fields['user_id'], $fields['blog_id'] ?? null );
				$response_data     = array(
					'status'            => 'success',
					'opposing_settings' => $opposing_settings,
				);
			} else {
				$response_data = array(
					'status' => 'error',
				);
			}

			return rest_ensure_response(
				new WP_REST_Response(
					$response_data
				)
			);
		} catch ( \Exception $e ) {
			$logger->error( 'an uncaught error occurred while executing rest API: ' . $e->getMessage() );
			return self::return_error();
		}
	}



	/**
	 * Save ntfy.sh configuration for current user
	 */
	private static function save_ntfy_config( \WP_REST_Request $request ): WP_REST_Response {
		$logger = self::logger();

		try {
			$user_id    = wp_get_current_user()->ID;
			$blog_id    = (int) $request['blog_id'];
			$ntfy_topic = sanitize_text_field( $request['ntfy_topic'] );
			$enabled    = isset( $request['enabled'] ) ? (bool) $request['enabled'] : true;

			// Validate topic format
			if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $ntfy_topic ) ) {
				$logger->warning( 'Invalid ntfy.sh topic format: ' . $ntfy_topic );
				return rest_ensure_response(
					new WP_REST_Response(
						array(
							'status'  => 'error',
							'message' => 'Invalid topic format. Only alphanumeric, hyphens, and underscores allowed.',
						)
					)
				);
			}

			global $wpdb;
			$ntfy_channel = new Ntfy_Channel( $wpdb );
			$result       = $ntfy_channel->save_user_config( $user_id, $blog_id, $ntfy_topic, $enabled );

			if ( $result ) {
				return rest_ensure_response(
					new WP_REST_Response(
						array(
							'status'     => 'success',
							'ntfy_topic' => $ntfy_topic,
							'enabled'    => $enabled,
						)
					)
				);
			} else {
				return self::return_error();
			}
		} catch ( \Exception $e ) {
			$logger->error( 'Error saving ntfy.sh config: ' . $e->getMessage() );
			return self::return_error();
		}
	}

	/**
	 * Get ntfy.sh configuration for current user
	 */
	private static function get_ntfy_config( \WP_REST_Request $request ): WP_REST_Response {
		$logger = self::logger();

		try {
			$user_id = wp_get_current_user()->ID;
			$blog_id = (int) $request['blog_id'];

			global $wpdb;
			$result = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ntfy_topic, enabled FROM ' . SCOPED_NOTIFY_TABLE_USER_NTFY_CONFIG . ' WHERE user_id = %d AND blog_id = %d',
					$user_id,
					$blog_id
				)
			);

			if ( $result ) {
				return rest_ensure_response(
					new WP_REST_Response(
						array(
							'status'     => 'success',
							'ntfy_topic' => $result->ntfy_topic,
							'enabled'    => (bool) $result->enabled,
						)
					)
				);
			} else {
				return rest_ensure_response(
					new WP_REST_Response(
						array(
							'status'     => 'success',
							'ntfy_topic' => '',
							'enabled'    => false,
						)
					)
				);
			}
		} catch ( \Exception $e ) {
			$logger->error( 'Error retrieving ntfy.sh config: ' . $e->getMessage() );
			return self::return_error();
		}
	}

	/**
	 * return error response
	 * @return WP_REST_Response error message
	 */
	private static function return_error(): WP_REST_Response {
		return rest_ensure_response(
			new WP_REST_Response(
				array(
					'status' => 'error',
				)
			)
		);
	}
}
