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

			// Add List ID and Send Event controls
			add_action(
				'elementor/element/jet-subscribe-form/section_general_style/before_section_start',
				array( $this, 'register_controls' ),
				10,
				2
			);

			// Pass additiional data into subscribe request
			add_action(
				'jet-elements/subscribe-form/input-instance-data',
				array( $this, 'add_data_to_request' ),
				10,
				2
			);

		}

		public function add_data_to_request( $data, $widget ) {

			$settings = $widget->get_settings();

			if ( ! empty( $settings['es_group_name'] ) ) {
				$data['es_group_name'] = esc_attr( $settings['es_group_name'] );
			}

			if ( ! empty( $settings['es_send_event'] ) ) {
				$data['es_send_event'] = filter_var( $settings['es_send_event'], FILTER_VALIDATE_BOOLEAN );
			} else {
				$data['es_send_event'] = false;
			}

			if ( ! empty( $settings['es_event_name'] ) ) {
				$data['es_event_name'] = esc_attr( $settings['es_event_name'] );
			}

			if ( ! empty( $settings['es_event_from'] ) ) {
				$data['es_event_from'] = esc_attr( $settings['es_event_from'] );
			}

			return $data;

		}

		public function register_controls( $controls_manager, $args ) {

			$controls_manager->start_controls_section(
				'section_esputnik_settings',
				array(
					'label' => esc_html__( 'eSputnik Settings', 'jet-elements' ),
				)
			);

			$controls_manager->add_control(
				'es_group_name',
				array(
					'label' => esc_html__( 'Group Name', 'jet-elements' ),
					'type'  => Elementor\Controls_Manager::TEXT,
				)
			);

			$controls_manager->add_control(
				'es_send_event',
				array(
					'label'        => esc_html__( 'Fire event after successfull subscription', 'jet-elements' ),
					'type'         => Elementor\Controls_Manager::SWITCHER,
					'label_on'     => esc_html__( 'Yes', 'jet-elements' ),
					'label_off'    => esc_html__( 'No', 'jet-elements' ),
					'return_value' => 'yes',
					'default'      => 'yes',
				)
			);

			$controls_manager->add_control(
				'es_event_name',
				array(
					'label'     => esc_html__( 'Event Name', 'jet-elements' ),
					'type'      => Elementor\Controls_Manager::TEXT,
					'condition' => array(
						'es_send_event' => 'yes',
					),
				)
			);

			$controls_manager->add_control(
				'es_event_from',
				array(
					'label'     => esc_html__( 'Event Source', 'jet-elements' ),
					'default'   => 'crocoblock',
					'type'      => Elementor\Controls_Manager::TEXT,
					'condition' => array(
						'es_send_event' => 'yes',
					),
				)
			);

			$controls_manager->end_controls_section();

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
			$args = isset( $data['data'] ) ? $data['data'] : array();

			$send_event = isset( $args['es_send_event'] ) ? $args['es_send_event'] : true;
			$send_event = filter_var( $send_event, FILTER_VALIDATE_BOOLEAN );
			$group      = isset( $args['es_group_name'] ) ? esc_attr( $args['es_group_name'] ) : $this->group;
			$event_name = isset( $args['es_event_name'] ) ? esc_attr( $args['es_event_name'] ) : 'CrocoSubscribe';
			$event_from = isset( $args['es_event_from'] ) ? esc_attr( $args['es_event_from'] ) : 'crocoblock';

			if ( ! $send_event ) {
				$this->send_event = false;
			}

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

			if ( ! empty( $group ) ) {
				$body['groups'] = array( $group );
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
					'eventTypeKey' => $event_name,
					'keyValue'     => $mail,
					'params'       => array(
						array(
							'name'  => $event_from,
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
