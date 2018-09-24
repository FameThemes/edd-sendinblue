<?php

add_filter( 'edd_export_get_data_customers', array( EDD_SIB_Export_Customers::get_instance(), 'filter_data' ) );
add_filter( 'edd_export_csv_cols_customers', array( EDD_SIB_Export_Customers::get_instance(), 'filter_cols' ) );

class EDD_SIB_Export_Customers {
	static $_instance =  null;
	static function get_instance(){
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	function filter_cols( $cols ){
		$cols['date_created'] = __( 'Date_Created',   'edd-sib' );
		$cols['purchased'] = __( 'Purchased',   'edd-sib' );
		$cols['last_purchase_date'] = __( 'Last_Purchase_Date',   'edd-sib' );
		return $cols;
	}
	function filter_data( $list ){

		foreach ( $list as $index => $data ) {

			$customer = new EDD_Customer( $data['email'] );
			$products = array();
			$last_date = 0;
			foreach ( $customer->get_payments( array( 'publish' ) ) as $payment ) {
				$_d = $payment->__get( 'downloads' );
				$t = strtotime( $payment->__get( 'date' ) );
				if( $t > $last_date ) {
					$last_date = $t;
				}
				foreach ( $_d as $_id ) {
					$products[ $_id['id'] ] = get_the_title( $_id['id'] );
				}
			}
			$data['date_created'] = $customer->date_created;
			$data['purchased']     = join( '|', $products );

			if ( $last_date ) {
				$data['last_purchase_date']  = date('Y-m-d H:i:s', $last_date );
			}

			$list[ $index ] =  $data;
		}

		return $list;

	}
}
