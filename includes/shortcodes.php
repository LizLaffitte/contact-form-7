<?php

class WPCF7_ShortcodeManager {

	var $shortcode_tags = array();

	// Taggs scanned at the last time of do_shortcode()
	var $scanned_tags = null;

	// Executing shortcodes (true) or just scanning (false)
	var $exec = true;

	function add_shortcode( $tag, $func, $has_name = false ) {
		if ( is_callable( $func ) )
			$this->shortcode_tags[$tag] = array(
				'function' => $func,
				'has_name' => (boolean) $has_name );
	}

	function remove_shortcode( $tag ) {
		unset( $this->shortcode_tags[$tag] );
	}

	function normalize_shortcode( $content ) {
		if ( empty( $this->shortcode_tags ) || ! is_array( $this->shortcode_tags ) )
			return $content;

		$pattern = $this->get_shortcode_regex();
		return preg_replace_callback( '/' . $pattern . '/s',
			array( &$this, 'normalize_space_cb' ), $content );
	}

	function normalize_space_cb( $m ) {
		// allow [[foo]] syntax for escaping a tag
		if ( $m[1] == '[' && $m[6] == ']' )
			return $m[0];

		$tag = $m[2];
		$attr = trim( preg_replace( '/[\r\n\t ]+/', ' ', $m[3] ) );
		$content = trim( $m[5] );

		$content = str_replace( "\n", '<WPPreserveNewline />', $content );

		$result = $m[1] . '[' . $tag
			. ( $attr ? ' ' . $attr : '' )
			. ( $m[4] ? ' ' . $m[4] : '' )
			. ']'
			. ( $content ? $content . '[/' . $tag . ']' : '' )
			. $m[6];

		return $result;
	}

	function do_shortcode( $content, $exec = true ) {
		$this->exec = (bool) $exec;
		$this->scanned_tags = array();

		if ( empty( $this->shortcode_tags ) || ! is_array( $this->shortcode_tags ) )
			return $content;

		$pattern = $this->get_shortcode_regex();
		return preg_replace_callback( '/' . $pattern . '/s',
			array( &$this, 'do_shortcode_tag' ), $content );
	}

	function scan_shortcode( $content ) {
		$this->do_shortcode( $content, false );
		return $this->scanned_tags;
	}

	function get_shortcode_regex() {
		$tagnames = array_keys( $this->shortcode_tags );
		$tagregexp = join( '|', array_map( 'preg_quote', $tagnames ) );

		return '(\[?)'
			. '\[(' . $tagregexp . ')(?:[\r\n\t ](.*?))?(?:[\r\n\t ](\/))?\]'
			. '(?:([^[]*?)\[\/\2\])?'
			. '(\]?)';
	}

	function do_shortcode_tag( $m ) {
		// allow [[foo]] syntax for escaping a tag
		if ( $m[1] == '[' && $m[6] == ']' ) {
			return substr( $m[0], 1, -1 );
		}

		$tag = $m[2];
		$attr = $this->shortcode_parse_atts( $m[3] );

		$scanned_tag = array(
			'type' => $tag,
			'basetype' => trim( $tag, '*' ),
			'name' => '',
			'options' => array(),
			'raw_values' => array(),
			'values' => array(),
			'pipes' => null,
			'labels' => array(),
			'attr' => '',
			'content' => '' );

		if ( is_array( $attr ) ) {
			if ( is_array( $attr['options'] ) ) {
				if ( $this->shortcode_tags[$tag]['has_name'] && ! empty( $attr['options'] ) ) {
					$scanned_tag['name'] = array_shift( $attr['options'] );

					if ( ! wpcf7_is_name( $scanned_tag['name'] ) )
						return $m[0]; // Invalid name is used. Ignore this tag.
				}

				$scanned_tag['options'] = (array) $attr['options'];
			}

			$scanned_tag['raw_values'] = (array) $attr['values'];

			if ( WPCF7_USE_PIPE ) {
				$pipes = new WPCF7_Pipes( $scanned_tag['raw_values'] );
				$scanned_tag['values'] = $pipes->collect_befores();
				$scanned_tag['pipes'] = $pipes;
			} else {
				$scanned_tag['values'] = $scanned_tag['raw_values'];
			}

			$scanned_tag['labels'] = $scanned_tag['values'];

		} else {
			$scanned_tag['attr'] = $attr;
		}

		$content = trim( $m[5] );
		$content = preg_replace( "/<br[\r\n\t ]*\/?>$/m", '', $content );
		$scanned_tag['content'] = $content;

		$scanned_tag = apply_filters( 'wpcf7_form_tag', $scanned_tag, $this->exec );

		$this->scanned_tags[] = $scanned_tag;

		if ( $this->exec ) {
			$func = $this->shortcode_tags[$tag]['function'];
			return $m[1] . call_user_func( $func, $scanned_tag ) . $m[6];
		} else {
			return $m[0];
		}
	}

	function shortcode_parse_atts( $text ) {
		$atts = array( 'options' => array(), 'values' => array() );
		$text = preg_replace( "/[\x{00a0}\x{200b}]+/u", " ", $text );
		$text = stripcslashes( trim( $text ) );

		$pattern = '%^([-+*=0-9a-zA-Z:.!?#$&@_/|\%\r\n\t ]*?)((?:[\r\n\t ]*"[^"]*"|[\r\n\t ]*\'[^\']*\')*)$%';

		if ( preg_match( $pattern, $text, $match ) ) {
			if ( ! empty( $match[1] ) ) {
				$atts['options'] = preg_split( '/[\r\n\t ]+/', trim( $match[1] ) );
			}
			if ( ! empty( $match[2] ) ) {
				preg_match_all( '/"[^"]*"|\'[^\']*\'/', $match[2], $matched_values );
				$atts['values'] = wpcf7_strip_quote_deep( $matched_values[0] );
			}
		} else {
			$atts = $text;
		}

		return $atts;
	}

}

function wpcf7_add_shortcode( $tag, $func, $has_name = false ) {
	global $wpcf7_shortcode_manager;

	if ( is_a( $wpcf7_shortcode_manager, 'WPCF7_ShortcodeManager' ) )
		return $wpcf7_shortcode_manager->add_shortcode( $tag, $func, $has_name );
}

function wpcf7_remove_shortcode( $tag ) {
	global $wpcf7_shortcode_manager;

	if ( is_a( $wpcf7_shortcode_manager, 'WPCF7_ShortcodeManager' ) )
		return $wpcf7_shortcode_manager->remove_shortcode( $tag );
}

function wpcf7_do_shortcode( $content ) {
	global $wpcf7_shortcode_manager;

	if ( is_a( $wpcf7_shortcode_manager, 'WPCF7_ShortcodeManager' ) )
		return $wpcf7_shortcode_manager->do_shortcode( $content );
}

function wpcf7_get_shortcode_regex() {
	global $wpcf7_shortcode_manager;

	if ( is_a( $wpcf7_shortcode_manager, 'WPCF7_ShortcodeManager' ) )
		return $wpcf7_shortcode_manager->get_shortcode_regex();
}

class WPCF7_Shortcode {

	public $type;
	public $basetype;
	public $name = '';
	public $options = array();
	public $raw_values = array();
	public $values = array();
	public $pipes;
	public $labels = array();
	public $attr = '';
	public $content = '';

	public function __construct( $tag ) {
		foreach ( $tag as $key => $value ) {
			if ( property_exists( __CLASS__, $key ) )
				$this->{$key} = $value;
		}
	}

	public function has_option( $opt ) {
		$pattern = sprintf( '/^%s(:.+)?$/', preg_quote( $opt, '/' ) );
		return (bool) preg_grep( $pattern, $this->options );
	}

	public function make_common_atts( $atts = '' ) {
		$defaults = array(
			'id' => '',
			'class' => '',
			'tabindex' => '' );

		$atts = wp_parse_args( $atts, $defaults );

		// id
		$matches = $this->get_first_match_option( '%^id:([-0-9a-zA-Z_]+)$%' );

		if ( $matches )
			$atts['id'] = $matches[1];

		// class
		$matches_a = $this->get_all_match_options( '%^class:([-0-9a-zA-Z_]+)$%' );

		if ( $matches_a ) {
			foreach ( $matches_a as $matches )
				$atts['class'] .= ' ' . $matches[1];

			$atts['class'] = trim( $atts['class'] );
		}

		// tabindex
		$matches = $this->get_first_match_option( '%^tabindex:(\d+)$%' );

		if ( $matches )
			$atts['tabindex'] = $matches[1];

		// size & maxlength
		if ( isset( $atts['size'] ) ) {
			$matches_a = $this->get_all_match_options(
				'%^([0-9]*)/([0-9]*)$%' );

			if ( $matches_a ) {
				$size = $maxlength = '';

				foreach ( $matches_a as $matches ) {
					if ( isset( $matches[1] ) && '' !== $matches[1]
					&& '' === $size )
						$size = $matches[1];

					if ( isset( $matches[2] ) && '' !== $matches[2]
					&& '' === $maxlength )
						$maxlength = $matches[2];
				}

				if ( '' !== $size )
					$atts['size'] = $size;

				if ( '' !== $maxlength )
					$atts['maxlength'] = $maxlength;
			}
		}

		// cols, rows & maxlength
		if ( isset( $atts['cols'] ) || isset( $atts['rows'] ) ) {
			$matches_a = $this->get_all_match_options(
				'%^(?:([0-9]*)x([0-9]*))?(?:/([0-9]+))?$%' );

			if ( $matches_a ) {
				$cols = $rows = $maxlength = '';

				foreach ( $matches_a as $matches ) {
					if ( isset( $matches[1] ) && '' !== $matches[1]
					&& '' === $cols )
						$cols = $matches[1];

					if ( isset( $matches[2] ) && '' !== $matches[2]
					&& '' === $rows )
						$rows = $matches[2];

					if ( isset( $matches[3] ) && '' !== $matches[3]
					&& '' === $maxlength )
						$maxlength = $matches[3];
				}

				if ( '' !== $cols )
					$atts['cols'] = $cols;

				if ( '' !== $rows )
					$atts['rows'] = $rows;

				if ( '' !== $maxlength )
					$atts['maxlength'] = $maxlength;
			}
		}

		return $atts;
	}

	public function get_first_match_option( $pattern ) {
		foreach( (array) $this->options as $option ) {
			if ( preg_match( $pattern, $option, $matches ) )
				return $matches;
		}

		return false;
	}

	public function get_all_match_options( $pattern ) {
		$result = array();

		foreach( (array) $this->options as $option ) {
			if ( preg_match( $pattern, $option, $matches ) )
				$result[] = $matches;
		}

		return $result;
	}
}

?>