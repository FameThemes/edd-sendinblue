<?php
/*
Plugin Name: EDD - Sendinblue
Plugin URL: https://www.famethemes.com/
Description: Sendinblue emails
Version: 0.0.1
Author: Shrimp2t
Author URI: https://www.famethemes.com/
*/


class SIB_API {
	static private $_instance = null;
	private $api_key = '';
	protected $api_url = 'https://api.sendinblue.com/v3/';
	protected $list_id = '';
	static function get_instance(){
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();

			$settings = edd_get_settings();
			if ( ! isset( $settings['edd_sib_api_key'] ) ) {
				$settings['edd_sib_api_key'] = '';
			}

			if ( ! isset( $settings['edd_sib_api_list_id'] ) ) {
				$options =  get_option( 'edd_sib_lists', array() );
				$settings['edd_sib_api_list_id'] = '';
				if ( is_array( $options ) ) {
					current($options);
					$key = key( $options );
					reset( $options );
					$settings['edd_sib_api_list_id'] = $key;
				}
			}

			self::$_instance->api_key = $settings['edd_sib_api_key'];
			self::$_instance->list_id = $settings['edd_sib_api_list_id'];

		}
		return self::$_instance;
	}

	private function remote( $action = '', $method = 'get', $params = null ){
		$method = strtoupper( $method );

		$url = $this->api_url.$action;

		if ( $params ) {
			if ( strtoupper( $method ) == 'GET' ) {
				$url = add_query_arg( $params, $url );
			}
		}

		// WP Remote not working with this server so we need use CURL
		$curl = curl_init();
		curl_setopt_array( $curl, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => "",
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json',
				'api-key: '. $this->api_key,
			),
			CURLOPT_POSTFIELDS     => json_encode( $params ),
		) );

		$response = curl_exec( $curl );
		$err      = curl_error( $curl );

		curl_close( $curl );

		if ( ! $err ) {
			return json_decode( $response, true );
		} else {
			return false;
		}

	}

	function get_contact_lists(){
		$list = $this->remote('contacts/lists', 'GET', array( 'limit' => 45 ) );
		if ( $list && isset( $list['lists'] ) ) {
			return $list['lists'];
		}
		return array();
	}

	function create_contact_attr( $attr = array() ){
		$attr = wp_parse_args( $attr , array(
			'category' => '',
			'type' => 'text',
			'name' => ''
		));
		$this->remote("contacts/attributes/{$attr['category']}/{$attr['name']}", 'post', array( 'type' => $attr['type'] ) );
	}

	function get_edd_customer_by_email( $email ){
		global $wpdb;
		$sql = "SELECT {$wpdb->prefix}edd_customers.*
			FROM {$wpdb->prefix}edd_customers LEFT JOIN {$wpdb->prefix}edd_customermeta AS email_mt ON {$wpdb->prefix}edd_customers.id = email_mt.customer_id
			WHERE
				(
					( email_mt.meta_key = 'additional_email' AND email_mt.meta_value = %s )
					OR 
					email = %s
				)
			GROUP BY {$wpdb->prefix}edd_customers.id ORDER BY {$wpdb->prefix}edd_customers.id DESC LIMIT 1";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, array( $email, $email ) ) );

		if ( $row ) {
			return $row->id;
		}

		return false;

	}

	function create_contact( $email_or_data = array(), $exclude_payment_ids = array() ){
		if ( ! $this->list_id || ! $this->api_key ) {
			return ;
		}

		if ( is_string( $email_or_data ) ) {
			$args['email'] = $email_or_data;
		} else {
			$args = $email_or_data;
		}

		$args = wp_parse_args( $args, array(
			'listIds' =>  array(),
			'email' =>  '',
			'updateEnabled' => true,
			'attributes' => array(
				'NAME'                  => '',
				'NUMBER_OF_PURCHASES'   => null,
				'CUSTOMER_VALUE'        => null,
				'DATE_CREATED'          => null,
				'PURCHASED'             => null,
				'LAST_PURCHASE_DATE'    => null,
			),
		) );

		if ( ! $args['email'] ) {
			return false;
		}

		$args['listIds'] = array( absint( $this->list_id ) );
		$args['updateEnabled'] = true;
		$customer = false;
		if ( is_numeric( $email_or_data ) ) {
			$customer = new EDD_Customer( $email_or_data );
			if ( $customer ) {
				$args['email'] = $customer->email;
 			}
		} else {
			$customer_id = $this->get_edd_customer_by_email( $args['email'] );
			if ( $customer_id ) {
				$customer = new EDD_Customer( $customer_id );
			}
		}

		if ( $customer ) {
			$args['attributes']['NAME'] = $customer->name;
			$args['attributes']['NUMBER_OF_PURCHASES'] = $customer->purchase_count;
			$args['attributes']['CUSTOMER_VALUE'] = $customer->purchase_value;
			$args['attributes']['DATE_CREATED'] = $customer->date_created;

			$products = array();

			$last_date = 0;

			foreach ( $customer->get_payments( array( 'publish' ) ) as $payment ) {
				$skip = false;
				if( ! empty( $exclude_payment_ids ) && in_array( $payment->ID, $exclude_payment_ids ) ) {
					$skip = true;
				}
				if ( ! $skip ) {
					$_d = $payment->__get( 'downloads' );
					$t = strtotime( $payment->__get( 'date' ) );
					if( $t > $last_date ) {
						$last_date = $t;
					}

					foreach ( $_d as $_id ) {
						$products[ $_id['id'] ] = get_the_title( $_id['id'] );
					}
				}
			}

			$args['attributes']['PURCHASED']     = join( '|', $products );
			if ( $last_date ) {
				$args['attributes']['LAST_PURCHASE_DATE']  = date('Y-m-d H:i:s', $last_date );
			}

		}

		return $this->remote( 'contacts', 'POST', $args );

	}

}

function edd_sib_payment_send_contact_info( $payment_id ){
	$payment = new EDD_Payment( $payment_id );
	SIB_API::get_instance()->create_contact( $payment->__get( 'email' ) );
}

function edd_sib_payment_send_deleted_contact_info( $payment_id ){
	$payment = new EDD_Payment( $payment_id );
	SIB_API::get_instance()->create_contact( $payment->__get( 'email' ), array( $payment_id ) );
}

function edd_sib_send_wp_customer_info( $id_or_email ){
	SIB_API::get_instance()->create_contact( $id_or_email );
}

function edd_sib_send_wp_user_info( $user_id ){
	$user = get_user_by( 'id',  $user_id );
	if ( $user ) {
		SIB_API::get_instance()->create_contact( array(
			'email' => $user->user_email,
			'attributes' => array(
				'NAME'                  => $user->display_name,
				'NUMBER_OF_PURCHASES'   => null,
				'CUSTOMER_VALUE'        => null,
				'DATE_CREATED'          => null,
				'PRODUCTS'              => null,
			),
		) );
	}

}


add_action( 'plugins_loaded' , 'SIB_Init' );
function SIB_Init(){
	if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
		return; //Support EDD Only
	}

	add_action( 'edd_update_payment_status', 'edd_sib_payment_send_contact_info' );
	add_action( 'edd_payment_saved', 'edd_sib_payment_send_contact_info' );
	add_action( 'edd_payment_delete', 'edd_sib_payment_send_deleted_contact_info' );
	add_action( 'user_register', 'edd_sib_send_wp_user_info' );
	add_action( 'edd_post_insert_customer', 'edd_sib_send_wp_customer_info' );


	if ( is_admin() ) {
		require_once dirname( __FILE__ ) . '/inc/admin.php';
		require_once dirname( __FILE__ ) . '/inc/export.php';
	}

}