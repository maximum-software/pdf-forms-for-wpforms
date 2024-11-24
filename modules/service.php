<?php
	
	if( ! defined( 'ABSPATH' ) )
		return;
	
	abstract class Pdf_Forms_For_WPForms_Service
	{
		public function api_get_fields( $attachment_id )
		{
			throw new Exception( __( "Missing feature", 'pdf-forms-for-wpforms' ) );
		}
		
		public function api_get_info( $attachment_id )
		{
			throw new Exception( __( "Missing feature", 'pdf-forms-for-wpforms' ) );
		}
		
		public function api_image( $destfile, $attachment_id, $page )
		{
			throw new Exception( __( "Missing feature", 'pdf-forms-for-wpforms' ) );
		}
		
		public function api_fill( $destfile, $attachment_id, $data, $options = array() )
		{
			throw new Exception( __( "Missing feature", 'pdf-forms-for-wpforms' ) );
		}
		
		public function api_fill_embed( $destfile, $attachment_id, $data, $embeds, $options = array() )
		{
			throw new Exception( __( "Missing feature", 'pdf-forms-for-wpforms' ) );
		}
		
		public function admin_notices() { }
		
		public function form_notices() { }
		
		public function plugin_init() {}
		
		public function admin_enqueue_scripts( $hook ) {}
	}
