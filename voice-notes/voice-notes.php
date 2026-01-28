<?php
/**
 * Plugin Name: Voice Notes Recorder
 * Description: Record and submit voice notes from the browser with email notification.
 * Version: 1.0.0
 * Author: OpenAI
 * Text Domain: voice-notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VN_VERSION = '1.0.0';
const VN_OPTION_KEY = 'voice_notes_settings';
const VN_CPT = 'voice_note';

require_once __DIR__ . '/includes/admin-settings.php';

function vn_default_settings() {
	return array(
		'recipient_email'     => 'praca@wkm-global.com',
		'phone_number'        => '+1 646 760 4011',
		'min_seconds'         => 30,
		'preferred_max'       => 60,
		'hard_max'            => 90,
		'max_file_size_mb'    => 15,
		'attach_up_to_mb'     => 10,
		'rate_limit_per_hour' => 3,
		'consent_text'        => __( 'I agree this recording may be edited for clarity and used in Editor Takes.', 'voice-notes' ),
		'button_label'        => __( 'Leave a voice note', 'voice-notes' ),
		'success_title'       => __( "Thanks, we've got your take", 'voice-notes' ),
		'success_message'     => __( 'We review all submissions and may feature them in a future episode.', 'voice-notes' ),
		'from_name'           => '',
		'from_email'          => '',
	);
}

function vn_get_settings() {
	$defaults = vn_default_settings();
	$saved    = get_option( VN_OPTION_KEY, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, $defaults );
}

function vn_register_post_type() {
	$labels = array(
		'name'               => __( 'Voice Notes', 'voice-notes' ),
		'singular_name'      => __( 'Voice Note', 'voice-notes' ),
		'add_new_item'       => __( 'Add Voice Note', 'voice-notes' ),
		'edit_item'          => __( 'Edit Voice Note', 'voice-notes' ),
		'new_item'           => __( 'New Voice Note', 'voice-notes' ),
		'view_item'          => __( 'View Voice Note', 'voice-notes' ),
		'not_found'          => __( 'No voice notes found', 'voice-notes' ),
		'not_found_in_trash' => __( 'No voice notes found in Trash', 'voice-notes' ),
	);

	$capabilities = array(
		'edit_post'          => 'edit_voice_note',
		'read_post'          => 'read_voice_note',
		'delete_post'        => 'delete_voice_note',
		'edit_posts'         => 'edit_voice_notes',
		'edit_others_posts'  => 'edit_others_voice_notes',
		'publish_posts'      => 'publish_voice_notes',
		'read_private_posts' => 'read_private_voice_notes',
		'delete_posts'       => 'delete_voice_notes',
		'delete_private_posts' => 'delete_private_voice_notes',
		'delete_published_posts' => 'delete_published_voice_notes',
		'delete_others_posts' => 'delete_others_voice_notes',
		'edit_private_posts' => 'edit_private_voice_notes',
		'edit_published_posts' => 'edit_published_voice_notes',
	);

	register_post_type(
		VN_CPT,
		array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'supports'            => array( 'title' ),
			'capability_type'     => array( 'voice_note', 'voice_notes' ),
			'capabilities'        => $capabilities,
			'map_meta_cap'        => true,
			'menu_icon'           => 'dashicons-microphone',
			'menu_position'       => 20,
			'has_archive'         => false,
			'show_in_rest'        => false,
		)
	);
}
add_action( 'init', 'vn_register_post_type' );

function vn_add_caps() {
	$roles = array( 'administrator', 'editor' );
	$caps  = array(
		'edit_voice_note',
		'read_voice_note',
		'delete_voice_note',
		'edit_voice_notes',
		'edit_others_voice_notes',
		'publish_voice_notes',
		'read_private_voice_notes',
		'delete_voice_notes',
		'delete_private_voice_notes',
		'delete_published_voice_notes',
		'delete_others_voice_notes',
		'edit_private_voice_notes',
		'edit_published_voice_notes',
	);
	foreach ( $roles as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap ) {
			$role->add_cap( $cap );
		}
	}
}
register_activation_hook( __FILE__, 'vn_add_caps' );

function vn_upload_mimes( $mimes ) {
	$mimes['webm'] = 'audio/webm';
	$mimes['ogg']  = 'audio/ogg';
	$mimes['m4a']  = 'audio/mp4';
	return $mimes;
}
add_filter( 'upload_mimes', 'vn_upload_mimes' );

function vn_register_rest_routes() {
	register_rest_route(
		'voice-notes/v1',
		'/submit',
		array(
			'methods'             => 'POST',
			'callback'            => 'vn_handle_submission',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'vn_register_rest_routes' );

function vn_get_client_ip() {
	$ip = '';
	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}
	return $ip;
}

function vn_check_rate_limit( $limit ) {
	$ip = vn_get_client_ip();
	if ( empty( $ip ) ) {
		return true;
	}
	$hash     = sha1( $ip );
	$key      = 'vn_rate_' . $hash;
	$current  = (int) get_transient( $key );
	if ( $current >= $limit ) {
		return false;
	}
	set_transient( $key, $current + 1, HOUR_IN_SECONDS );
	return true;
}

function vn_get_upload_dir( $subdir ) {
	$upload_dir = wp_upload_dir();
	$path       = trailingslashit( $upload_dir['basedir'] ) . 'voice-notes' . $subdir;
	$url        = trailingslashit( $upload_dir['baseurl'] ) . 'voice-notes' . $subdir;
	return array(
		'path' => $path,
		'url'  => $url,
	);
}

function vn_custom_upload_dir( $dirs ) {
	if ( empty( $GLOBALS['vn_upload_dir'] ) ) {
		return $dirs;
	}
	$upload = $GLOBALS['vn_upload_dir'];
	$dirs['subdir'] = $upload['subdir'];
	$dirs['path']   = $upload['path'];
	$dirs['url']    = $upload['url'];
	return $dirs;
}

function vn_handle_submission( WP_REST_Request $request ) {
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error( 'vn_invalid_nonce', __( 'Security check failed.', 'voice-notes' ), array( 'status' => 403 ) );
	}

	$settings = vn_get_settings();

	$consent = (bool) $request->get_param( 'consent' );
	if ( ! $consent ) {
		return new WP_Error( 'vn_no_consent', __( 'Consent is required.', 'voice-notes' ), array( 'status' => 400 ) );
	}

	$honeypot = $request->get_param( 'website' );
	if ( ! empty( $honeypot ) ) {
		return new WP_Error( 'vn_spam', __( 'Submission rejected.', 'voice-notes' ), array( 'status' => 400 ) );
	}

	$min_delay = 8;
	$opened_at = (int) $request->get_param( 'opened_at' );
	if ( $opened_at > 0 && ( time() - $opened_at ) < $min_delay ) {
		return new WP_Error( 'vn_too_fast', __( 'Please take a moment before submitting.', 'voice-notes' ), array( 'status' => 400 ) );
	}

	$limit = (int) $settings['rate_limit_per_hour'];
	if ( $limit > 0 && ! vn_check_rate_limit( $limit ) ) {
		return new WP_Error( 'vn_rate_limited', __( 'Too many submissions. Please try again later.', 'voice-notes' ), array( 'status' => 429 ) );
	}

	if ( empty( $_FILES['audio'] ) || empty( $_FILES['audio']['name'] ) ) {
		return new WP_Error( 'vn_no_file', __( 'No audio file uploaded.', 'voice-notes' ), array( 'status' => 400 ) );
	}

	$file        = $_FILES['audio'];
	$max_size_mb = (int) $settings['max_file_size_mb'];
	$max_size    = $max_size_mb > 0 ? $max_size_mb * 1024 * 1024 : 0;
	if ( $max_size > 0 && (int) $file['size'] > $max_size ) {
		return new WP_Error( 'vn_file_too_large', __( 'The audio file is too large.', 'voice-notes' ), array( 'status' => 400 ) );
	}

	$duration = (int) $request->get_param( 'duration' );
	$min      = (int) $settings['min_seconds'];
	$hard_max = (int) $settings['hard_max'];
	if ( $duration < $min ) {
		return new WP_Error( 'vn_too_short', __( 'Recording is too short.', 'voice-notes' ), array( 'status' => 400 ) );
	}
	if ( $hard_max > 0 && $duration > $hard_max ) {
		return new WP_Error( 'vn_too_long', __( 'Recording is too long.', 'voice-notes' ), array( 'status' => 400 ) );
	}

	$name    = sanitize_text_field( $request->get_param( 'name' ) );
	$company = sanitize_text_field( $request->get_param( 'company' ) );
	$page    = esc_url_raw( $request->get_param( 'page_url' ) );
	$recipient = sanitize_email( $request->get_param( 'recipient_email' ) );
	if ( empty( $recipient ) ) {
		$recipient = sanitize_email( $settings['recipient_email'] );
	}

	$time       = current_time( 'mysql' );
	$subdir     = '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
	$upload_dir = vn_get_upload_dir( $subdir );
	$GLOBALS['vn_upload_dir'] = array(
		'subdir' => '/voice-notes' . $subdir,
		'path'   => $upload_dir['path'],
		'url'    => $upload_dir['url'],
	);

	add_filter( 'upload_dir', 'vn_custom_upload_dir' );

	$overrides = array(
		'test_form' => false,
		'mimes'     => array(
			'webm' => 'audio/webm',
			'ogg'  => 'audio/ogg',
			'm4a'  => 'audio/mp4',
		),
	);

	$uploaded = wp_handle_upload( $file, $overrides );

	remove_filter( 'upload_dir', 'vn_custom_upload_dir' );
	unset( $GLOBALS['vn_upload_dir'] );

	if ( isset( $uploaded['error'] ) ) {
		return new WP_Error( 'vn_upload_failed', $uploaded['error'], array( 'status' => 500 ) );
	}

	$attachment_id = 0;
	if ( ! empty( $uploaded['file'] ) ) {
		$attachment = array(
			'post_mime_type' => $uploaded['type'],
			'post_title'     => sanitize_file_name( wp_basename( $uploaded['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attachment_id = wp_insert_attachment( $attachment, $uploaded['file'] );
		if ( $attachment_id ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		}
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => VN_CPT,
			'post_status' => 'publish',
			'post_title'  => sprintf( __( 'Voice Note from %s', 'voice-notes' ), $name ? $name : __( 'Anonymous', 'voice-notes' ) ),
			'post_date'   => $time,
		)
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return new WP_Error( 'vn_post_failed', __( 'Could not save submission.', 'voice-notes' ), array( 'status' => 500 ) );
	}

	update_post_meta( $post_id, '_vn_name', $name );
	update_post_meta( $post_id, '_vn_company', $company );
	update_post_meta( $post_id, '_vn_duration', $duration );
	update_post_meta( $post_id, '_vn_page_url', $page );
	update_post_meta( $post_id, '_vn_consent', $consent );
	if ( $attachment_id ) {
		update_post_meta( $post_id, '_vn_attachment_id', $attachment_id );
	}

	if ( $attachment_id ) {
		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $post_id,
			)
		);
	}

	$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$subject   = sprintf( '[%s] %s', $site_name, __( 'New voice note submission', 'voice-notes' ) );
	$body      = array();
	$body[]    = sprintf( "%s: %s", __( 'Name', 'voice-notes' ), $name ? $name : __( 'Not provided', 'voice-notes' ) );
	$body[]    = sprintf( "%s: %s", __( 'Company', 'voice-notes' ), $company ? $company : __( 'Not provided', 'voice-notes' ) );
	$body[]    = sprintf( "%s: %d", __( 'Duration (seconds)', 'voice-notes' ), $duration );
	$body[]    = sprintf( "%s: %s", __( 'Consent confirmed', 'voice-notes' ), $consent ? __( 'Yes', 'voice-notes' ) : __( 'No', 'voice-notes' ) );
	$body[]    = sprintf( "%s: %s", __( 'Page URL', 'voice-notes' ), $page ? $page : __( 'Not provided', 'voice-notes' ) );
	$body[]    = sprintf( "%s: %s", __( 'File URL', 'voice-notes' ), $uploaded['url'] );
	$body[]    = sprintf( "%s: %s", __( 'Admin link', 'voice-notes' ), admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );

	$headers = array();
	if ( ! empty( $settings['from_name'] ) && ! empty( $settings['from_email'] ) ) {
		$headers[] = 'From: ' . sanitize_text_field( $settings['from_name'] ) . ' <' . sanitize_email( $settings['from_email'] ) . '>';
	}

	$subject = apply_filters( 'vn_email_subject', $subject, $post_id );
	$headers = apply_filters( 'vn_email_headers', $headers, $post_id );
	wp_mail( $recipient, $subject, implode( "\n", $body ), $headers );

	return rest_ensure_response(
		array(
			'success' => true,
			'post_id' => $post_id,
		)
	);
}

function vn_enqueue_assets() {
	$settings = vn_get_settings();
	wp_register_style( 'voice-notes', plugins_url( 'assets/css/voice-notes.css', __FILE__ ), array(), VN_VERSION );
	wp_register_script( 'voice-notes', plugins_url( 'assets/js/voice-notes.js', __FILE__ ), array(), VN_VERSION, true );

	wp_localize_script(
		'voice-notes',
		'VoiceNotesSettings',
		array(
			'restUrl'        => esc_url_raw( rest_url( 'voice-notes/v1/submit' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'phoneNumber'    => $settings['phone_number'],
			'consentText'    => $settings['consent_text'],
			'minSeconds'     => (int) $settings['min_seconds'],
			'hardMaxSeconds' => (int) $settings['hard_max'],
			'buttonLabel'    => $settings['button_label'],
			'successTitle'   => $settings['success_title'],
			'successMessage' => $settings['success_message'],
			'preferredMax'   => (int) $settings['preferred_max'],
			'openDelay'      => 8,
			'recordLimit'    => 900,
			'strings'        => array(
				'startRecording' => __( 'Start recording', 'voice-notes' ),
				'stopRecording'  => __( 'Stop recording', 'voice-notes' ),
				'noSupport'      => __( 'Recording is not supported in this browser. Prefer to call instead?', 'voice-notes' ),
				'permissionDenied' => __( 'Microphone access was denied. Enable microphone access or prefer to call instead.', 'voice-notes' ),
				'stopBeforeClose' => __( 'Stop the recording before closing.', 'voice-notes' ),
				'noRecording'    => __( 'Please record your voice note before submitting.', 'voice-notes' ),
				'consentRequired' => __( 'Please confirm consent to continue.', 'voice-notes' ),
				'uploadFailed'   => __( 'Upload failed. Please try again.', 'voice-notes' ),
				'networkFailed'  => __( 'Upload failed. Please check your connection and try again.', 'voice-notes' ),
				'captured'       => __( 'Recording captured (%s seconds)', 'voice-notes' ),
				'tooShort'       => __( 'Recording must be at least %s seconds.', 'voice-notes' ),
				'tooLong'        => __( 'Recording must be under %s seconds.', 'voice-notes' ),
			),
		)
	);

	wp_enqueue_style( 'voice-notes' );
	wp_enqueue_script( 'voice-notes' );
}

function vn_shortcode( $atts ) {
	$settings = vn_get_settings();
	$atts     = shortcode_atts(
		array(
			'label'           => $settings['button_label'],
			'auto_open'       => 'false',
			'recipient_email' => '',
			'phone'           => $settings['phone_number'],
			'min_seconds'     => $settings['min_seconds'],
			'max_seconds'     => $settings['hard_max'],
			'theme'           => '',
		),
		$atts,
		'voice_note'
	);

	vn_enqueue_assets();

	$instance_id = uniqid( 'vn_', false );
	$auto_open   = filter_var( $atts['auto_open'], FILTER_VALIDATE_BOOLEAN );

	ob_start();
	?>
	<div class="vn-opener" data-vn-instance="<?php echo esc_attr( $instance_id ); ?>">
		<button type="button" class="vn-open-button" data-vn-open="true">
			<?php echo esc_html( $atts['label'] ); ?>
		</button>
	</div>
	<div class="vn-modal" data-vn-modal="<?php echo esc_attr( $instance_id ); ?>" data-auto-open="<?php echo esc_attr( $auto_open ? 'true' : 'false' ); ?>" data-recipient="<?php echo esc_attr( $atts['recipient_email'] ); ?>" data-phone="<?php echo esc_attr( $atts['phone'] ); ?>" data-min-seconds="<?php echo esc_attr( (int) $atts['min_seconds'] ); ?>" data-max-seconds="<?php echo esc_attr( (int) $atts['max_seconds'] ); ?>" data-theme="<?php echo esc_attr( $atts['theme'] ); ?>" aria-hidden="true" role="dialog" aria-modal="true">
		<div class="vn-modal__overlay" data-vn-overlay="true"></div>
		<div class="vn-modal__dialog" role="document" aria-labelledby="vn-title-<?php echo esc_attr( $instance_id ); ?>">
			<button type="button" class="vn-modal__close" aria-label="<?php esc_attr_e( 'Close dialog', 'voice-notes' ); ?>" data-vn-close="true">&times;</button>
			<div class="vn-modal__content" data-vn-state="idle">
				<h2 id="vn-title-<?php echo esc_attr( $instance_id ); ?>" class="vn-title"><?php esc_html_e( 'Record your voice note', 'voice-notes' ); ?></h2>
				<p class="vn-subtitle"><?php esc_html_e( 'Share your take in 30 to 60 seconds.', 'voice-notes' ); ?></p>
				<div class="vn-recorder" data-vn-recorder="true">
					<button type="button" class="vn-mic" aria-label="<?php esc_attr_e( 'Start recording', 'voice-notes' ); ?>" data-vn-mic="true">
						<span class="vn-mic__icon"></span>
					</button>
					<p class="vn-helper"><?php esc_html_e( 'Click the microphone to start recording', 'voice-notes' ); ?></p>
					<div class="vn-timer" data-vn-timer="true">00:00 <span class="vn-timer__max">max 01:30</span></div>
					<div class="vn-waveform" data-vn-waveform="true" aria-hidden="true"></div>
					<div class="vn-capture" data-vn-capture="true" hidden>
						<div class="vn-waveform vn-waveform--captured"></div>
						<p class="vn-captured-text" data-vn-captured-text="true"></p>
						<button type="button" class="vn-start-over" data-vn-start-over="true"><?php esc_html_e( 'Start over', 'voice-notes' ); ?></button>
					</div>
				</div>
				<div class="vn-fields">
					<label class="vn-field">
						<span><?php esc_html_e( 'Name (optional)', 'voice-notes' ); ?></span>
						<input type="text" name="vn_name" autocomplete="name" />
					</label>
					<label class="vn-field">
						<span><?php esc_html_e( 'Company (optional)', 'voice-notes' ); ?></span>
						<input type="text" name="vn_company" autocomplete="organization" />
					</label>
				</div>
				<label class="vn-consent">
					<input type="checkbox" name="vn_consent" />
					<span><?php echo esc_html( $settings['consent_text'] ); ?></span>
				</label>
				<input type="text" class="vn-honeypot" name="website" tabindex="-1" autocomplete="off" />
				<div class="vn-error" role="alert" data-vn-error="true" hidden></div>
				<button type="button" class="vn-submit" data-vn-submit="true" disabled><?php esc_html_e( 'Submit recording', 'voice-notes' ); ?></button>
				<p class="vn-footer">
					<?php esc_html_e( 'Prefer to call instead?', 'voice-notes' ); ?>
					<a href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $atts['phone'] ) ); ?>" class="vn-phone"><?php echo esc_html( $atts['phone'] ); ?></a>
				</p>
			</div>
			<div class="vn-modal__content" data-vn-state="success" hidden>
				<div class="vn-success">
					<div class="vn-success__icon"></div>
					<h2 class="vn-success__title"><?php echo esc_html( $settings['success_title'] ); ?></h2>
					<p class="vn-success__message"><?php echo esc_html( $settings['success_message'] ); ?></p>
					<button type="button" class="vn-close-success" data-vn-close="true"><?php esc_html_e( 'Close', 'voice-notes' ); ?></button>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'voice_note', 'vn_shortcode' );

function vn_register_block() {
	wp_register_script(
		'voice-notes-block',
		plugins_url( 'assets/js/block.js', __FILE__ ),
		array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor' ),
		VN_VERSION,
		true
	);

	register_block_type(
		'voice-notes/recorder',
		array(
			'editor_script'   => 'voice-notes-block',
			'render_callback' => 'vn_render_block',
			'attributes'      => array(
				'label'           => array( 'type' => 'string' ),
				'auto_open'       => array( 'type' => 'boolean', 'default' => false ),
				'recipient_email' => array( 'type' => 'string' ),
				'phone'           => array( 'type' => 'string' ),
				'min_seconds'     => array( 'type' => 'number' ),
				'max_seconds'     => array( 'type' => 'number' ),
				'theme'           => array( 'type' => 'string' ),
			),
		)
	);
}
add_action( 'init', 'vn_register_block' );

function vn_render_block( $attributes ) {
	$attributes = wp_parse_args( $attributes, array( 'auto_open' => false ) );
	$atts       = array(
		'label'           => isset( $attributes['label'] ) ? $attributes['label'] : '',
		'auto_open'       => ! empty( $attributes['auto_open'] ) ? 'true' : 'false',
		'recipient_email' => isset( $attributes['recipient_email'] ) ? $attributes['recipient_email'] : '',
		'phone'           => isset( $attributes['phone'] ) ? $attributes['phone'] : '',
		'min_seconds'     => isset( $attributes['min_seconds'] ) ? $attributes['min_seconds'] : '',
		'max_seconds'     => isset( $attributes['max_seconds'] ) ? $attributes['max_seconds'] : '',
		'theme'           => isset( $attributes['theme'] ) ? $attributes['theme'] : '',
	);
	return vn_shortcode( $atts );
}

function vn_admin_columns( $columns ) {
	$columns['vn_name']     = __( 'Name', 'voice-notes' );
	$columns['vn_company']  = __( 'Company', 'voice-notes' );
	$columns['vn_duration'] = __( 'Duration', 'voice-notes' );
	$columns['vn_page_url'] = __( 'Page URL', 'voice-notes' );
	$columns['vn_audio']    = __( 'Audio', 'voice-notes' );
	return $columns;
}
add_filter( 'manage_' . VN_CPT . '_posts_columns', 'vn_admin_columns' );

function vn_admin_column_content( $column, $post_id ) {
	if ( 'vn_name' === $column ) {
		echo esc_html( get_post_meta( $post_id, '_vn_name', true ) );
	}
	if ( 'vn_company' === $column ) {
		echo esc_html( get_post_meta( $post_id, '_vn_company', true ) );
	}
	if ( 'vn_duration' === $column ) {
		$duration = (int) get_post_meta( $post_id, '_vn_duration', true );
		echo esc_html( $duration ? $duration . 's' : '' );
	}
	if ( 'vn_page_url' === $column ) {
		$url = get_post_meta( $post_id, '_vn_page_url', true );
		if ( $url ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'voice-notes' ) . '</a>';
		}
	}
	if ( 'vn_audio' === $column ) {
		$attachment_id = (int) get_post_meta( $post_id, '_vn_attachment_id', true );
		if ( $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				echo wp_audio_shortcode( array( 'src' => $url ) );
			}
		}
	}
}
add_action( 'manage_' . VN_CPT . '_posts_custom_column', 'vn_admin_column_content', 10, 2 );

function vn_admin_sortable_columns( $columns ) {
	$columns['vn_duration'] = 'vn_duration';
	return $columns;
}
add_filter( 'manage_edit-' . VN_CPT . '_sortable_columns', 'vn_admin_sortable_columns' );

function vn_admin_sort_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( VN_CPT !== $query->get( 'post_type' ) ) {
		return;
	}
	if ( 'vn_duration' === $query->get( 'orderby' ) ) {
		$query->set( 'meta_key', '_vn_duration' );
		$query->set( 'orderby', 'meta_value_num' );
	}
}
add_action( 'pre_get_posts', 'vn_admin_sort_query' );
