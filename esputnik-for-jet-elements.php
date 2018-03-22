<?php
/*
Plugin Name:  eSputik integration for Jet Elements
Plugin URI:
Description:   eSputik integration for Jet Elements subscribe
Version:      1.0.0
Author:       Zemez
Author URI:
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  esputnik-for-jet-elements
Domain Path:  /languages
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'eSputnik_For_Jet_Elements' ) ) {

	/**
	 * Define eSputnik_For_Jet_Elements class
	 */
	final class eSputnik_For_Jet_Elements {

		/**
		 * A reference to an instance of this class.
		 *
		 * @since 1.0.0
		 * @var   object
		 */
		private static $instance = null;

		/**
		 * Enter auth data into MailChimp API key field in JetElements settings in 'user_mail:password' format.
		 * @var string
		 */
		public $auth;

		/**
		 * Enter group ID into MailChimp list ID field in JetElements settings.
		 * @var string
		 */
		public $group;

		/**
		 * Send or not CrocoSubscribe event. Use Double opt-in field in JetElemnts settings.
		 * @var string
		 */
		public $send_event;

		/**
		 * Sputnic API server URL
		 * @var string
		 */
		public $api_server = 'https://esputnik.com/api';

		/**
		 * Constructor for the class
		 */
		public function __construct() {

			add_action( 'wp_ajax_jet_subscribe_form_ajax', array( $this, 'ajax_subscribe' ), 0 );
			add_action( 'wp_ajax_nopriv_jet_subscribe_form_ajax', array( $this, 'ajax_subscribe' ), 0 );

		}

		public function ajax_subscribe() {

			$this->auth       = jet_elements_settings()->get( 'mailchimp-api-key' );
			$this->group      = jet_elements_settings()->get( 'mailchimp-list-id' );
			$this->send_event = jet_elements_settings()->get( 'mailchimp-double-opt-in' );
			$this->send_event = filter_var( $this->send_event, FILTER_VALIDATE_BOOLEAN );

			$messages = array(
				'invalid_mail'      => 'Please, provide valid mail',
				'esputnik'          => 'Please, set up eSputnik Auth data and group',
				'internal'          => 'Internal error. Please, try again later',
				'server_error'      => 'Server error. Please, try again later',
				'subscribe_success' => 'Success',
			);

			if ( ! $this->auth ) {
				wp_send_json_error( array( 'type' => 'error', 'message' => $messages['esputnik'] ) );
			}

			$data = ( ! empty( $_POST['data'] ) ) ? $_POST['data'] : false;

			if ( ! $data ) {
				wp_send_json_error( array( 'type' => 'error', 'message' => $messages['server_error'] ) );
			}

			$mail = $data['mail'];

			if ( empty( $mail ) || ! is_email( $mail ) ) {
				wp_send_json( array( 'type' => 'error', 'message' => $messages['invalid_mail'] ) );
			}

			$auth = base64_encode( $this->auth );

			$body = array(
				'contact' => array(
					'channels' => array(
						'type'  => 'email',
						'value' => $mail,
					),
				)
			);

			if ( ! empty( $this->group ) ) {
				$body['groups'] = array( $this->group );
			}

			$response = wp_remote_post(
				$this->api_server . '/v1/contact/subscribe',
				array(
					'method'      => 'POST',
					'timeout'     => 60,
					'headers'     => array(
						'Authorization' => 'Basic ' . $auth,
						'Accept'        => 'application/json',
						'Content-Type'  => 'application/json',
					),
					'body'        => json_encode( $body ),
				)
			);

			$response = wp_remote_retrieve_body( $response );
			$response = json_decode( $response, true );

			if ( true === $this->send_event ) {

				$event = array(
					'eventTypeKey' => 'CrocoSubscribe',
					'keyValue'     => $mail,
					'params'       => array(
						array(
							'name'  => 'crocoblock',
							'value' => true,
						),
						array(
							'name'  => 'EmailAddress',
							'value' => $mail,
						),
					),
				);

				$event_reponse = wp_remote_post(
					$this->api_server . '/v1/event',
					array(
						'method'      => 'POST',
						'timeout'     => 60,
						'headers'     => array(
							'Authorization' => 'Basic ' . $auth,
							'Accept'        => 'application/json',
							'Content-Type'  => 'application/json',
						),
						'body'        => json_encode( $event ),
					)
				);

			}

			if ( ! empty( $response['id'] ) ) {
				wp_send_json(
					array(
						'type'    => 'success',
						'message' => $messages['subscribe_success'] )
				);
			} else {
				wp_send_json(
					array(
						'type'    => 'error',
						'message' => $messages['internal'] )
				);
			}

		}

		/**
		 * Returns the instance.
		 *
		 * @since  1.0.0
		 * @return object
		 */
		public static function get_instance() {

			// If the single instance hasn't been set, set it now.
			if ( null == self::$instance ) {
				self::$instance = new self;
			}
			return self::$instance;
		}
	}

}

eSputnik_For_Jet_Elements::get_instance();
