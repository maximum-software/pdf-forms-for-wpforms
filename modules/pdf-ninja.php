<?php

if( ! defined( 'ABSPATH' ) )
	return;

class WPForms_Pdf_Ninja extends Pdf_Forms_For_WPForms_Service
{
	private static $instance = null;
	
	private $key = null;
	private $error = null;
	private $api_url = null;
	private $api_version = null;
	private $ignore_ssl_errors = null;
	private $capabilities = null;
	
	const API_URL = 'https://pdf.ninja';
	const VIEW = 'pdf-ninja';
	
	/*
	 * Runs after all plugins have been loaded
	 */
	public function plugin_init()
	{
		add_filter( 'wpforms_settings_defaults', array( $this, 'register_settings_fields' ), 5, 1 );
		add_filter( 'wpforms_settings_tabs', array( $this, 'register_settings_tabs' ), 5, 1 );
	}
	
	/**
	 * Adds necessary admin scripts and styles
	 */
	public function admin_enqueue_scripts( $hook )
	{
		if( wpforms_is_admin_page( 'settings' ) )
		{
			wp_register_script( 'pdf_forms_for_wpforms_copy_key_script', plugins_url( '../js/copy-key.js', __FILE__ ), array( 'jquery' ), Pdf_Forms_For_WPForms::VERSION );
			wp_localize_script( 'pdf_forms_for_wpforms_copy_key_script', 'pdf_forms_for_wpforms_copy_key', array(
				'__key_copy_btn_label' => __( 'copy key', 'pdf-forms-for-wpforms' ),
				'__key_copied_btn_label' => __( 'copied!', 'pdf-forms-for-wpforms' ),
			) );
			wp_enqueue_script( 'pdf_forms_for_wpforms_copy_key_script' );
			
			wp_enqueue_script( 'pdf_forms_filler_spinner_script' );
			wp_enqueue_style( 'pdf_forms_filler_spinner_style' );
			
			wp_register_script( 'pdf_forms_for_wpforms_pdfninja_panel_script', plugins_url( '../js/pdf-ninja-settings.js', __FILE__ ), array('jquery'), Pdf_Forms_For_WPForms::VERSION );
			wp_register_style( 'pdf_forms_for_wpforms_pdfninja_panel_style', plugins_url( '../css/pdf-ninja-settings.css', __FILE__ ), array( ), Pdf_Forms_For_WPForms::VERSION );
			wp_localize_script( 'pdf_forms_for_wpforms_pdfninja_panel_script', 'pdf_forms_for_wpforms', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'ajax_nonce' => wp_create_nonce( 'pdf-forms-for-wpforms-ajax-nonce' ),
				'__Unknown_error' => __( 'Unknown error', 'pdf-forms-for-wpforms' ),
			) );
			wp_enqueue_script( 'pdf_forms_for_wpforms_pdfninja_panel_script' );
			wp_enqueue_style( 'pdf_forms_for_wpforms_pdfninja_panel_style' );
		}
	}
	
	/**
	 * Add Pdf.Ninja to settings tabs
	 */
	public function register_settings_tabs( $tabs )
	{
		$pdf_ninja = array(
			self::VIEW => array(
				'name'   => esc_html__( 'Pdf.Ninja', 'pdf-forms-for-wpforms' ),
				'form'   => true,
				'submit' => esc_html__( 'Save Settings', 'pdf-forms-for-wpforms' ),
			)
		);
		
		return wpforms_array_insert( $tabs, $pdf_ninja, 'email' );
	}
	
	/**
	 * Add Pdf.Ninja settings fields
	 */
	public function register_settings_fields( $settings_fields )
	{
		$settings_fields[ self::VIEW ] = array(
			self::VIEW . '-heading' => array(
				'id'       => self::VIEW . '-heading',
				'content'  => Pdf_Forms_For_WPForms::replace_tags(
					'<h4>{title}</h4><p>{paragraph}</p>',
					array(
						'title' => esc_html__( 'Pdf.Ninja API', 'pdf-forms-for-wpforms' ),
						'paragraph' => esc_html__('The following form allows you to edit your API settings.' ),
					)
				),
				'type'     => 'content',
				'no_label' => true,
				'class'    => array( 'wpforms-setting-pdf-ninja-heading', 'section-heading' ),
			),
			self::VIEW . '-api_key' => array(
				'id'       => self::VIEW . '-api_key',
				'name'     => esc_html__( 'API Key', 'pdf-forms-for-wpforms' ),
				'type'     => 'text',
				'desc'     => '<button class="copy-pdf-ninja-key-btn wpforms-btn wpforms-btn-md">' . esc_html__( 'copy key', 'pdf-forms-for-wpforms' ) . '</button>',
				'filter'   => function($value, $id, $field, $prev_value) { return empty($value) ? $prev_value : $value; }
			),
			self::VIEW . '-request_new_key_form' => array(
				'id'       => self::VIEW . '-request_new_key_form',
				'content'  => 
					Pdf_Forms_For_WPForms::render( "spinner" ).
					Pdf_Forms_For_WPForms::render( "pdf-ninja-settings",
						array(
							'request-new-key-heading' => esc_html__( "Request new key from API", 'pdf-forms-for-wpforms' ),
							'admin-email' => $this->get_admin_email(),
							'admin-email-label' => esc_html__( "Administrator's Email Address", 'pdf-forms-for-wpforms' ),
							'get-new-key-label' => esc_html__( "Get new key", 'pdf-forms-for-wpforms' ),
						)
					),
				'type'     => 'content',
				'no_label' => true,
				'class'    => array( 'section-heading' ),
			),
			self::VIEW . '-api_url' => array(
				'id'       => self::VIEW . '-api_url',
				'name'     => esc_html__( 'API URL', 'pdf-forms-for-wpforms' ),
				'type'     => 'text',
				'default'  => self::API_URL,
			),
			self::VIEW . '-api_version' => array(
				'id'       => self::VIEW . '-api_version',
				'name'     => esc_html__( 'API Version', 'pdf-forms-for-wpforms' ),
				'type'     => 'radio',
				'options'  => array(
					'1' => esc_html__( 'Version 1', 'pdf-forms-for-wpforms' ),
					'2' => esc_html__( 'Version 2', 'pdf-forms-for-wpforms' ),
				),
				'default'  => '2',
			),
			self::VIEW . '-ignore_ssl_errors' => array(
				'id'       => self::VIEW . '-ignore_ssl_errors',
				'name'     => esc_html__( 'Data Security', 'pdf-forms-for-wpforms' ),
				'desc'     => esc_html__( 'Ignore SSL errors', 'pdf-forms-for-wpforms' ),
				'type'     => 'checkbox',
				'default'  => true
			),
		);
		
		// set capabilities section content
		try
		{
			$settings_fields[ self::VIEW ][ self::VIEW . '-capabilities' ] = array(
				'id'       => self::VIEW . '-capabilities',
				'name'     => esc_html__( 'API Capabilities', 'pdf-forms-for-wpforms' ),
				'content'  => Pdf_Forms_For_WPForms::replace_tags(
					'<button onclick="jQuery(\'#pdf-ninja-caps\').toggle();return false;"><span class="dashicons dashicons-search"></span></button><pre id="pdf-ninja-caps" style="display:none;">{json}</pre>',
					array(
						'json' => esc_html( json_encode( $this->api_get_capabilities( $use_cache = false ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ),
					)
				),
				'type'     => 'content',
			);
		}
		catch( Exception $e) { } // ignore errors
		
		return $settings_fields;
	}
	
	public function update_wpforms_settings_value( $field_name, $new_value )
	{
		$settings = get_option( 'wpforms_settings', array() );
		$settings[ $field_name ] = $new_value;
		wpforms_update_settings( $settings );
	}
	
	/**
	 * Returns a global instance of this class
	 */
	public static function get_instance()
	{
		if( ! self::$instance )
			self::$instance = new self;
		
		return self::$instance;
	}
	
	/**
	 * Returns (and initializes, if necessary) the current API key
	 */
	public function get_key()
	{
		if( $this->key )
			return $this->key;
		
		if( ! $this->key )
			$this->key = wpforms_setting( self::VIEW . '-api_key' );
		
		if( ! $this->key )
		{
			// attempt to get the key from another plugin
			$key = $this->get_external_key();
			if( $key )
				$this->set_key( $key );
		}
		
		if( ! $this->key )
		{
			// don't try to get the key from the API on every page load!
			$fail = get_transient( 'pdf_forms_for_wpforms_pdfninja_key_failure' );
			if( $fail )
				throw new Exception( __( "Failed to get the Pdf.Ninja API key on last attempt.", 'pdf-forms-for-wpforms' ) );
			
			// create new key if it hasn't yet been set
			try { $key = $this->generate_key(); }
			catch (Exception $e)
			{
				set_transient( 'pdf_forms_for_wpforms_pdfninja_key_failure', true, 12 * HOUR_IN_SECOND );
				throw $e;
			}
			
			$this->set_key( $key );
		}
		
		return $this->key;
	}
	
	/**
	 * Sets the API key
	 */
	public function set_key( $value )
	{
		$this->key = $value;
		$this->update_wpforms_settings_value( self::VIEW . '-api_key', $value );
		delete_transient( 'pdf_forms_for_wpforms_pdfninja_key_failure' );
		return true;
	}
	
	/**
	 * Searches for key in other plugins
	 */
	public function get_external_key()
	{
		// from PDF Forms Filler for CF7
		$option = get_option( 'wpcf7' );
		if( $option !== false && is_array( $option ) && isset( $option['wpcf7_pdf_forms_pdfninja_key'] ) )
			return $option['wpcf7_pdf_forms_pdfninja_key'];
		
		// from PDF Forms Filler for WooCommerce
		$option = get_option( 'pdf-forms-for-woocommerce-settings-pdf-ninja-api-key' );
		if( $option !== false )
			return $option;
		
		return null;
	}
	
	/**
	 * Determines administrator's email address (for use with requesting a new key from the API)
	 */
	public function get_admin_email()
	{
		$current_user = wp_get_current_user();
		if( ! $current_user )
			return null;
		
		$email = sanitize_email( $current_user->user_email );
		if( ! $email )
			return null;
		
		return $email;
	}
	
	/**
	 * Requests a key from the API server
	 */
	public function generate_key( $email = null )
	{
		if( $email === null )
			$email = $this->get_admin_email();
		
		if( $email === null )
			throw new Exception( __( "Failed to determine the administrator's email address.", 'pdf-forms-for-wpforms' ) );
		
		$key = null;
		
		// try to get the key the normal way
		try { $key = $this->api_get_key( $email ); }
		catch( Exception $e )
		{
			// if we are not running for the first time, throw an error
			$old_key = wpforms_setting( self::VIEW . '-api_key' );
			if( $old_key )
				throw $e;
			
			// there might be an issue with certificate verification on this system, disable it and try again
			$this->set_ignore_ssl_errors( true );
			try { $key = $this->api_get_key( $email ); }
			catch( Exception $e )
			{
				// if it still fails, revert and throw
				$this->set_ignore_ssl_errors( false );
				throw $e;
			}
		}
		
		return $key;
	}
	
	/**
	 * Returns (and initializes, if necessary) the current API URL
	 */
	public function get_api_url()
	{
		if( ! $this->api_url )
			$this->api_url = wpforms_setting( self::VIEW . '-api_url', self::API_URL );
		
		return $this->api_url;
	}
	
	/**
	 * Returns (and initializes, if necessary) the api version setting
	 */
	public function get_api_version()
	{
		if( $this->api_version === null )
		{
			$value = wpforms_setting( self::VIEW . '-api_version', '2' );
			if( $value == '1' ) $this->api_version = 1;
			if( $value == '2' ) $this->api_version = 2;
		}
		
		return $this->api_version;
	}
	
	/**
	 * Returns (and initializes, if necessary) the ignore ssl errors setting
	 */
	public function get_ignore_ssl_errors()
	{
		if( $this->ignore_ssl_errors === null )
			$this->ignore_ssl_errors = boolval( wpforms_setting( self::VIEW . '-ignore_ssl_errors', false ) );
		
		return $this->ignore_ssl_errors;
	}
	
	/**
	 * Sets the ignore ssl errors setting
	 */
	public function set_ignore_ssl_errors( $value )
	{
		$this->ignore_ssl_errors = $value;
		$this->update_wpforms_settings_value( self::VIEW . '-ignore_ssl_errors', $value );
		return true;
	}
	
	/**
	 * Generates common set of arguments to be used with remote http requests
	 */
	private function wp_remote_args()
	{
		return array(
			'headers'     => array(
				'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
				'Referer' => home_url(),
			),
			'compress'    => true,
			'decompress'  => true,
			'timeout'     => 300,
			'redirection' => 5,
			'user-agent'  => 'pdf-forms-for-wpforms/' . Pdf_Forms_For_WPForms::VERSION,
			'sslverify'   => !$this->get_ignore_ssl_errors(),
		);
	}
	
	/**
	 * Helper function for processing the API response
	 */
	private function api_process_response( $response )
	{
		if( is_wp_error( $response ) )
		{
			$errors = $response->get_error_messages();
			foreach($errors as &$error)
				if( stripos( $error, 'cURL error 7' ) !== false )
					$error = Pdf_Forms_For_WPForms::replace_tags(
							__( "Failed to connect to {url}", 'pdf-forms-for-wpforms' ),
							array( 'url' => $this->get_api_url() )
						);
			throw new Exception( implode( ', ', $errors ) );
		}
		
		$body = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		
		if( strpos($content_type, 'application/json' ) !== FALSE )
		{
			$result = json_decode( $body , true );
			
			if( ! $result || ! is_array( $result ) )
				throw new Exception( __( "Failed to decode API server response", 'pdf-forms-for-wpforms' ) );
			
			if( ! isset( $result['success'] ) || ( $result['success'] === false && ! isset( $result['error'] ) ) )
				throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'pdf-forms-for-wpforms' ) );
			
			if( $result['success'] === false )
				throw new WPForms_Pdf_Ninja_Exception( $result );
			
			if( $result['success'] == true && isset( $result['fileUrl'] ) )
			{
				$args2 = $this->wp_remote_args();
				$args2['timeout'] = 100;
				$response2 = wp_remote_get( $result['fileUrl'], $args2 );
				if( is_wp_error( $response2 ) )
					throw new Exception( __( "Failed to download a file from the API server", 'pdf-forms-for-wpforms' ) );
				
				$result['content_type'] = wp_remote_retrieve_header( $response2, 'content-type' );
				$result['content'] = wp_remote_retrieve_body( $response2 );
			}
		}
		else
		{
			if( wp_remote_retrieve_response_code( $response ) < 400 )
				$result = array(
					'success' => true,
					'content_type' => $content_type,
					'content' => $body,
				);
			else
				$result = array(
					'success' => false,
					'error' => wp_remote_retrieve_response_message( $response ),
				);
		}
		
		return $result;
	}
	
	/**
	 * Helper function that retries GET request if the file needs to be re-uploaded or md5 sum recalculated
	 */
	private function api_get_retry_attachment( $attachment_id, $endpoint, $params )
	{
		try
		{
			return $this->api_get( $endpoint, $params );
		}
		catch( WPForms_Pdf_Ninja_Exception $e )
		{
			$reason = $e->getReason();
			if( $reason == 'noSuchFileId' || $reason == 'md5sumMismatch' )
			{
				if( $this->is_local_attachment( $attachment_id ) )
					$this->api_upload_file( $attachment_id );
				else
					// update local md5sum
					$params['md5sum'] = Pdf_Forms_For_WPForms::update_attachment_md5sum( $attachment_id );
				
				return $this->api_get( $endpoint, $params );
			}
			throw $e;
		}
	}
	
	/*
	 * Helper function that retries POST request if the file needs to be re-uploaded or md5 sum recalculated
	 */
	private function api_post_retry_attachment( $attachment_id, $endpoint, $payload, $headers = array(), $args_override = array() )
	{
		try
		{
			return $this->api_post( $endpoint, $payload, $headers, $args_override );
		}
		catch( WPForms_Pdf_Ninja_Exception $e )
		{
			$reason = $e->getReason();
			if( $reason == 'noSuchFileId' || $reason == 'md5sumMismatch' )
			{
				if( $this->is_local_attachment( $attachment_id ) )
					$this->api_upload_file( $attachment_id );
				else
					// update local md5sum
					$params['md5sum'] = Pdf_Forms_For_WPForms::update_attachment_md5sum( $attachment_id );
				
				return $this->api_post( $endpoint, $payload, $headers, $args_override );
			}
			throw $e;
		}
	}
	
	/*
	 * Helper function for communicating with the API via the GET request
	 */
	private function api_get( $endpoint, $params )
	{
		$url = add_query_arg( $params, $this->get_api_url() . "/api/v" . $this->get_api_version() . "/" . $endpoint );
		$response = wp_remote_get( $url, $this->wp_remote_args() );
		return $this->api_process_response( $response );
	}
	
	/*
	 * Helper function for communicating with the API via the POST request
	 */
	private function api_post( $endpoint, $payload, $headers = array(), $args_override = array() )
	{
		$args = $this->wp_remote_args();
		
		$args['body'] = $payload;
		
		if( is_array( $headers ) )
			foreach( $headers as $key => $value )
				$args['headers'][$key] = $value;
		
		if( is_array( $args_override ) )
			foreach( $args_override as $key => $value )
				$args[$key] = $value;
		
		$url = $this->get_api_url() . "/api/v" . $this->get_api_version() . "/" . $endpoint;
		$response = wp_remote_post( $url, $args );
		return $this->api_process_response( $response );
	}
	
	/**
	 * Communicates with the API server to get a new key
	 */
	public function api_get_key( $email )
	{
		$result = $this->api_get( 'key', array( 'email' => $email ) );
		
		if( ! isset( $result['key'] ) )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'pdf-forms-for-wpforms' ) );
		
		return $result['key'];
	}
	
	/**
	 * Retrieves API capabilities
	 */
	public function api_get_capabilities( $use_cache = true )
	{
		if( $this->capabilities !== null )
			return $this->capabilities;
		
		$transient = 'pdf_forms_for_wpforms_pdfninja_capabilities';
		
		if( $use_cache )
		{
			// retrieve cached capabilities
			$capabilities = get_transient( $transient );
			if( $capabilities !== false )
			{
				$this->capabilities = json_decode( $capabilities, true );
				return $this->capabilities;
			}
		}
		
		// get capabilities from the API
		$capabilities = $this->api_get( 'capabilities', array( 'key' => $this->get_key() ) );
		
		if( ! is_array( $capabilities ) || ! isset( $capabilities['version'] ) )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'pdf-forms-for-wpforms' ) );
		
		// cache capabilities
		set_transient( $transient, Pdf_Forms_For_WPForms::json_encode( $capabilities ), DAY_IN_SECONDS );
		
		$this->capabilities = $capabilities;
		
		return $capabilities;
	}
	
	/**
	 * Generates and returns file id to be used with the API server
	 */
	private function get_file_id( $attachment_id )
	{
		$file_id = Pdf_Forms_For_WPForms::get_meta( $attachment_id, 'file_id' );
		if( ! $file_id )
		{
			$file_id = substr( $attachment_id . "-" . get_site_url(), 0, 40 );
			Pdf_Forms_For_WPForms::set_meta( $attachment_id, 'file_id', $file_id );
		}
		return substr( $file_id, 0, 40 );
	}
	
	/**
	 * Returns true if file hasn't yet been uploaded to the API server
	 */
	private function is_new_file( $attachment_id )
	{
		return Pdf_Forms_For_WPForms::get_meta( $attachment_id, 'file_id' ) == null;
	}
	
	/**
	 * Returns true if attachment file is on the local file system
	 */
	private function is_local_attachment( $attachment_id )
	{
		$filepath = get_attached_file( $attachment_id );
		return $filepath !== false && is_readable( $filepath ) !== false;
	}
	
	/*
	 * Returns file URL to be used with the API server
	 */
	private function get_file_url( $attachment_id )
	{
		$fileurl = wp_get_attachment_url( $attachment_id );
		
		if( $fileurl === false )
			throw new Exception( __( "Unknown attachment URL", 'pdf-forms-for-wpforms' ) );
		
		return $fileurl;
	}
	
	/*
	 * Communicates with the API to upload the media file
	 */
	public function api_upload_file( $attachment_id )
	{
		$md5sum = Pdf_Forms_For_WPForms::update_attachment_md5sum( $attachment_id );
		
		$params = array(
			'fileId' => $this->get_file_id( $attachment_id ),
			'md5sum' => $md5sum,
			'key'    => $this->get_key(),
		);
		
		$boundary = wp_generate_password( 48, $special_chars = false, $extra_special_chars = false );
		
		$payload = "";
		
		foreach( $params as $name => $value )
			$payload .= "--{$boundary}\r\n"
			          . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
			          . "\r\n"
			          . "{$value}\r\n";
		
		if( ! $this->is_local_attachment( $attachment_id ) )
			throw new Exception( __( "File is not accessible in the local file system", 'pdf-forms-for-wpforms' ) );
		
		$filepath = get_attached_file( $attachment_id );
		$filename = wp_basename( $filepath );
		$filecontents = file_get_contents( $filepath );
		
		$payload .= "--{$boundary}\r\n"
		          . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
		          . "Content-Type: application/octet-stream\r\n"
		          . "\r\n"
		          . "{$filecontents}\r\n";
		
		$payload .= "--{$boundary}--";
		
		$headers  = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
		$args = array( 'timeout' => 300 );
		
		$result = $this->api_post( 'file', $payload, $headers, $args );
		
		if( $result['success'] != true )
			throw new Exception( $result['error'] );
		
		return true;
	}
	
	/**
	 * Helper function for communicating with the API to obtain the PDF file fields
	 */
	public function api_get_info_helper( $endpoint, $attachment_id )
	{
		$params = array(
			'md5sum' => Pdf_Forms_For_WPForms::get_attachment_md5sum( $attachment_id ),
			'key'    => $this->get_key(),
		);
		
		if( $this->is_local_attachment( $attachment_id ) )
		{
			if( $this->is_new_file( $attachment_id ) )
				$this->api_upload_file( $attachment_id );
			$params['fileId'] = $this->get_file_id( $attachment_id );
		}
		else
			$params['fileUrl'] = $this->get_file_url( $attachment_id );
		
		return $this->api_get_retry_attachment( $attachment_id, $endpoint, $params );
	}
	
	/**
	 * Communicates with the API to obtain the PDF file fields
	 */
	public function api_get_fields( $attachment_id )
	{
		$result = $this->api_get_info_helper( 'fields', $attachment_id );
		
		if( ! isset( $result['fields'] ) || ! is_array( $result['fields'] ) )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'pdf-forms-for-wpforms' ) );
		
		return $result['fields'];
	}
	
	/**
	 * Communicates with the API to obtain the PDF file information
	 */
	public function api_get_info( $attachment_id )
	{
		$result = $this->api_get_info_helper( 'info', $attachment_id );
		
		if( ! isset( $result['fields'] ) || ! isset( $result['pages'] ) || ! is_array( $result['fields'] ) || ! is_array( $result['pages'] ) )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'pdf-forms-for-wpforms' ) );
		
		unset( $result['success'] );
		
		return $result;
	}
	
	/**
	 * Communicates with the API to get image of PDF pages
	 */
	public function api_image( $destfile, $attachment_id, $page )
	{
		$params = array(
			'md5sum' => Pdf_Forms_For_WPForms::get_attachment_md5sum( $attachment_id ),
			'key'    => $this->get_key(),
			'type'   => 'jpeg',
			'page'   => intval($page),
			'dumpFile' => true,
		);
		
		if( $this->is_local_attachment( $attachment_id ) )
		{
			if( $this->is_new_file( $attachment_id ) )
				$this->api_upload_file( $attachment_id );
			$params['fileId'] = $this->get_file_id( $attachment_id );
		}
		else
			$params['fileUrl'] = $this->get_file_url( $attachment_id );
		
		$result = $this->api_get_retry_attachment( $attachment_id, 'image', $params );
		
		if( ! isset( $result['content'] ) || ! isset( $result['content_type'] ) || $result['content_type'] != 'image/jpeg' )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'pdf-forms-for-wpforms' ) );
		
		if( file_put_contents( $destfile, $result['content'] ) === false || ! is_file( $destfile ) )
			throw new Exception( __( "Failed to create file", 'pdf-forms-for-wpforms' ) );
		
		return true;
	}
	
	/**
	 * Helper function for communicating with the API to generate PDF file
	 */
	private function api_pdf_helper( $destfile, $endpoint, $attachment_id, $data, $embeds, $options )
	{
		if( ! is_array ( $data ) )
			$data = array();
		
		if( ! is_array ( $embeds ) )
			$embeds = array();
		
		if( ! is_array ( $options ) )
			$options = array();
		
		// prepare files and embed params
		$files = array();
		foreach( $embeds as $key => $embed )
		{
			$filepath = $embed['image'];
			if( !is_readable( $filepath ) )
			{
				unset( $embeds[$key] );
				continue;
			}
			$files[$filepath] = $filepath;
		}
		$files = array_values( $files );
		foreach( $embeds as &$embed )
		{
			$filepath = $embed['image'];
			$id = array_search($filepath, $files, $strict=true);
			if($id === FALSE)
				continue;
			$embed['image'] = $id;
		}
		unset($embed);
		
		$encoded_data = Pdf_Forms_For_WPForms::json_encode( $data );
		if( $encoded_data === FALSE || $encoded_data === null )
			throw new Exception( __( "Failed to encode JSON data", 'pdf-forms-for-wpforms' ) );
		
		$encoded_embeds = Pdf_Forms_For_WPForms::json_encode( $embeds );
		if( $encoded_embeds === FALSE || $encoded_embeds === null )
			throw new Exception( __( "Failed to encode JSON data", 'pdf-forms-for-wpforms' ) );
		
		$params = array(
			'md5sum'   => Pdf_Forms_For_WPForms::get_attachment_md5sum( $attachment_id ),
			'key'      => $this->get_key(),
			'data'     => $encoded_data,
			'embeds'   => $encoded_embeds,
			'dumpFile' => true,
		);
		
		if( $this->is_local_attachment( $attachment_id ) )
		{
			if( $this->is_new_file( $attachment_id ) )
				$this->api_upload_file( $attachment_id );
			$params['fileId'] = $this->get_file_id( $attachment_id );
		}
		else
			$params['fileUrl'] = $this->get_file_url( $attachment_id );
		
		foreach( $options as $key => $value )
		{
			if( $key == 'flatten' )
				$params[$key] = $value;
		}
		
		$boundary = wp_generate_password( 48, $special_chars = false, $extra_special_chars = false );
		
		$payload = "";
		
		foreach( $params as $name => $value )
			$payload .= "--{$boundary}\r\n"
			          . "Content-Disposition: form-data; name=\"{$name}\"\r\n"
			          . "\r\n"
			          . "{$value}\r\n";
		
		foreach( $files as $fileId => $filepath )
		{
			$filename = wp_basename( $filepath );
			$filecontents = file_get_contents( $filepath );
			
			$payload .= "--{$boundary}\r\n"
			          . "Content-Disposition: form-data; name=\"images[{$fileId}]\"; filename=\"{$filename}\"\r\n"
			          . "Content-Type: application/octet-stream\r\n"
			          . "\r\n"
			          . "{$filecontents}\r\n";
		}
		
		$payload .= "--{$boundary}--";
		
		$headers  = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
		$args = array( 'timeout' => 300 );
		
		$result = $this->api_post_retry_attachment( $attachment_id, $endpoint, $payload, $headers, $args );
		
		if( ! isset( $result['content'] ) || ! isset( $result['content_type'] ) || $result['content_type'] != 'application/pdf' )
			throw new Exception( __( "Pdf.Ninja API server did not send an expected response", 'pdf-forms-for-wpforms' ) );
		
		if( file_put_contents( $destfile, $result['content'] ) === false || ! is_file( $destfile ) )
			throw new Exception( __( "Failed to create file", 'pdf-forms-for-wpforms' ) );
		
		return true;
	}
	
	/**
	 * Communicates with the API to fill fields in the PDF file
	 */
	public function api_fill( $destfile, $attachment_id, $data, $options = array() )
	{
		return $this->api_pdf_helper( $destfile, 'fill', $attachment_id, $data, array(), $options );
	}
	
	/*
	 * Communicates with the API to fill fields in the PDF file
	 */
	public function api_fill_embed( $destfile, $attachment_id, $data, $embeds, $options = array() )
	{
		return $this->api_pdf_helper( $destfile, 'fillembed', $attachment_id, $data, $embeds, $options );
	}
	
	/**
	 * This function gets called to display admin notices
	 */
	public function admin_notices()
	{
		try { $this->get_key(); } catch(Exception $e) { }
		$fail = get_transient( 'pdf_forms_for_wpforms_pdfninja_key_failure' );
		if( isset( $fail ) && $fail && current_user_can( wpforms_get_capability_manage_options() ) )
			echo Pdf_Forms_For_WPForms::render_error_notice( 'pdf-ninja-new-key-failure', array(
				'label' => esc_html__( "PDF Forms Filler for WPForms Error", 'pdf-forms-for-wpforms' ),
				'message' =>
					Pdf_Forms_For_WPForms::replace_tags(
						esc_html__( "Failed to acquire the Pdf.Ninja API key on last attempt. {a-href-edit-service-page}Please retry manually{/a}.", 'pdf-forms-for-wpforms' ),
						array(
							'a-href-edit-service-page' => "<a href='".esc_url( add_query_arg( array( 'view' => 'pdf-ninja' ), menu_page_url( 'wpforms-settings', false ) ) )."'>",
							'/a' => "</a>",
						)
					)
			) );
	}
	
	/**
	 * Returns form settings screen notices that need to be displayed
	 */
	public function form_notices()
	{
		$notices = '';
		try
		{
			$url = $this->get_api_url();
			$ignore_ssl_errors = $this->get_ignore_ssl_errors();
			if( substr( $url, 0, 5 ) == 'http:' || $ignore_ssl_errors )
				$notices .= Pdf_Forms_For_WPForms::render_warning_notice( null, array(
				'label' => esc_html__( "Warning", 'pdf-forms-for-wpforms' ),
				'message' => esc_html__( "Your WPForms settings indicate that you are using an insecure connection to the Pdf.Ninja API server.", 'pdf-forms-for-wpforms' ),
			) );
		}
		catch(Exception $e) { };
		
		return $notices;
	}
}

class WPForms_Pdf_Ninja_Exception extends Exception
{
	private $reason = null;
	
	public function __construct( $response )
	{
		$msg = $response;
		
		if( is_array( $response ) )
		{
			if( ! isset( $response['error'] ) || $response['error'] == "" )
				$msg = __( "Unknown error", 'pdf-forms-for-wpforms' );
			else
				$msg = $response['error'];
			if( isset( $response['reason'] ) )
				$this->reason = $response['reason'];
		}
		
		parent::__construct( $msg );
	}
	
	public function getReason() { return $this->reason; }
}
