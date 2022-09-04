<?php
	
	class Pdf_Forms_For_WPForms_Wrapper
	{
		public static function mb_strpos( $haystack, $needle, $offset = 0 )
		{
			return function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $needle, $offset ) : strpos( $haystack, $needle, $offset );
		}
		
		public static function mb_strlen( $string )
		{
			return function_exists( 'mb_strlen' ) ? mb_strlen( $string ) : strlen( $string );
		}
		
		public static function mb_substr( $string, $start, $length = null )
		{
			return function_exists( 'mb_substr' ) ? mb_substr( $string, $start, $length ) : substr( $string, $start, $length );
		}
		
		public static function mb_strtolower($str)
		{
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $str ) : strtolower( $str );
		}
	}
