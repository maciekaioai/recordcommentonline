<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function vn_register_settings() {
	register_setting( 'voice_notes_settings', VN_OPTION_KEY, 'vn_sanitize_settings' );

	add_settings_section(
		'vn_settings_main',
		__( 'Voice Notes Settings', 'voice-notes' ),
		'vn_settings_section_intro',
		'voice_notes_settings'
	);

	$fields = array(
		'recipient_email'  => __( 'Recipient email', 'voice-notes' ),
		'phone_number'     => __( 'Phone number', 'voice-notes' ),
		'min_seconds'      => __( 'Min seconds', 'voice-notes' ),
		'preferred_max'    => __( 'Preferred max seconds', 'voice-notes' ),
		'hard_max'         => __( 'Hard max seconds', 'voice-notes' ),
		'max_file_size_mb' => __( 'Max file size (MB)', 'voice-notes' ),
		'attach_up_to_mb'  => __( 'Attach files up to (MB)', 'voice-notes' ),
		'rate_limit_per_hour' => __( 'Rate limit per hour', 'voice-notes' ),
		'consent_text'     => __( 'Consent text', 'voice-notes' ),
		'button_label'     => __( 'Button label for opener', 'voice-notes' ),
		'success_title'    => __( 'Success title', 'voice-notes' ),
		'success_message'  => __( 'Success message', 'voice-notes' ),
		'from_name'        => __( 'From name', 'voice-notes' ),
		'from_email'       => __( 'From email', 'voice-notes' ),
	);

	foreach ( $fields as $field => $label ) {
		add_settings_field(
			$field,
			$label,
			'vn_render_field',
			'voice_notes_settings',
			'vn_settings_main',
			array(
				'key'   => $field,
				'label' => $label,
			)
		);
	}
}
add_action( 'admin_init', 'vn_register_settings' );

function vn_settings_section_intro() {
	echo '<p>' . esc_html__( 'Configure Voice Notes recorder settings.', 'voice-notes' ) . '</p>';
}

function vn_render_field( $args ) {
	$settings = vn_get_settings();
	$key      = $args['key'];
	$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
	$type     = in_array( $key, array( 'consent_text', 'success_message' ), true ) ? 'textarea' : 'text';
	if ( 'textarea' === $type ) {
		echo '<textarea name="' . esc_attr( VN_OPTION_KEY ) . '[' . esc_attr( $key ) . ']" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
		return;
	}
	echo '<input type="text" name="' . esc_attr( VN_OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" />';
}

function vn_sanitize_settings( $input ) {
	$defaults = vn_default_settings();
	$output   = array();
	foreach ( $defaults as $key => $default ) {
		$value = isset( $input[ $key ] ) ? $input[ $key ] : $default;
		switch ( $key ) {
			case 'recipient_email':
			case 'from_email':
				$output[ $key ] = sanitize_email( $value );
				break;
			case 'min_seconds':
			case 'preferred_max':
			case 'hard_max':
			case 'max_file_size_mb':
			case 'attach_up_to_mb':
			case 'rate_limit_per_hour':
				$output[ $key ] = max( 0, (int) $value );
				break;
			case 'consent_text':
			case 'success_title':
			case 'success_message':
			case 'button_label':
			case 'phone_number':
			case 'from_name':
				$output[ $key ] = sanitize_text_field( $value );
				break;
			default:
				$output[ $key ] = sanitize_text_field( $value );
		}
	}
	return $output;
}

function vn_register_settings_page() {
	add_options_page(
		__( 'Voice Notes', 'voice-notes' ),
		__( 'Voice Notes', 'voice-notes' ),
		'manage_options',
		'voice-notes-settings',
		'vn_render_settings_page'
	);
}
add_action( 'admin_menu', 'vn_register_settings_page' );

function vn_render_settings_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Voice Notes Settings', 'voice-notes' ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'voice_notes_settings' );
			do_settings_sections( 'voice_notes_settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

