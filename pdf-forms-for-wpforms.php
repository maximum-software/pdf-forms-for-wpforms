<?php
/**
 * Plugin Name: PDF Forms Filler for WPForms
 * Plugin URI: https://pdfformsfiller.org/
 * Description: Build WPForms from PDF forms. Get PDFs filled automatically and attached to email messages and/or website responses on form submissions.
 * Version: 1.1.2
 * Requires at least: 5.4
 * Requires PHP: 5.5
 * Author: Maximum.Software
 * Author URI: https://maximum.software/
 * Text Domain: pdf-forms-for-wpforms
 * Domain Path: /languages
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

require_once untrailingslashit( dirname( __FILE__ ) ) . '/inc/tgm-config.php';
require_once untrailingslashit( dirname( __FILE__ ) ) . '/modules/wrapper.php';

if( ! class_exists('Pdf_Forms_For_WPForms') )
{
	class Pdf_Forms_For_WPForms
	{
		const VERSION = '1.1.2';
		const MIN_WPFORMS_VERSION = '1.6.9';
		const MAX_WPFORMS_VERSION = '1.8.99';
		private static $BLACKLISTED_WPFORMS_VERSIONS = array();
		
		private static $instance = null;
		private $pdf_ninja_service = null;
		private $service = null;
		private $registered_services = false;
		private $downloads = null;
		private $storage = null;
		private $tmp_dir = null;
		private $wpforms_mail_attachments = array();
		
		private function __construct()
		{
			add_action( 'admin_notices',  array( $this, 'admin_notices' ) );
			add_action( 'plugins_loaded', array( $this, 'plugin_init' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
			register_activation_hook( __FILE__, array( $this, 'plugin_activated' ) );
			register_deactivation_hook( __FILE__, array( $this, 'plugin_deactivated' ) );
			add_action( 'pdf_forms_for_wpforms_cron', array( $this, 'cron' ) );
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
		 * Runs after all plugins have been loaded
		 */
		public function plugin_init()
		{
			load_plugin_textdomain( 'pdf-forms-for-wpforms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			
			if( ! class_exists('WPForms') || ! defined( 'WPFORMS_VERSION' ) )
				return;
			
			add_action( 'wp_ajax_pdf_forms_for_wpforms_get_attachment_data', array( $this, 'wp_ajax_get_attachment_data' ) );
			add_action( 'wp_ajax_pdf_forms_for_wpforms_query_page_image', array( $this, 'wp_ajax_query_page_image' ) );
			add_action( 'wp_ajax_pdf_forms_for_wpforms_generate_pdf_ninja_key', array( $this, 'wp_ajax_generate_pdf_ninja_key') );
			
			add_action( 'admin_menu', array( $this, 'register_services' ) );
			
			add_filter( 'wpforms_save_form_args', array( $this, 'wpforms_save_form_args' ), 10, 3 );
			add_action( 'wpforms_process', array( $this, 'fill_pdfs' ), 10, 3 );
			add_action( 'wpforms_process_complete', array( $this, 'remove_tmp_dir' ), 99, 0 );
			add_filter( 'wpforms_emails_send_email_data', array( $this, 'attach_files' ), 10, 2 );
			add_action( 'wpforms_frontend_confirmation_message', array( $this, 'change_confirmation_message'), 10, 4 );
			
			add_filter( 'wpforms_builder_settings_sections', array( $this, 'add_settings_panel' ) );
			add_action( 'wpforms_form_settings_panel_content', array( $this, 'add_settings_content' ) );
			
			add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
			
			if( $service = $this->get_service() )
			{
				$service->plugin_init();
				if( $service != $this->pdf_ninja_service )
					$this->pdf_ninja_service->plugin_init();
			}
		}
		
		/*
		 * Runs after the plugin have been activated/deactivated
		 */
		public function plugin_activated( $network_wide = false )
		{
			if( $network_wide )
			{
				$sites = get_sites( array( 'fields' => 'ids' ) );
				foreach( $sites as $id )
				{
					switch_to_blog( $id );
					$this->plugin_activated( false );
					restore_current_blog();
				}
				return;
			}
			
			$this->enable_cron();
		}
		public function plugin_deactivated( $network_deactivating = false )
		{
			if( $network_deactivating )
			{
				$sites = get_sites( array( 'fields' => 'ids' ) );
				foreach( $sites as $id )
				{
					switch_to_blog( $id );
					$this->plugin_deactivated( false );
					restore_current_blog();
				}
				return;
			}
			
			$this->disable_cron();
			$this->get_downloads()->set_timeout(0)->delete_old_downloads();
		}
		
		/*
		 * Hook that adds a cron schedule
		 */
		public function cron_schedules( $schedules )
		{
			$interval = $this->get_downloads()->get_timeout();
			$display = self::replace_tags( __("Every {interval} seconds"), array( 'interval' => $interval ) );
			$schedules['pdf_forms_for_wpforms_cron_frequency'] = array(
				'interval' => $interval,
				'display' => $display
			);
			return $schedules;
		}
		
		/*
		 * Enables cron
		 */
		private function enable_cron()
		{
			$due = wp_next_scheduled( 'pdf_forms_for_wpforms_cron' );
			$current_time = time();
			
			$interval = $this->get_downloads()->get_timeout();
			
			if( $due !== false && (
					$due < $current_time - ( $interval + 60 ) // cron is not functional
					|| $due > $current_time + $interval // interval changed to a smaller value
				) )
			{
				$this->cron(); // run manually
				wp_clear_scheduled_hook( 'pdf_forms_for_wpforms_cron' );
				$due = false;
			}
			
			if( $due === false )
				wp_schedule_event( $current_time, 'pdf_forms_for_wpforms_cron_frequency', 'pdf_forms_for_wpforms_cron' );
		}
		
		/*
		 * Disables cron
		 */
		private function disable_cron()
		{
			wp_clear_scheduled_hook( 'pdf_forms_for_wpforms_cron' );
		}
		
		/**
		 * Executes scheduled tasks
		 */
		public function cron()
		{
			$this->get_downloads()->delete_old_downloads();
		}
		
		/**
		 * Adds plugin action links
		 */
		public function action_links( $links )
		{
			$links[] = '<a target="_blank" href="https://wordpress.org/support/plugin/pdf-forms-for-wpforms/">'.esc_html__( "Support", 'pdf-forms-for-wpforms' ).'</a>';
			return $links;
		}
		
		/**
		 * Prints admin notices
		 */
		public function admin_notices()
		{
			if( ! class_exists('WPForms') || ! defined( 'WPFORMS_VERSION' ) )
			{
				if( current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ) )
					echo Pdf_Forms_For_WPForms::render_error_notice( 'wpf-not-installed', array(
						'label'   => esc_html__( "PDF Forms Filler for WPForms Error", 'pdf-forms-for-wpforms' ),
						'message' => esc_html__( "The required plugin 'WPForms' version is not installed!", 'pdf-forms-for-wpforms' ),
					) );
				return;
			}
			
			if( ! $this->is_wpf_version_supported( WPFORMS_VERSION ) )
				if( current_user_can( 'update_plugins' ) )
					echo Pdf_Forms_For_WPForms::render_warning_notice( 'unsupported-wpf-version-'.WPFORMS_VERSION, array(
						'label'   => esc_html__( "PDF Forms Filler for WPForms Warning", 'pdf-forms-for-wpforms' ),
						'message' =>
							self::replace_tags(
								esc_html__( "The currently installed version of 'WPForms' plugin ({current-wpforms-version}) may not be supported by the current version of 'PDF Forms Filler for WPForms' plugin ({current-plugin-version}), please switch to 'WPForms' plugin version {supported-wpforms-version} or below to ensure that 'PDF Forms Filler for WPForms' plugin would work correctly.", 'pdf-forms-for-wpforms' ),
								array(
									'current-wpforms-version' => esc_html( defined( 'WPFORMS_VERSION' ) ? WPFORMS_VERSION : __( "Unknown version", 'pdf-forms-for-wpforms' ) ),
									'current-plugin-version' => esc_html( self::VERSION ),
									'supported-wpforms-version' => esc_html( self::MAX_WPFORMS_VERSION ),
								)
							),
					) );
			
			if( $service = $this->get_service() )
			{
				$service->admin_notices();
				if( $service != $this->pdf_ninja_service )
					$this->pdf_ninja_service->admin_notices();
			}
		}
		
		/**
		 * Checks if WPF version is supported
		 */
		public function is_wpf_version_supported( $version )
		{
			if( version_compare( $version, self::MIN_WPFORMS_VERSION ) < 0
			||  version_compare( $version, self::MAX_WPFORMS_VERSION ) > 0 )
				return false;
			
			foreach( self::$BLACKLISTED_WPFORMS_VERSIONS as $blacklisted_version )
				if( version_compare( $version, $blacklisted_version ) == 0 )
					return false;
			
			return true;
		}
		
		/**
		 * Returns the service module instance
		 */
		public function get_service()
		{
			$this->register_services();
			
			if( ! $this->service )
				$this->set_service( $this->load_pdf_ninja_service() );
			
			return $this->service;
		}
		
		/**
		 * Sets the service module instance
		 */
		public function set_service( $service )
		{
			return $this->service = $service;
		}
		
		/**
		 * Loads and returns the storage module
		 */
		private function get_storage()
		{
			if( ! $this->storage )
			{
				require_once untrailingslashit( dirname( __FILE__ ) ) . '/modules/storage.php';
				$this->storage = Pdf_Forms_For_WPForms_Storage::get_instance();
			}
			
			return $this->storage;
		}
		
		/**
		 * Loads and returns the downloads module
		 */
		private function get_downloads()
		{
			if( ! $this->downloads )
			{
				require_once untrailingslashit( dirname( __FILE__ ) ) . '/modules/downloads.php';
				$this->downloads = Pdf_Forms_For_WPForms_Downloads::get_instance();
			}
			return $this->downloads;
		}
		
		/**
		 * Registers PDF.Ninja service
		 */
		public function register_services()
		{
			if( $this->registered_services )
				return;
			
			require_once untrailingslashit( dirname( __FILE__ ) ) . '/modules/service.php';
			
			$this->registered_services = true;
			$this->load_pdf_ninja_service();
		}
		
		/**
		 * Loads the Pdf.Ninja service module
		 */
		private function load_pdf_ninja_service()
		{
			if( ! $this->pdf_ninja_service )
			{
				require_once untrailingslashit( dirname( __FILE__ ) ) . '/modules/pdf-ninja.php';
				$this->pdf_ninja_service = WPForms_Pdf_Ninja::get_instance();
			}
			
			return $this->pdf_ninja_service;
		}
		
		/**
		 * Function for working with metadata
		 */
		public static function get_meta( $post_id, $key )
		{
			$value = get_post_meta( $post_id, "pdf-forms-for-wpforms" . $key, $single=true );
			if( $value === '' )
				return null;
			return $value;
		}
		
		/**
		 * Function for working with metadata
		 */
		public static function set_meta( $post_id, $key, $value )
		{
			$oldval = get_post_meta( $post_id, "pdf-forms-for-wpforms" . $key, true );
			if( $oldval !== '' && $value === null)
				delete_post_meta( $post_id, "pdf-forms-for-wpforms" . $key );
			else
			{
				// wp bug workaround
				// https://developer.wordpress.org/reference/functions/update_post_meta/#workaround
				$fixed_value = wp_slash( $value );
				
				update_post_meta( $post_id, "pdf-forms-for-wpforms" . $key, $fixed_value, $oldval );
			}
			return $value;
		}
		
		/**
		 * Function for working with metadata
		 */
		public static function unset_meta( $post_id, $key )
		{
			delete_post_meta( $post_id, "pdf-forms-for-wpforms" . $key );
		}
		
		/**
		 * Hook that runs on user click 'Get New key' button and gets new pdf-ninja key
		 */
		public function wp_ajax_generate_pdf_ninja_key()
		{
			try
			{
				if( ! check_ajax_referer( 'pdf-forms-for-wpforms-ajax-nonce', 'nonce', false ) )
					throw new Exception( __( "Nonce mismatch", 'pdf-forms-for-wpforms' ) );
				
				if( ! wpforms_current_user_can( wpforms_get_capability_manage_options() ) )
					throw new Exception( __( "Permission denied", 'pdf-forms-for-wpforms' ) );
				
				$service = $this->get_service();
				
				// TODO: get email from form, remove email setting
				if( empty( $email = wpforms_setting( WPForms_Pdf_Ninja::VIEW . '-email' ) ) )
					$email = null;
				else
					$email = sanitize_email( $email );
				
				$service->set_key( $key = $service->generate_key( $email ) );
			}
			catch ( Exception $e )
			{
				return wp_send_json( array (
					'success' => false,
					'error_message' => $e->getMessage()
				) );
			}
			
			wp_send_json_success();
		}
		
		const DEFAULT_PDF_OPTIONS = array( 'skip_empty' => false, 'flatten' => false, 'notifications' => array( ), 'confirmations' => array(), 'filename' => "", 'save_directory'=> "" );
		
		/**
		 * Hook that runs on wpform save action to validate plugin data
		 */
		public function wpforms_save_form_args( $post, $form_data, $args )
		{
			try
			{
				$post_content = json_decode( stripslashes( $post['post_content'] ), true );
				if( is_array( $post_content ) )
				{
					if( isset( $post_content['fields'] ) )
						$wpf_fields = $post_content['fields'];
					else
						$wpf_fields = array();
					
					// check form settings
					if( isset( $post_content['pdf-forms-for-wpforms-form-settings'] ) && isset( $post_content['pdf-forms-for-wpforms-form-settings']['data'] ) )
					{
						$data = self::decode_form_settings( $post_content['pdf-forms-for-wpforms-form-settings']['data'] );
						
						// check attachments
						$attachments = array();
						if( isset( $data['attachments'] ) && is_array( $data['attachments'] ) )
						{
							foreach( $data['attachments'] as $attachment )
							{
								$attachment_id = $attachment['attachment_id'];
								
								// check permissions
								if( ! wpforms_current_user_can( 'edit_post', $attachment_id ) )
									continue;
								
								// check options
								if( !isset( $attachment['options'] ) || !is_array( $attachment['options'] ) )
									$attachment['options'] = array();
								else
								{
									// add missing options
									foreach( self::DEFAULT_PDF_OPTIONS as $option_name => $option_value )
									{
										if( !isset( $attachment['options'][$option_name] ) )
											$attachment['options'][$option_name] = $option_value;
									}
									
									// remove non-existing options
									foreach( $attachment['options'] as $option_name => $option_value )
									{
										if( !isset( self::DEFAULT_PDF_OPTIONS[$option_name] ) )
											unset( $attachment['options'][$option_name] );
									}
									
									// check skip_empty to make sure it is a boolean value
									if( isset( $attachment['options']['skip_empty'] ) && !is_bool( $attachment['options']['skip_empty'] ) )
										$attachment['options']['skip_empty'] = boolval( $attachment['options']['skip_empty'] );
									
									// check flatten to make sure it is a boolean value
									if( isset( $attachment['options']['flatten'] ) && !is_bool( $attachment['options']['flatten'] ) )
										$attachment['options']['flatten'] = boolval( $attachment['options']['flatten'] );
									
									// check notifications
									if( isset( $attachment['options']['notifications'] ) )
									{
										if( !is_array( $option_value ) )
											$option_value = array();
										foreach( $option_value as $notification_id => $notification )
										{
											// check to make sure this notification is valid
											if( !isset( $post_content['notifications'][$notification_id] ) )
												unset( $attachment['options']['notifications'][$notification_id] );
										}
									}
									
									// check confirmations
									if( isset( $attachment['options']['confirmations'] ) )
									{
										if( !is_array( $option_value ) )
											$option_value = array();
										foreach( $option_value as $confirmation_id => $confirmation )
										{
											// check to make sure this confirmation is valid
											if( !isset( $post_content['confirmations'][$confirmation_id] ) )
												unset( $attachment['options']['confirmations'][$confirmation_id] );
										}
									}
								}
								
								$attachments[$attachment_id] = $attachment;
							}
						}
						$data['attachments'] = $attachments;
						
						// process mappings
						$mappings = array();
						if( isset( $data['mappings'] ) && is_array( $data['mappings'] ) )
						{
							foreach( $data['mappings'] as $mapping )
							{
								if( isset( $mapping['wpf_field'] ) && isset( $mapping['pdf_field'] ) )
								{
									// make sure wpforms field exists
									$exists = false;
									foreach( $wpf_fields as $field )
										if( isset( $field['id'] ) && $field['id'] == $mapping['wpf_field'] )
										{
											$exists = true;
											break;
										}
									if( ! $exists )
										continue;
									
									// TODO: make sure pdf field exists
									
									$mappings[] = array( 'wpf_field' => $mapping['wpf_field'], 'pdf_field' => $mapping['pdf_field'] );
								}
								
								
								if( isset( $mapping['smart_tags'] ) && isset( $mapping['pdf_field'] ) )
								{
									$mappings[] = array( 'smart_tags' => $mapping['smart_tags'], 'pdf_field' => $mapping['pdf_field'] );
								}
							}
						}
						$data['mappings'] = $mappings;
						
						// process embeds
						$embeds = array();
						if( isset( $data['embeds'] ) && is_array( $data['embeds'] ) )
						{
							foreach( $data['embeds'] as $embed )
							{
								if( ! isset( $embed['attachment_id'] ) )
									continue;
								
								if( ! isset( $embed['smart_tags'] ) && ! isset( $embed['wpf_field'] ) )
									continue;
								
								// make sure attachment exists
								if( ! isset( $data['attachments'][ $embed['attachment_id'] ] ) && $embed['attachment_id'] !== 'all' )
									continue;
								
								if( isset( $embed['wpf_field'] ) )
								{
									// make sure wpforms field exists
									$exists = false;
									foreach( $wpf_fields as $field )
										if( isset( $field['id'] ) && $field['id'] == $embed['wpf_field'] )
										{
											$exists = true;
											break;
										}
									if( ! $exists )
										continue;
								}
								
								// TODO: make sure pdf page exists
								// TODO: check insertion position and size
								
								if( isset( $embed['smart_tags'] ) )
									$embed['smart_tags'] = strval( $embed['smart_tags'] );
								
								// TODO: don't reuse user input but create a new array with checked data
								$embeds[] = $embed;
							}
						}
						$data['embeds'] = $embeds;
						
						// process value mappings
						if( !isset( $data['value_mappings'] ) || !is_array( $data['value_mappings'] ) )
							$data['value_mappings'] = array();
						else
						{
							$value_mappings = array();
							foreach( $data['value_mappings'] as $value_mapping )
								if( isset( $value_mapping['pdf_field'] ) && isset( $value_mapping['wpf_value'] ) && isset( $value_mapping['pdf_value'] ) )
									$value_mappings[] = array( 'pdf_field' => $value_mapping['pdf_field'], 'wpf_value' => $value_mapping['wpf_value'], 'pdf_value' => $value_mapping['pdf_value'] );
							$data['value_mappings'] = $value_mappings;
						}
						
						$post_content['pdf-forms-for-wpforms-form-settings']['data'] = self::encode_form_settings( $data );
					}
					
					$post['post_content'] = wpforms_encode( $post_content );
				}
				
				return $post;
			}
			catch(Exception $e)
			{
				// TODO: improve error handling via wpforms
				wp_send_json_error(
					esc_html(
						self::replace_tags(
							__( "Error saving PDF form data: {error-message} at {error-file}:{error-line}", 'pdf-forms-for-wpforms' ),
							array( 'error-message' => $e->getMessage(), 'error-file' => wp_basename( $e->getFile() ), 'error-line' => $e->getLine() )
						)
					)
				);
				exit(0);
			}
		}
		
		/**
		 * Returns MIME type of the file
		 */
		public static function get_mime_type( $filepath )
		{
			$mimetype = null;
			
			if( file_exists( $filepath ) )
			{
				if( function_exists( 'finfo_open' ) )
				{
					if( version_compare( phpversion(), "5.3" ) < 0 )
					{
						$finfo = finfo_open( FILEINFO_MIME );
						if( $finfo )
						{
							$mimetype = finfo_file( $finfo, $filepath );
							$mimetype = explode( ";", $mimetype );
							$mimetype = reset( $mimetype );
							finfo_close( $finfo );
						}
					}
					else
					{
						$finfo = finfo_open( FILEINFO_MIME_TYPE );
						if( $finfo )
						{
							$mimetype = finfo_file( $finfo, $filepath );
							finfo_close( $finfo );
						}
					}
				}
				
				if( ! $mimetype && function_exists( 'mime_content_type' ) )
					$mimetype = mime_content_type( $filepath );
			}
			
			// fallback
			if( ! $mimetype )
			{
				$type = wp_check_filetype( $filepath );
				if( isset( $type['type'] ) )
					$mimetype = $type['type'];
			}
			
			return $mimetype;
		}
		
		/**
		 * Downloads a file from the given URL and saves the contents to the given filepath, returns mime type via argument
		 */
		private static function download_file( $url, $filepath, &$mimetype = null )
		{
			// if this url points to the site, copy the file directly
			$site_url = trailingslashit( get_site_url() );
			if( substr( $url, 0, strlen( $site_url ) ) == $site_url )
			{
				$path = substr( $url, strlen( $site_url ) );
				$home_path = trailingslashit( realpath( dirname(__FILE__) . '/../../../' ) );
				$sourcepath = realpath( $home_path . $path );
				if( $home_path && $sourcepath && substr( $sourcepath, 0, strlen( $home_path ) ) == $home_path )
					if( file_exists( $sourcepath ) )
						if( copy($sourcepath, $filepath) )
						{
							$mimetype = self::get_mime_type( $sourcepath );
							return;
						}
			}
			
			$args = array(
				'compress'    => true,
				'decompress'  => true,
				'timeout'     => 100,
				'redirection' => 5,
				'user-agent'  => 'pdf-forms-for-wpforms/' . self::VERSION,
			);
			
			$response = wp_remote_get( $url, $args );
			
			if( is_wp_error( $response ) )
				throw new Exception(
					self::replace_tags(
							__( "Failed to download file: {error-message}", 'pdf-forms-for-wpforms' ),
							array( 'error-message' => $response->get_error_message() )
						)
				);
			
			$mimetype = wp_remote_retrieve_header( $response, 'content-type' );
			
			$body = wp_remote_retrieve_body( $response );
			
			$handle = @fopen( $filepath, 'w' );
			
			if( ! $handle )
				throw new Exception( __( "Failed to open file for writing", 'pdf-forms-for-wpforms' ) );
			
			fwrite( $handle, $body );
			fclose( $handle );
			
			if( ! file_exists( $filepath ) )
				throw new Exception( __( "Failed to create file", 'pdf-forms-for-wpforms' ) );
		}
		
		/**
		 * Get temporary directory path
		 */
		public static function get_tmp_path()
		{
			$upload_dir = wpforms_upload_dir();
			$tmp_path = empty( $upload_dir['error'] ) ? ( trailingslashit( $upload_dir['path'] ) . 'tmp' ) : get_temp_dir();
			
			$dir = trailingslashit( $tmp_path ) . 'pdf-forms-for-wpforms';
			
			if( ! is_dir( $dir ) )
			{
				wp_mkdir_p( $dir );
				wpforms_create_index_html_file( $dir );
			}
			
			return trailingslashit( $dir );
		}
		
		/**
		 * Creates a temporary directory
		 */
		public function create_tmp_dir()
		{
			if( ! $this->tmp_dir )
			{
				$dir = trailingslashit( self::get_tmp_path() ) . wp_hash( wp_rand() . microtime() );
				wp_mkdir_p( $dir );
				$this->tmp_dir = trailingslashit( $dir );
			}
			
			return $this->tmp_dir;
		}
		
		/**
		 * Removes a temporary directory
		 */
		public function remove_tmp_dir()
		{
			if( ! $this->tmp_dir )
				return;
			
			// remove files in the directory
			$tmp_dir_slash = trailingslashit( $this->tmp_dir );
			$files = array_merge( glob( $tmp_dir_slash . '*' ), glob( $tmp_dir_slash . '.*' ) );
			while( $file = array_shift( $files ) )
				if( is_file( $file ) )
					@unlink( $file );
			
			@rmdir( $this->tmp_dir );
			$this->tmp_dir = null;
		}
		
		/**
		 * Creates a temporary file path (but not the file itself)
		 */
		private function create_tmp_filepath( $filename )
		{
			$uploads_dir = $this->create_tmp_dir();
			$filename = sanitize_file_name( $filename );
			$filename = wp_unique_filename( $uploads_dir, $filename );
			return trailingslashit( $uploads_dir ) . $filename;
		}
		
		/**
		 * Checks if the image MIME type is supported for embedding
		 */
		private function is_embed_image_format_supported( $mimetype )
		{
			$supported_mime_types = array(
					"image/jpeg",
					"image/png",
					"image/gif",
					"image/tiff",
					"image/bmp",
					"image/x-ms-bmp",
					"image/svg+xml",
					"image/webp",
				);
			
			if( $mimetype )
				foreach( $supported_mime_types as $smt )
					if( $mimetype === $smt )
						return true;
			
			return false;
		}
		
		/**
		 * Used for encoding WPForms form settings data
		 */
		public static function encode_form_settings( $data )
		{
			return base64_encode( self::json_encode( $data ) );
		}
		
		/**
		 * Used for decoding WPForms form settings data
		 */
		public static function decode_form_settings( $data )
		{
			$form_settings = array();
			// we can't use json directly, we have to use encoding without slashes and quotes due to an unnecessary stripslashes() call in wpforms/includes/admin/builder/panels/class-base.php
			if( is_array( $base64_decoded = json_decode( base64_decode( $data ), true ) ) )
				$form_settings = $base64_decoded;
			else
				if( is_array( $json_decoded = json_decode( $data, true ) ) )
					$form_settings = $json_decoded;
			return $form_settings;
		}
		
		/**
		 * We need to fill the pdf's document fields and then create attachment file and attach them
		 */
		public function fill_pdfs( $wpform_fields, $entry, $form_data )
		{
			try
			{
				$entry_id = $entry["id"];
				$wpform_fields_data = $entry['fields'];
				
				$attachments = array();
				$mappings = array();
				$embeds = array();
				$value_mappings = array();
				
				if( isset( $form_data['pdf-forms-for-wpforms-form-settings'] ) && isset( $form_data['pdf-forms-for-wpforms-form-settings']['data'] ) )
				{
					$form_settings = self::decode_form_settings( $form_data['pdf-forms-for-wpforms-form-settings']['data'] );
					if( isset( $form_settings['attachments'] ) && is_array( $form_settings['attachments'] ) )
						$attachments = $form_settings['attachments'];
					if( isset( $form_settings['mappings'] ) && is_array( $form_settings['mappings'] ) )
						$mappings = $form_settings['mappings'];
					if( isset( $form_settings['embeds'] ) && is_array( $form_settings['embeds'] ) )
						$embeds = $form_settings['embeds'];
					if( isset( $form_settings['value_mappings'] ) && is_array( $form_settings['value_mappings'] ))
						$value_mappings = $form_settings['value_mappings'];
				}
				
				$files = array();
				
				// preprocess embedded images
				$embed_files = array();
				foreach( $embeds as $id => $embed )
				{
					$filepath = null;
					$filename = null;
					$url_mimetype = null;
					
					$url = null;
					
					if( isset( $embed["wpf_field"] ) )
					{
						$wpf_field_id = $embed["wpf_field"];
						$url = $wpform_fields_data[$wpf_field_id];
					}
					if( isset( $embed['smart_tags'] ) ) 
					{
						$url = wpforms_process_smart_tags( $embed["smart_tags"], $form_data, $wpform_fields, $entry_id );
					}
					
					if( $url != null )
					{
						if( filter_var( $url, FILTER_VALIDATE_URL ) !== FALSE )
						if( substr( $url, 0, 5 ) === 'http:' || substr( $url, 0, 6 ) === 'https:' )
						{
							$filepath = $this->create_tmp_filepath( 'img_download_'.count($embed_files).'.tmp' );
							self::download_file( $url, $filepath, $url_mimetype ); // can throw an exception
							$filename = $url;
						}
						
						if( substr( $url, 0, 5 ) === 'data:' )
						{
							$filepath = $this->create_tmp_filepath( 'img_data_'.count($embed_files).'.tmp' );
							$filename = $url;
							
							$parsed = self::parse_data_uri( $url );
							if( $parsed !== false )
							{
								$url_mimetype = $parsed['mime'];
								file_put_contents( $filepath, $parsed['data'] );
							}
						}
					}
					
					if( isset( $embed["wpf_field"] ) )
					{
						$wpf_field_id = $embed["wpf_field"];
						
						$field = null;
						if( isset( $form_data['fields'][$wpf_field_id] ) )
						{
							$field = $form_data['fields'][$wpf_field_id];
							if( is_array( $field ) && $field['type'] == 'file-upload' )
							{
								$input_name = sprintf( 'wpforms_%d_%d', $entry_id, $field['id'] );
								if( $field['style'] == 'modern' && isset( $_POST[$input_name] ) )
								{
									$raw_files = json_decode( wp_unslash( $_POST[$input_name] ), true );
									if( is_array( $raw_files ) && sizeof( $raw_files ) > 0 )
									{
										// TODO: handle multiple files
										$file = reset( $raw_files );
										$upload_dir = wpforms_upload_dir();
										$upload_path = trailingslashit( $upload_dir['path'] ) . 'tmp';
										$upload_filepath = trailingslashit( $upload_path ) . wp_basename( $file['file'] );
										// security check
										$upload_filepath = realpath( $upload_filepath );
										$upload_path = realpath( $upload_path );
										if( $upload_filepath !== false && $upload_path !== false
										&& substr( $upload_filepath, 0, strlen( $upload_path ) ) === $upload_path )
										{
											$filepath = $upload_filepath;
											$filename = $file['file_user_name'];
										}
									}
								}
								else if( ! empty( $_FILES[$input_name] ) )
								{
									$filepath = $_FILES[$input_name]['tmp_name'];
									$filename = $_FILES[$input_name]['name'];
								}
							}
						}
					}
					
					if( ! $filepath )
						continue;
					
					$file_mimetype = self::get_mime_type( $filepath );
					
					$mimetype_supported = false;
					$mimetype = 'unknown';
					
					if( $file_mimetype )
					{
						$mimetype = $file_mimetype;
						
						// check if MIME type is supported based on file contents
						$mimetype_supported = $this->is_embed_image_format_supported( $file_mimetype );
					}
					
					// if we were not able to determine MIME type based on file contents
					// then fall back to URL MIME type (if we are dealing with a URL)
					// (maybe fileinfo functions are not functional and WP fallback failed due to the ".tmp" extension)
					if( !$mimetype_supported && $url_mimetype )
					{
						$mimetype = $url_mimetype;
						$mimetype_supported = $this->is_embed_image_format_supported( $url_mimetype );
					}
					
					if( !$mimetype_supported )
						throw new Exception(
							self::replace_tags(
								__( "File type {mime-type} of {file} is unsupported for {purpose}", 'pdf-forms-for-wpforms' ),
								array( 'mime-type' => $mimetype, 'file' => $filename, 'purpose' => __( "image embedding", 'pdf-forms-for-wpforms') )
							)
						);
					
					$embed_files[$id] = $filepath;
				}
				
				$multiselectable_wpform_fields = array();
				foreach( $wpform_fields as $wpform_field )
					if( array_key_exists( 'name', $wpform_field ) )
					{
						if(
							// WPForms checkboxes can have multiple values if the choice limit is more than one
							( $wpform_field['type'] == 'checkbox' && intval( $form_data['fields'][$wpform_field['id']]['choice_limit'] ) != 1 )
							
							// WPForms drop-downs can have 'multiple' option
							|| ( $wpform_field['type'] == 'select' && boolval( $form_data['fields'][$wpform_field['id']]['multiple'] ) )
							
							// support for unknown field types: if field data is an array then it must be that this field supports multiple values
							|| ( is_array( $wpform_fields_data[$wpform_field['id']] ) )
						)
							$multiselectable_wpform_fields[$wpform_field['name']] = $wpform_field['name'];
					}
				
				foreach( $attachments as $attachment )
				{
					$attachment_id = $attachment["attachment_id"];
					
					$fields = $this->get_fields( $attachment_id );
					$data = array();
					
					// process mappings
					foreach( $mappings as $mapping )
					{
						$i = strpos( $mapping["pdf_field"], '-');
						if( $i === FALSE )
							continue;
						
						$aid = substr( $mapping["pdf_field"], 0, $i );
						if( $aid != $attachment_id && $aid != 'all' )
							continue;
						
						$field = substr( $mapping["pdf_field"], $i+1);
						$field = self::base64url_decode( $field );
						
						if( !isset( $fields[$field] ) )
							continue;
						
						$multiple =
							   ( isset( $mapping["wpf_field"] ) && isset( $multiselectable_wpform_fields[ $mapping["wpf_field"] ] ) )
							|| ( isset( $fields[$field]['flags'] ) && in_array( 'MultiSelect', $fields[$field]['flags'] ) );
						
						if( isset( $mapping["wpf_field"] ) )
							$data[$field] = $wpform_fields_data[$mapping["wpf_field"]];
						
						if( isset( $mapping["smart_tags"] ) )
						{
							$data[$field] = wpforms_process_smart_tags( $mapping["smart_tags"], $form_data, $wpform_fields, $entry_id );
							
							if( $multiple )
							{
								$data[$field] = explode( "\n" , $data[$field] );
								foreach( $data[$field] as &$value )
									$value = trim( $value );
								unset( $value );
							}
						}
					}
					
					if( count( $value_mappings ) > 0 )
					{
						// process value mappings
						$processed_value_mappings = array();
						$value_mapping_data = array();
						$existing_data_fields = array_fill_keys( array_keys( $data ), true );
						foreach( $value_mappings as $value_mapping )
						{
							$i = strpos( $value_mapping["pdf_field"], '-' );
							if( $i === FALSE )
								continue;
							
							$aid = substr( $value_mapping["pdf_field"], 0, $i );
							if( $aid != $attachment_id && $aid != 'all' )
								continue;
							
							$field = substr( $value_mapping["pdf_field"], $i+1 );
							$field = self::base64url_decode( $field );
							
							if( !isset( $existing_data_fields[$field] ) )
								continue;
							
							if( !isset( $value_mapping_data[$field] ) )
								$value_mapping_data[$field] = $data[$field];
							
							$wpf_value = strval( $value_mapping['wpf_value'] );
							if( ! isset( $processed_value_mappings[$field] ) )
								$processed_value_mappings[$field] = array();
							if( ! isset( $processed_value_mappings[$field][$wpf_value] ) )
								$processed_value_mappings[$field][$wpf_value] = array();
							$processed_value_mappings[$field][$wpf_value][] = $value_mapping;
						}
						
						// convert plain text values to arrays for processing
						foreach( $value_mapping_data as $field => &$value )
							if( ! is_array( $value ) )
								$value = array( $value );
						unset( $value );
						
						// determine old and new values
						$add_data = array();
						$remove_data = array();
						foreach($processed_value_mappings as $field => $wpf_mappings_list)
							foreach($wpf_mappings_list as $wpf_value => $list)
							{
								foreach( $value_mapping_data[$field] as $key => $value )
									if( Pdf_Forms_For_WPForms_Wrapper::mb_strtolower( $value ) === Pdf_Forms_For_WPForms_Wrapper::mb_strtolower( $wpf_value ) )
									{
										if( ! isset( $remove_data[$field] ) )
											$remove_data[$field] = array();
										$remove_data[$field][] = $value;
										
										if( ! isset( $add_data[$field] ) )
											$add_data[$field] = array();
										foreach( $list as $item )
											$add_data[$field][] = $item['pdf_value'];
									}
							}
						
						// remove old values
						foreach( $value_mapping_data as $field => &$value )
							if( isset( $remove_data[$field] ) )
								$value = array_diff( $value, $remove_data[$field] );
						unset( $value );
						
						// add new values
						foreach( $value_mapping_data as $field => &$value )
							if( isset( $add_data[$field] ) )
								$value = array_unique( array_merge( $value, $add_data[$field] ) );
						unset( $value );
						
						// convert arrays back to plain text where needed
						foreach( $value_mapping_data as $field => &$value )
							if( count( $value ) < 2 )
							{
								if( count( $value ) > 0 )
									$value = reset( $value );
								else
									$value = null;
							}
						unset( $value );
						
						// update data
						foreach( $value_mapping_data as $field => &$value )
							$data[$field] = $value;
						unset( $value );
					}
					
					// filter out anything that the pdf field can't accept
					foreach( $data as $field => &$value )
					{
						$type = $fields[$field]['type'];
						
						if( $type == 'radio' || $type == 'select' || $type == 'checkbox' )
						{
							// compile a list of field options
							$pdf_field_options = null;
							if( isset( $fields[$field]['options'] ) && is_array( $fields[$field]['options'] ) )
							{
								$pdf_field_options = $fields[$field]['options'];
								
								// if options are have more information than value, extract only the value
								foreach( $pdf_field_options as &$option )
									if( is_array( $option ) && isset( $option['value'] ) )
										$option = $option['value'];
								unset( $option );
							}
							
							// if a list of options are available then filter $value
							if( $pdf_field_options !== null )
							{
								if( is_array( $value ) )
									$value = array_intersect( $value, $pdf_field_options );
								else
									$value = in_array( $value, $pdf_field_options ) ? $value : null;
							}
						}
						
						// if pdf field is not a multiselect field but value is an array then use the first element only
						$pdf_field_multiselectable = isset( $fields[$field]['flags'] ) && in_array( 'MultiSelect', $fields[$field]['flags'] );
						if( !$pdf_field_multiselectable && is_array( $value ) )
						{
							if( count( $value ) > 0 )
								$value = reset( $value );
							else
								$value = null;
						}
					}
					unset( $value );
					
					// process image embeds
					$embeds_data = array();
					foreach( $embeds as $id => $embed )
						if( $embed['attachment_id'] == $attachment_id || $embed['attachment_id'] == 'all' )
						{
							if( isset( $embed_files[$id] ) )
							{
								$embed_data = array(
									'image' => $embed_files[$id],
									'page' => $embed['page'],
								);
								
								if($embed['page'] > 0)
								{
									$embed_data['left'] = $embed['left'];
									$embed_data['top'] = $embed['top'];
									$embed_data['width'] = $embed['width'];
									$embed_data['height'] = $embed['height'];
								};
								
								$embeds_data[] = $embed_data;
							}
						}
					
					// skip file if 'skip when empty' option is enabled and form data is blank
					if($attachment['options']['skip_empty'] )
					{
						$empty_data = true;
						foreach( $data as $field => $value )
							if( !( is_null( $value ) || $value === array() || trim( $value ) === "" ) )
							{
								$empty_data = false;
								break;
							}
						
						if( $empty_data && count( $embeds_data ) == 0 )
							continue;
					}
					
					$notifications = $attachment['options']['notifications'];
					$confirmations = $attachment['options']['confirmations'];
					$save_directory = strval( $attachment['options']['save_directory'] );
					$create_download_link = sizeof( $attachment['options']['confirmations'] ) > 0;
					
					// skip file if it is not used anywhere
					if( empty( $notifications )
					&& empty( $confirmations )
					&& empty( $save_directory )
					&& !$create_download_link )
						continue;
					
					$options = array();
					
					$options['flatten'] =
						isset($attachment['options']) &&
						isset($attachment['options']['flatten']) &&
						$attachment['options']['flatten'] == true;
					
					// determine if the attachment would be changed
					$filling_data = false;
					foreach( $data as $field => $value )
					{
						if( $value === null || $value === '' )
							$value = array();
						else if( ! is_array( $value ) )
							$value = array( $value );
						
						$pdf_value = null;
						if( isset( $fields[$field]['value'] ) )
							$pdf_value = $fields[$field]['value'];
						if( $pdf_value === null || $pdf_value === '' )
							$pdf_value = array();
						else if( ! is_array( $pdf_value ) )
							$pdf_value = array( $pdf_value );
						
						// check if values don't match
						if( ! ( array_diff( $value, $pdf_value ) == array() && array_diff( $pdf_value, $value ) == array() ) )
						{
							$filling_data = true;
							break;
						}
					}
					$attachment_affected = $filling_data || count( $embeds_data ) > 0 || $options['flatten'];
					
					$filepath = get_attached_file( $attachment_id );
					
					$filename = strval( $attachment['options']['filename'] );
					if ( $filename !== "" )
						$destfilename = wpforms_process_smart_tags( $filename, $form_data, $wpform_fields, $entry_id );
					else
						$destfilename = $filepath;
					
					$destfilename = wp_basename( empty( $destfilename ) ? $filepath : $destfilename, '.pdf' );
					$destfile = $this->create_tmp_filepath( $destfilename . '.pdf' );
					
					try
					{
						$service = $this->get_service();
						$filled = false;
						
						if( $service )
							// we only want to use the API when something needs to be done to the file
							if( $attachment_affected )
								$filled = $service->api_fill_embed( $destfile, $attachment_id, $data, $embeds_data, $options );
						
						if( ! $filled )
							copy( $filepath, $destfile );
						$files[] = array( 'attachment_id' => $attachment_id, 'file' => $destfile, 'filename' => $destfilename . '.pdf', 'options' => $attachment['options'] );
					}
					catch(Exception $e)
					{
						throw new Exception(
							self::replace_tags(
								__( "Error generating PDF: {error-message} at {error-file}:{error-line}", 'pdf-forms-for-wpforms' ),
								array( 'error-message' => $e->getMessage(), 'error-file' => wp_basename( $e->getFile() ), 'error-line' => $e->getLine() )
							)
						);
					}
				}
				
				// files will be attached to email messages in attach_files()
				$this->wpforms_mail_attachments = $files;
				
				if( count( $files ) > 0 )
				{
					$storage = $this->get_storage();
					foreach( $files as $id => $filedata )
					{
						$save_directory = strval( $filedata['options']['save_directory'] );
						if( $save_directory !== "" )
						{
							// standardize directory separator
							$save_directory = str_replace( '\\', '/', $save_directory );
							
							// remove preceding slashes and dots and space characters
							$trim_characters = "/\\. \t\n\r\0\x0B";
							$save_directory = trim( $save_directory, $trim_characters );
							
							// replace smart tags in path elements
							$path_elements = explode( "/", $save_directory );
							$tag_replaced_path_elements = array();
							foreach ( $path_elements as $key => $value )
								$tag_replaced_path_elements[$key] = wpforms_process_smart_tags( $value, $form_data, $wpform_fields, $entry_id );
							
							foreach( $tag_replaced_path_elements as $elmid => &$new_element )
							{
								// sanitize
								$new_element = trim( sanitize_file_name( $new_element ), $trim_characters );
								
								// if replaced and sanitized filename is blank then attempt to use the non-tag-replaced version
								if( $new_element === "" )
									$new_element = trim( sanitize_file_name( $path_elements[$elmid] ), $trim_characters );
							}
							unset($new_element);
							
							$save_directory = implode( "/", $tag_replaced_path_elements );
							$save_directory = preg_replace( '|/+|', '/', $save_directory ); // remove double slashes
							
							$storage->set_subpath( $save_directory );
							$storage->save( $filedata['file'], $filedata['filename'] );
						}
						
						$create_download_link = sizeof( $filedata['options']['confirmations'] ) > 0;
						if( $create_download_link )
							$this->get_downloads()->add_file( $filedata['file'], $filedata['filename'], array( 'confirmations' => $filedata['options']['confirmations'] ) );
					}
				}
			}
			catch( Exception $e )
			{
				wpforms()->process->errors[ $form_data['id'] ]['header'] = __( "PDF Forms Filler for WPForms Error", 'pdf-forms-for-wpforms' );
				wpforms()->process->errors[ $form_data['id'] ]['footer'] =
						self::replace_tags(
							__( "Error generating PDF: {error-message} at {error-file}:{error-line}", 'pdf-forms-for-wpforms' ),
							array( 'error-message' => $e->getMessage(), 'error-file' => wp_basename( $e->getFile() ), 'error-line' => $e->getLine() )
						);
				
				// clean up
				$this->remove_tmp_dir();
				$this->wpforms_mail_attachments = array();
			}
		}
		
		/**
		 * Attach files to notifications
		 */
		public function attach_files( $emailData, $wpforms )
		{
			$notification_id = $wpforms->notification_id;
			
			foreach( $this->wpforms_mail_attachments as $file )
				foreach( $file['options']['notifications'] as $id )
					if( $notification_id == $id )
					{
						$emailData['attachments'][] = $file['file'];
						break;
					}
			
			return $emailData;
		}
		
		/**
		 * Add download link to confirmation
		 */
		public function change_confirmation_message( $confirmation_message, $form_data, $fields, $entry_id )
		{
			if( $this->downloads )
			{
				$confirmation = wpforms()->get('process')->get_current_confirmation();
				if( ! ( $confirmation_id = array_search( $confirmation, $form_data['settings']['confirmations'] ) ) )
					return $confirmation_message;
				
				foreach( $this->downloads->get_files() as $file )
					foreach( $file['metadata']['confirmations'] as $id )
						if( $id == $confirmation_id )
						{
							$confirmation_message .= "<div>".
								self::replace_tags(
									esc_html__( "{icon} {a-href-url}{filename}{/a} {i}({size}){/i}", 'pdf-forms-for-wpforms' ),
									array(
										'icon' => '<span class="dashicons dashicons-download"></span>',
										'a-href-url' => '<a href="' . esc_html( $file['url'] ) . '" download>',
										'filename' => esc_html( $file['filename'] ),
										'/a' => '</a>',
										'i' => '<span class="file-size">',
										'size' => esc_html( size_format( filesize( $file['filepath'] ) ) ),
										'/i' => '</span>',
									)
								)
								. "</div>";
							break;
						}
				
				// make sure to enable cron if it is down so that old download files get cleaned up
				$this->enable_cron();
			}
			
			return $confirmation_message;
		}
		
		/**
		 * Takes html template from the html folder and renders it with the given attributes
		 */
		public static function render( $template, $attributes = array() )
		{
			return self::render_file( plugin_dir_path(__FILE__) . 'html/' . $template . '.html', $attributes );
		}
		
		/**
		 * Renders a notice with the given attributes
		 */
		public static function render_notice( $notice_id, $type, $attributes = array() )
		{
			if( ! isset( $attributes['classes'] ) )
				$attributes['classes'] = "";
			$attributes['classes'] = trim( $attributes['classes'] . " notice-$type" );
			
			if( !isset( $attributes['label'] ) )
				$attributes['label'] = __( "PDF Forms Filler for WPForms" );
			
			if( $notice_id )
			{
				$attributes['attributes'] = 'data-notice-id="'.esc_attr( $notice_id ).'"';
				$attributes['classes'] .= ' is-dismissible';
			}
			
			return self::render( "notice", $attributes );
		}
		
		/**
		 * Renders a success notice with the given attributes
		 */
		public static function render_success_notice( $notice_id, $attributes = array() )
		{
			return self::render_notice( $notice_id, 'success', $attributes );
		}
		
		/**
		 * Renders a warning notice with the given attributes
		 */
		public static function render_warning_notice( $notice_id, $attributes = array() )
		{
			return self::render_notice( $notice_id, 'warning', $attributes );
		}
		
		/**
		 * Renders an error notice with the given attributes
		 */
		public static function render_error_notice( $notice_id, $attributes = array() )
		{
			return self::render_notice( $notice_id, 'error', $attributes );
		}
		
		/**
		 * Helper for replace_tags function
		 */
		private static function add_curly_braces($str)
		{
			return '{'.$str.'}';
		}
		
		/**
		 * Takes a string with tags and replaces tags in the string with the given values in $tags array
		 */
		public static function replace_tags( $string, $tags = array() )
		{
			return str_replace(
				array_map( array( get_class(), 'add_curly_braces' ), array_keys( $tags ) ),
				array_values( $tags ),
				$string
			);
		}
		
		/**
		 * Takes html template file and renders it with the given attributes
		 */
		public static function render_file( $template_filepath, $attributes = array() )
		{
			return self::replace_tags( file_get_contents( $template_filepath ) , $attributes );
		}
		
		/**
		 * Adds the 'PDF Forms' section to list of form settings
		 */
		public function add_settings_content( $instance )
		{
			$messages = '';
			$attachments = array();
			
			try
			{
				$service = $this->get_service();
				if( $service )
					$messages .= $service->form_notices();
				
				if( isset( $instance->form_data['pdf-forms-for-wpforms-form-settings'] ) && isset( $instance->form_data['pdf-forms-for-wpforms-form-settings']['data'] ) )
				{
					$form_settings = self::decode_form_settings( $instance->form_data['pdf-forms-for-wpforms-form-settings']['data'] );
					if( isset( $form_settings['attachments'] ) && is_array( $form_settings['attachments'] ) )
					foreach( $form_settings['attachments'] as $attachment )
					{
						$attachment_id = $attachment['attachment_id'];
						$info = $this->get_info( $attachment_id );
						$info['fields'] = $this->query_pdf_fields( $attachment_id );
						
						$attachments[] = array(
							'attachment_id' => $attachment_id,
							'filename' => wp_basename( get_attached_file( $attachment_id ) ),
							'info' => $info,
						);
					}
				}
			}
			catch(Exception $e)
			{
				$messages .=
					Pdf_Forms_For_WPForms::render_error_notice( null, array(
						'label' => esc_html__( "Error", 'pdf-forms-for-wpforms' ),
						'message' => esc_html__( $e->getMessage(), 'pdf-forms-for-wpforms' ),
					) );
			}
			
			$preload_data = array(
				"attachments" => $attachments,
				"default_pdf_options" => self::DEFAULT_PDF_OPTIONS,
			);
			
			echo self::render( "spinner" ).
				self::render( 'settings', array(
				'title' => __( 'PDF Forms', 'pdf-forms-for-wpforms' ),
				'data-field' => wpforms_panel_field(
					'text',
					'pdf-forms-for-wpforms-form-settings',
					'data',
					$instance->form_data,
					"",
					array(
						'class' => 'pdf-form-data-box',
						'readonly' => 'readonly', // TODO: switch to input type="hidden" instead
					),
					false
				),
				'preload-data' => esc_html( self::json_encode( $preload_data ) ),
				'messages' => $messages,
				'instructions' => esc_html__( "You can use this section to attach a PDF file to your form, insert new form fields into your form, and link them to fields in the PDF file. You can also embed images (from URLs or attached files) into the PDF file. Changes here are applied when the form is saved.", 'pdf-forms-for-wpforms' ),
				'attach-pdf' => esc_html__( "Attach a PDF File", 'pdf-forms-for-wpforms' ),
				'insert-tags' => esc_html__( "Insert Tags", 'pdf-forms-for-wpforms' ),
				'insert-tag' => esc_html__( "Insert and Link", 'pdf-forms-for-wpforms' ),
				'generate-and-insert-all-tags-message' => esc_html__( "This button allows you to generate fields for all remaining unlinked PDF fields, insert them into the form and link them to their corresponding fields.", 'pdf-forms-for-wpforms' ),
				'insert-and-map-all-tags' => esc_html__( "Insert & Link All", 'pdf-forms-for-wpforms' ),
				'delete' => esc_html__( 'Delete', 'pdf-forms-for-wpforms' ),
				'map-value' => esc_html__( 'Map Value', 'pdf-forms-for-wpforms' ),
				'options' => esc_html__( 'Options', 'pdf-forms-for-wpforms' ),
				'skip-when-empty' => esc_html__( 'Skip when empty', 'pdf-forms-for-wpforms' ),
				'notifications' => esc_html__( 'Attach the filled PDF file to the following notifications:', 'pdf-forms-for-wpforms' ),
				'confirmations' => esc_html__( 'Add download link to filled PDF file to the following confirmations:', 'pdf-forms-for-wpforms' ),
				'flatten' => esc_html__( 'Flatten', 'pdf-forms-for-wpforms' ),
				'filename' => esc_html__( 'New filled PDF file name:', 'pdf-forms-for-wpforms' ),
				'save-directory'=> esc_html__( 'Path for saving filled PDF file:', 'pdf-forms-for-wpforms' ),
				'leave-blank-to-disable'=> esc_html__( '(leave blank to disable this option)', 'pdf-forms-for-wpforms' ),
				'field-mapping' => esc_html__( 'Field Mapper Tool', 'pdf-forms-for-wpforms' ),
				'field-mapping-help' => esc_html__( 'This tool can be used to link form fields and smart tags with fields in the attached PDF files. WPForms fields can also be generated from PDF fields. When your users submit the form, input from form fields and other smart tags will be inserted into the correspoinding fields in the PDF file. WPForms to PDF field value mappings can also be created to enable the replacement of WPForms data when PDF fields are filled.', 'pdf-forms-for-wpforms' ),
				'pdf-field' => esc_html__( 'PDF field', 'pdf-forms-for-wpforms' ),
				'wpf-field' => esc_html__( 'WPForms field / smart tags', 'pdf-forms-for-wpforms' ),
				'add-mapping' => esc_html__( 'Add Mapping', 'pdf-forms-for-wpforms' ),
				'delete-all-mappings' => esc_html__( 'Delete All', 'pdf-forms-for-wpforms' ),
				'new-field' => esc_html__( 'New Field:', 'pdf-forms-for-wpforms' ),
				'image-embedding' => esc_html__( 'Image Embedding Tool', 'pdf-forms-for-wpforms' ),
				'image-embedding-help'=> esc_html__( 'This tool allows embedding images into PDF files.  Images are taken from field attachments or field values that are URLs.  You must select a PDF file, its page and draw a bounding box for image insertion.', 'pdf-forms-for-wpforms' ),
				'add-wpf-field-embed' => esc_html__( 'Embed Image', 'pdf-forms-for-wpforms' ),
				'delete-wpf-field-embed' => esc_html__( 'Delete', 'pdf-forms-for-wpforms' ),
				'pdf-file' => esc_html__( 'PDF file', 'pdf-forms-for-wpforms' ),
				'page' => esc_html__( 'Page', 'pdf-forms-for-wpforms' ),
				'image-region-selection-hint' => esc_html__( 'Select a region where the image needs to be embeded.', 'pdf-forms-for-wpforms' ),
				'add-smart-tags' => esc_html__( 'Add smart-tags:', 'pdf-forms-for-wpforms' ),
				'show-smart-tags' => esc_html__( 'Show Smart Tags', 'pdf-forms-for-wpforms' ),
				'help-message' => self::replace_tags(
					esc_html__( "Have a question/comment/problem?  Feel free to use {a-href-forum}the support forum{/a}.", 'pdf-forms-for-wpforms' ),
					array(
						'a-href-forum' => '<a href="https://wordpress.org/support/plugin/pdf-forms-for-wpforms/" target="_blank">',
						'/a' => '</a>',
					)
				),
				'show-help' => esc_html__( 'Show Help', 'pdf-forms-for-wpforms' ),
				'hide-help' => esc_html__( 'Hide Help', 'pdf-forms-for-wpforms' ),
			) );
		}
		
		/**
		 * Adds necessary admin scripts and styles
		 */
		public function admin_enqueue_scripts( $hook )
		{
			wp_register_script( 'pdf_forms_for_wpforms_notices_script', plugin_dir_url( __FILE__ ) . 'js/notices.js', array( 'jquery' ), self::VERSION );
			wp_enqueue_script( 'pdf_forms_for_wpforms_notices_script' );
			
			if( ! class_exists('WPForms') || ! defined( 'WPFORMS_VERSION' ) )
				return;
			
			wp_register_script( 'pdf_forms_filler_spinner_script', plugin_dir_url(__FILE__) . 'js/spinner.js', array('jquery'), '1.0.0' );
			wp_register_style( 'pdf_forms_filler_spinner_style', plugin_dir_url( __FILE__ ) . 'css/spinner.css', array(), '1.0.0' );
			
			if( false !== strpos( $hook, 'wpforms' ) )
			{
				if( wpforms_is_admin_page( 'builder' ) )
				{
					wp_register_style( 'select2', plugin_dir_url( __FILE__ ) . 'css/select2.min.css', array(), '4.0.13' );
					wp_register_script( 'select2', plugin_dir_url(  __FILE__ ) . 'js/select2/select2.min.js', array( 'jquery' ), '4.0.13' );
					
					// TODO: improve registering/enqueuing of jcrop
					if( ! wp_style_is( 'jcrop', 'registered' ) )
						wp_register_style( 'jcrop', includes_url('js/jcrop/jquery.Jcrop.min.css'), array(), '0.9.12' );
					
					wp_register_script( 'pdf_forms_for_wpforms_admin_script', plugin_dir_url( __FILE__ ) . 'js/admin.js', array( 'jquery', 'jcrop', 'select2' ), self::VERSION );
					wp_register_style( 'pdf_forms_for_wpforms_admin_style', plugin_dir_url( __FILE__ ) . 'css/admin.css', array( 'jcrop', 'select2' ), self::VERSION );
					
					wp_localize_script( 'pdf_forms_for_wpforms_admin_script', 'pdf_forms_for_wpforms', array(
						'ajax_url' => admin_url( 'admin-ajax.php' ),
						'ajax_nonce' => wp_create_nonce( 'pdf-forms-for-wpforms-ajax-nonce' ),
						'__No_Form_ID' => __( "Failed to determine form ID", 'pdf-forms-for-wpforms' ),
						'__No_Preload_Data' => __( 'Failed to load PDF form data', 'pdf-forms-for-wpforms' ),
						'__Unknown_error' => __( 'Unknown error', 'pdf-forms-for-wpforms' ),
						'__Confirm_Delete_Attachment' => __( 'Are you sure you want to delete this file?  This will delete field mappings and image embeds associated with this file.', 'pdf-forms-for-wpforms' ),
						'__Confirm_Delete_Mapping' => __( 'Are you sure you want to delete this mapping?', 'pdf-forms-for-wpforms' ),
						'__Confirm_Delete_All_Mappings' => __( 'Are you sure you want to delete all mappings?', 'pdf-forms-for-wpforms' ),
						'__Confirm_Attach_Empty_Pdf' => __( 'Are you sure you want to attach a PDF file without any form fields?', 'pdf-forms-for-wpforms' ),
						'__Confirm_Delete_Embed' => __( 'Are you sure you want to delete this embeded image?', 'pdf-forms-for-wpforms' ),
						'__Show_Help' => __( 'Show Help', 'pdf-forms-for-wpforms' ),
						'__Hide_Help' => __( 'Hide Help', 'pdf-forms-for-wpforms' ),
						'__PDF_Frame_Title' => __( 'Select a PDF file', 'pdf-forms-for-wpforms'),
						'__PDF_Frame_Button' => __( 'Select', 'pdf-forms-for-wpforms'),
						'__Custom_String' => __( "Custom text string...", 'pdf-forms-for-wpforms' ),
						'__All_PDFs' => __( 'All PDFs', 'pdf-forms-for-wpforms' ),
						'__All_Pages' => __( 'All', 'pdf-forms-for-wpforms' ),
						'__PDF_Field_Type_Unsupported' => __( 'PDF field type has no equivalent in WPForms', 'pdf-forms-for-wpforms' ),
						'__Default_Notification' => __( 'Default Notification', 'pdf-forms-for-wpforms' ),
						'__Default_Confirmation' => __( 'Default Confirmation', 'pdf-forms-for-wpforms' ),
						'__Null_Value_Mapping' => __( '--- EMPTY ---', 'pdf-forms-for-wpforms' ),
					) );
					
					wp_enqueue_media();
					
					wp_enqueue_script( 'pdf_forms_for_wpforms_admin_script' );
					wp_enqueue_style( 'pdf_forms_for_wpforms_admin_style' );
					
					wp_enqueue_script( 'pdf_forms_filler_spinner_script' );
					wp_enqueue_style( 'pdf_forms_filler_spinner_style' );
				}
			}
			
			if( $service = $this->get_service() )
			{
				$service->admin_enqueue_scripts( $hook );
				if( $service != $this->pdf_ninja_service )
					$this->pdf_ninja_service->admin_enqueue_scripts( $hook );
			}
		}
		
		/**
		 * Adds a Pdf Forms to list of form settings
		 */
		public function add_settings_panel( $panels )
		{
			$panels['pdf-forms'] = __( 'PDF Forms', 'pdf-forms-for-wpforms' );
			return $panels;
		}
		
		/**
		 * Used for retreiving PDF file information in WPForms Builder interface
		 */
		public function wp_ajax_get_attachment_data()
		{
			try
			{
				if( ! check_ajax_referer( 'pdf-forms-for-wpforms-ajax-nonce', 'nonce', false ) )
					throw new Exception( __( "Nonce mismatch", 'pdf-forms-for-wpforms' ) );
				
				$attachment_id = isset( $_POST['file_id'] ) ? intval( $_POST['file_id'] ) : null;
				
				if( ! $attachment_id )
					throw new Exception( __( "Invalid attachment ID", 'pdf-forms-for-wpforms' ) );
				
				if( ! current_user_can( 'edit_post', $attachment_id ) )
					throw new Exception( __( "Permission denied", 'pdf-forms-for-wpforms' ) );
				
				if( ( $filepath = get_attached_file( $attachment_id ) ) !== false
				&& ( $mimetype = self::get_mime_type( $filepath ) ) != null
				&& $mimetype !== 'application/pdf' )
					throw new Exception(
						self::replace_tags(
							__( "File type {mime-type} of {file} is unsupported for {purpose}", 'pdf-forms-for-wpforms' ),
							array( 'mime-type' => $mimetype, 'file' => wp_basename( $filepath ), 'purpose' => __("PDF form filling", 'pdf-forms-for-wpforms') )
						)
					);
				
				$info = $this->get_info( $attachment_id );
				$info['fields'] = $this->query_pdf_fields( $attachment_id );
				
				return wp_send_json( array(
					'success' => true,
					'attachment_id' => $attachment_id,
					'filename' => wp_basename( $filepath ),
					'info' => $info,
				) );
			}
			catch( Exception $e )
			{
				return wp_send_json( array(
					'success' => false,
					'error_message' => $e->getMessage(),
					'error_location' => wp_basename( $e->getFile() ) . ":" . $e->getLine(),
				) );
			}
		}
		
		/**
		 * Returns (and computes, if necessary) the md5 sum of the media file
		 */
		public static function get_attachment_md5sum( $attachment_id )
		{
			$md5sum = self::get_meta( $attachment_id, 'md5sum' );
			if( ! $md5sum )
				return self::update_attachment_md5sum( $attachment_id );
			else
				return $md5sum;
		}
		
		/**
		 * Computes, saves and returns the md5 sum of the media file
		 */
		public static function update_attachment_md5sum( $attachment_id )
		{
			// clear info cache
			self::unset_meta( $attachment_id, 'info' );
			
			// delete page snapshots
			$args = array(
				'post_parent' => $attachment_id,
				'meta_key' => 'pdf-forms-for-wpforms-page',
				'post_type' => 'attachment',
				'post_status' => 'any',
				'posts_per_page' => -1,
			);
			foreach( get_posts( $args ) as $post )
				wp_delete_post( $post->ID, $force_delete = true );
			
			$filepath = get_attached_file( $attachment_id );
			
			if( $filepath !== false && is_readable( $filepath ) !== false )
				$md5sum = @md5_file( $filepath );
			else
			{
				$fileurl = wp_get_attachment_url( $attachment_id );
				if( $fileurl === false )
					throw new Exception( __( "Attachment file is not accessible", 'pdf-forms-for-wpforms' ) );
				
				try
				{
					$temp_filepath = wp_tempnam();
					self::download_file( $fileurl, $temp_filepath ); // can throw an exception
					$md5sum = @md5_file( $temp_filepath );
					@unlink( $temp_filepath );
				}
				catch(Exception $e)
				{
					@unlink( $temp_filepath );
					throw $e;
				}
			}
			
			if( $md5sum === false )
				throw new Exception( __( "Could not read attached file", 'pdf-forms-for-wpforms' ) );
			
			return self::set_meta( $attachment_id, 'md5sum', $md5sum );
		}
		
		/**
		 * Caching wrapper for $service->api_get_info()
		 */
		public function get_info( $attachment_id )
		{
			// cache
			if( ( $info = self::get_meta( $attachment_id, 'info' ) )
			&& ( $old_md5sum = self::get_meta( $attachment_id, 'md5sum' ) ) )
			{
				// use cache only if file is locally accessible
				$filepath = get_attached_file( $attachment_id );
				if( $filepath !== false && is_readable( $filepath ) !== false )
				{
					$new_md5sum = md5_file( $filepath );
					if( $new_md5sum !== false && $new_md5sum === $old_md5sum )
						return json_decode( $info, true );
					else
						self::update_attachment_md5sum( $attachment_id );
				}
			}
			
			$service = $this->get_service();
			if( !$service )
				throw new Exception( __( "No service", 'pdf-forms-for-wpfoms' ) );
			
			$info = $service->api_get_info( $attachment_id );
			
			// set up array keys so it is easier to search
			$fields = array();
			foreach( $info['fields'] as $field )
				$fields[$field['name']] = $field;
			$info['fields'] = $fields;
			
			$pages = array();
			foreach( $info['pages'] as $page )
				$pages[$page['number']] = $page;
			$info['page'] = $pages;
			
			// set fields cache
			self::set_meta( $attachment_id, 'info', self::json_encode( $info ) );
			
			return $info;
		}
		
		/**
		 * Caches and returns fields for an attachment
		 */
		public function get_fields( $attachment_id )
		{
			$info = $this->get_info( $attachment_id );
			return $info['fields'];
		}
		
		/**
		 * PHP version specific wrapper for json_encode function
		 */
		public static function json_encode( $value )
		{
			return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		
		/**
		 * Multibyte trim
		 */
		public static function mb_trim( $str )
		{
			return preg_replace( '/(^\s+)|(\s+$)/us', '', $str );
		}
		
		private static function escape_tag_value( $value )
		{
			$value = esc_attr( $value );
			$escape_characters = array( "\\","]","|" );
			$escape_table = array( '&#92;', '&#93;','&#124;' );
			$value = str_replace( $escape_characters, $escape_table, $value );
			return $value;
		}
		
		/**
		 * Helper function used in wp-admin interface
		 */
		private function query_pdf_fields( $attachment_id )
		{
			$fields = $this->get_fields( $attachment_id );
			
			foreach( $fields as $id => &$field )
			{
				if( !isset( $field['name'] ) )
				{
					unset( $fields[$id] );
					continue;
				}
				
				$name = strval( $field['name'] );
				$field['id'] = self::base64url_encode( $name );
			}
			
			return $fields;
		}
		
		/**
		 * Downloads and caches PDF page images, returns image attachment id
		 */
		public function get_pdf_snapshot( $attachment_id, $page )
		{
			$args = array(
				'post_parent' => $attachment_id,
				'meta_key' => 'pdf-forms-for-wpforms-page',
				'meta_value' => $page,
				'post_type' => 'attachment',
				'post_status' => 'any',
				'posts_per_page' => 1,
			);
			$posts = get_posts( $args );
			
			if( count( $posts ) > 0 )
			{
				$old_attachment_id = reset( $posts )->ID;
				return $old_attachment_id;
			}
			
			if( ! ( ( $wp_upload_dir = wp_upload_dir() ) && false === $wp_upload_dir['error'] ) )
				throw new Exception( $wp_upload_dir['error'] );
			
			$attachment_path = get_attached_file( $attachment_id );
			
			if( $attachment_path === false )
				$attachment_path = wp_get_attachment_url( $attachment_id );
			if( $attachment_path === false )
				$attachment_path = "unknown";
			
			$filename = wp_unique_filename( $wp_upload_dir['path'], wp_basename( $attachment_path ).'.page'.intval($page).'.jpg' );
			$filepath = $wp_upload_dir['path'] . "/$filename";
			
			$service = $this->get_service();
			if( $service )
				$service->api_image( $filepath, $attachment_id, $page );
			
			$mimetype = self::get_mime_type( $filename );
			
			$attachment = array(
				'guid'           => $wp_upload_dir['url'] . '/' . $filename,
				'post_mime_type' => $mimetype,
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit'
			);
			
			$new_attachment_id = wp_insert_attachment( $attachment, $filepath, $attachment_id );
			
			self::set_meta( $new_attachment_id, 'page', $page );
			
			return $new_attachment_id;
		}
		
		/**
		 * Used for getting PDF page images in wp-admin interface
		 */
		public function wp_ajax_query_page_image()
		{
			try
			{
				if ( ! check_ajax_referer( 'pdf-forms-for-wpforms-ajax-nonce', 'nonce', false ) )
					throw new Exception( __( "Nonce mismatch", 'pdf-forms-for-wpforms' ) );
				
				$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : null;
				$page = isset( $_POST['page'] ) ? (int) $_POST['page'] : null;
				
				if ( $page < 1 )
					throw new Exception( __( "Invalid page number", 'pdf-forms-for-wpforms' ) );
				
				if( ! wpforms_current_user_can( 'edit_post', $attachment_id ) )
					throw new Exception( __( "Permission denied", 'pdf-forms-for-wpforms' ) );
				
				$attachment_id = $this->get_pdf_snapshot( $attachment_id, $page );
				$snapshot = wp_get_attachment_image_src( $attachment_id, array( 500, 500 ) );
				
				if( !$snapshot || !is_array( $snapshot ) )
					throw new Exception( __( "Failed to retrieve page snapshot", 'pdf-forms-for-wpforms' ) );
				
				return wp_send_json( array(
					'success' => true,
					'snapshot' => reset( $snapshot ),
				) );
			}
			catch( Exception $e )
			{
				return wp_send_json( array(
					'success'  => false,
					'error_message' => $e->getMessage(),
					'error_location' => wp_basename( $e->getFile() ) . ":". $e->getLine(),
				) );
			}
		}
		
		/**
		 * get WPForms form data by post_id
		 */
		public static function get_wpf_form_data( $post_id )
		{
			if( !$post_id || is_null( $form = wpforms()->form->get( $post_id ) ))
				return null;
			
			return wpforms_decode( $form->post_content );
		}
		
		/**
		 * Helper functions for encoding/decoding field names
		 */
		public static function base64url_encode( $data )
		{
			return rtrim( strtr( base64_encode( $data ), '+/', '._' ), '=' );
		}
		public static function base64url_decode( $data )
		{
			return base64_decode( str_pad( strtr( $data, '._', '+/' ), strlen( $data ) % 4, '=', STR_PAD_RIGHT ) );
		}
		
		/*
		 * Parses data URI
		 */
		public static function parse_data_uri( $uri )
		{
			if( ! preg_match( '/data:([a-zA-Z-\/+.]*)((;[a-zA-Z0-9-_=.+]+)*),(.*)/', $uri, $matches ) )
				return false;
			
			$data = $matches[4];
			$mime = $matches[1] ? $matches[1] : null;
			
			$base64 = false;
			foreach( explode( ';', $matches[2] ) as $ext )
				if( $ext == "base64" )
				{
					$base64 = true; 
					if( ! ( $data = base64_decode( $data, $strict=true ) ) )
						return false;
				}
			
			if( ! $base64 )
				$data = rawurldecode( $data );
			
			return array(
				'data' => $data,
				'mime' => $mime,
			);
		}
	}
	
	Pdf_Forms_For_WPForms::get_instance();
}
