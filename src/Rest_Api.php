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
	}

	/**
	* set user preferences
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
				'user_id' => wp_get_current_user()->ID,
			);

			if ( Scope::Blog === $scope || Scope::Post === $scope ) {
				$fields['blog_id'] = $request['blogId'];
			}

			if ( Scope::Post === $scope ) {
				$fields['post_id'] = $request['postId'];
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

			return rest_ensure_response(
				new WP_REST_Response(
					array(
						'status' => $res ? 'success' : 'error',
					)
				)
			);
		} catch ( \Exception $e ) {
			$logger->error( 'an uncaught error occured while executing rest API: ' . $e->getMessage() );
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
