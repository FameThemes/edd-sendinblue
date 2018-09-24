<?php
// Add our settings to the EDD Extensions tab


class EDD_SIB_Admin {
	function __construct()
	{
		add_filter( 'edd_settings_sections_extensions', array( $this, 'register_subsection' )    );
		add_filter( 'edd_settings_extensions'         , array( $this, 'extensions_settings' ) );
	}

	function sync_attrs(){
		$attrs = array(
			array(
				'name' => 'NUMBER_OF_PURCHASES',
				'category' => 'normal',
				'type' => 'number',
			),
			array(
				'name' => 'CUSTOMER_VALUE',
				'category' => 'normal',
				'type' => 'number',
			),
			array(
				'name' => 'DATE_CREATED',
				'category' => 'normal',
				'type' => 'date',
			),
			array(
				'name' => 'PRODUCTS',
				'category' => 'normal',
				'type' => 'text',
			),
		);

		foreach ( $attrs as $attr ) {
			SIB_API::get_instance()->create_contact_attr( $attr );
		}

	}

	public function register_subsection( $sections ) {
		// Note the array key here of 'ck-settings'
		$sections['edd_sendinblue'] = __( 'Sendinblue', 'edd_sib' );

		return $sections;

	}

	public function extensions_settings( $extensions ) {
		$options = array();

		if ( isset( $_POST['edd_settings'] ) ) {
			$this->sync_attrs();
		}

		foreach ( SIB_API::get_instance()->get_contact_lists() as $list ) {
			$options[ $list['id'] ] = $list['name'].' ('.$list['totalSubscribers'].')';
		}

		if ( isset( $_POST['edd_settings'] ) ) {
			update_option( 'edd_sib_lists', $options );
			$this->sync_attrs();
		}

		$settings = array (

			'api_key' => array(
				'id'    => 'edd_sib_api_key',
				'name'  => __( 'API Key', 'edd_sib' ),
				'type'  => 'text',
				'std'   => ''
			),

			// SIB_API
			array(
				'id'            => 'edd_sib_api_list_id',
				'name'          => __( 'Sync To List', 'edd-free-downloads' ),
				'type'          => 'select',
				'options' => $options
			),

		);

		$extensions['edd_sendinblue'] = $settings;

		return $extensions;

	}

}
new EDD_SIB_Admin();