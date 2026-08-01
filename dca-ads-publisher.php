<?php
/**
 * Plugin Name: DCA Ads Publisher
 * Description: Publish AdSanity banner groups from DurhamContests.com on other WordPress websites without iframes.
 * Version: 2.1.0
 * Author: Dylan AAWS(Lahiru Mahakumburage)
 * Text Domain: dca-ads-publisher
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DCA_Ads_Publisher {
	const VERSION = '2.1.0';
	const OPTION  = 'dca_ads_settings';
	const SLUG    = 'dca-ads';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_action( 'template_redirect', array( $this, 'maybe_serve_api' ), 0 );

		add_shortcode( 'dca_ads', array( $this, 'shortcode' ) );

		add_action( 'wp_ajax_dca_ads_get_groups', array( $this, 'ajax_get_groups' ) );
		add_action( 'wp_ajax_dca_ads_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_dca_ads_rotate', array( $this, 'ajax_rotate' ) );
		add_action( 'wp_ajax_nopriv_dca_ads_rotate', array( $this, 'ajax_rotate' ) );
	}

	public static function activate() {
		$settings = get_option( self::OPTION, array() );
		if ( empty( $settings['token'] ) ) {
			$settings['token'] = wp_generate_password( 40, false, false );
		}
		if ( empty( $settings['mode'] ) ) {
			$settings['mode'] = self::adsanity_available() ? 'server' : 'client';
		}
		if ( empty( $settings['main_url'] ) ) {
			$settings['main_url'] = 'https://durhamcontests.com';
		}
		update_option( self::OPTION, $settings, false );
	}

	private static function adsanity_available() {
		return shortcode_exists( 'adsanity_group' ) && taxonomy_exists( 'ad-group' );
	}

	private function settings() {
		return wp_parse_args(
			get_option( self::OPTION, array() ),
			array(
				'mode'            => self::adsanity_available() ? 'server' : 'client',
				'main_url'        => 'https://durhamcontests.com',
				'token'           => '',
				'allowed_domains' => '',
			)
		);
	}

	public function register_settings() {
		register_setting( 'dca_ads_group', self::OPTION, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$current = $this->settings();
		$output  = array();

		$output['mode'] = isset( $input['mode'] ) && 'server' === $input['mode'] ? 'server' : 'client';
		$output['main_url'] = isset( $input['main_url'] ) ? untrailingslashit( esc_url_raw( trim( $input['main_url'] ) ) ) : $current['main_url'];
		$output['token'] = isset( $input['token'] ) ? sanitize_text_field( trim( $input['token'] ) ) : $current['token'];
		if ( '' === $output['token'] ) {
			$output['token'] = wp_generate_password( 40, false, false );
		}

		$domains = isset( $input['allowed_domains'] ) ? preg_split( '/\r\n|\r|\n/', $input['allowed_domains'] ) : array();
		$clean   = array();
		foreach ( $domains as $domain ) {
			$domain = $this->normalize_domain( $domain );
			if ( $domain ) {
				$clean[] = $domain;
			}
		}
		$output['allowed_domains'] = implode( "\n", array_unique( $clean ) );
		return $output;
	}

	public function admin_menu() {
		add_menu_page(
			'DCA Ads Publisher',
			'DCA Ads',
			'manage_options',
			self::SLUG,
			array( $this, 'admin_page' ),
			'dashicons-megaphone',
			58
		);
	}

	public function admin_assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dca-ads-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', array(), self::VERSION );
		wp_enqueue_script( 'dca-ads-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery' ), self::VERSION, true );
		wp_localize_script(
			'dca-ads-admin',
			'DCAAdsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dca_ads_admin' ),
			)
		);
	}

	public function admin_page() {
		$settings = $this->settings();
		?>
		<div class="wrap dca-wrap">
			<h1>DCA Ads Publisher</h1>
			<p>Publish DurhamContests.com AdSanity banner groups on other WordPress websites without iframes.</p>

			<form method="post" action="options.php" class="dca-panel">
				<?php settings_fields( 'dca_ads_group' ); ?>
				<h2>Connection Settings</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="dca-mode">Plugin Mode</label></th>
						<td>
							<select id="dca-mode" name="<?php echo esc_attr( self::OPTION ); ?>[mode]">
								<option value="server" <?php selected( $settings['mode'], 'server' ); ?>>Main Site — Publish AdSanity ads</option>
								<option value="client" <?php selected( $settings['mode'], 'client' ); ?>>Client Site — Display remote ads</option>
							</select>
						</td>
					</tr>
					<tr class="dca-client-row">
						<th><label for="dca-main-url">Main Ad Site URL</label></th>
						<td><input id="dca-main-url" type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[main_url]" value="<?php echo esc_attr( $settings['main_url'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="dca-token">API Token</label></th>
						<td><input id="dca-token" type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION ); ?>[token]" value="<?php echo esc_attr( $settings['token'] ); ?>"><p class="description">Use the same token on the main site and every client site.</p></td>
					</tr>
					<tr class="dca-server-row">
						<th><label for="dca-domains">Allowed Client Domains</label></th>
						<td><textarea id="dca-domains" class="large-text" rows="5" name="<?php echo esc_attr( self::OPTION ); ?>[allowed_domains]"><?php echo esc_textarea( $settings['allowed_domains'] ); ?></textarea><p class="description">One domain per line. Leave empty to allow any site with the token.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
				<button type="button" class="button" id="dca-test">Test Connection</button>
				<span id="dca-test-result"></span>
			</form>

			<div class="dca-builder">
				<div class="dca-panel">
					<h2>1. Configure Ad</h2>
					<label for="dca-group">Select Ad Group:</label>
					<select id="dca-group"><option value="">Loading groups...</option></select>
					<div id="dca-group-status"></div>

					<label for="dca-speed">Rotation Speed (Seconds):</label>
					<input id="dca-speed" type="number" min="5" value="15">

					<label for="dca-align">Alignment:</label>
					<select id="dca-align"><option value="left">Left</option><option value="center" selected>Center</option><option value="right">Right</option></select>

					<label for="dca-width">Width (e.g. 100%, 468px):</label>
					<input id="dca-width" type="text" value="100%">

					<label for="dca-height">Height (e.g. auto, 60px):</label>
					<input id="dca-height" type="text" value="auto">
				</div>

				<div class="dca-panel">
					<h2>2. Your Shortcode</h2>
					<textarea id="dca-shortcode" class="large-text code" rows="5" readonly>[dca_ads]</textarea>
					<p><button type="button" class="button button-primary" id="dca-copy">Copy to Clipboard</button></p>
					<h3>Preview</h3>
					<div id="dca-preview"></div>
				</div>
			</div>
		</div>
		<?php
	}

	public function maybe_serve_api() {
		if ( empty( $_GET['dca_ads_api'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['dca_ads_api'] ) );
		if ( ! in_array( $action, array( 'groups', 'render' ), true ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );

		if ( ! $this->api_authorized() ) {
			status_header( 401 );
			echo wp_json_encode( array( 'success' => false, 'message' => 'Invalid API token or client domain.' ) );
			exit;
		}

		if ( ! self::adsanity_available() ) {
			status_header( 500 );
			echo wp_json_encode( array( 'success' => false, 'message' => 'AdSanity is not available on the main site.' ) );
			exit;
		}

		if ( 'groups' === $action ) {
			$terms  = get_terms( array( 'taxonomy' => 'ad-group', 'hide_empty' => false ) );
			$groups = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$groups[] = array( 'id' => (int) $term->term_id, 'name' => $term->name, 'count' => (int) $term->count );
				}
			}
			echo wp_json_encode( array( 'success' => true, 'groups' => $groups ) );
			exit;
		}

		$group_id  = isset( $_GET['group'] ) ? absint( $_GET['group'] ) : 0;
		$exclude_id = isset( $_GET['exclude'] ) ? absint( $_GET['exclude'] ) : 0;

		if ( ! $group_id || ! term_exists( $group_id, 'ad-group' ) ) {
			status_header( 404 );
			echo wp_json_encode( array( 'success' => false, 'message' => 'Ad group not found.' ) );
			exit;
		}

		$ad_ids = get_posts(
			array(
				'post_type'              => 'ads',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'rand',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy' => 'ad-group',
						'field'    => 'term_id',
						'terms'    => array( $group_id ),
					),
				),
			)
		);

		if ( empty( $ad_ids ) ) {
			status_header( 404 );
			echo wp_json_encode( array( 'success' => false, 'message' => 'No published ads were found in this group.' ) );
			exit;
		}

		// Avoid immediately repeating the currently displayed ad when alternatives exist.
		if ( $exclude_id && count( $ad_ids ) > 1 ) {
			$ad_ids = array_values( array_diff( $ad_ids, array( $exclude_id ) ) );
		}

		$ad_id = (int) $ad_ids[ array_rand( $ad_ids ) ];
		$html  = do_shortcode( sprintf( '[adsanity id="%d" align="aligncenter" /]', $ad_id ) );

		echo wp_json_encode(
			array(
				'success' => true,
				'html'    => $html,
				'ad_id'   => $ad_id,
				'total'   => count( $ad_ids ),
			)
		);
		exit;
	}

	private function api_authorized() {
		$settings = $this->settings();
		$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$client   = isset( $_GET['client'] ) ? $this->normalize_domain( wp_unslash( $_GET['client'] ) ) : '';

		if ( ! $settings['token'] || ! hash_equals( (string) $settings['token'], (string) $token ) ) {
			return false;
		}

		$allowed = preg_split( '/\r\n|\r|\n/', (string) $settings['allowed_domains'] );
		$allowed = array_filter( array_map( array( $this, 'normalize_domain' ), $allowed ) );
		return empty( $allowed ) || ( $client && in_array( $client, $allowed, true ) );
	}

	private function normalize_domain( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( false === strpos( $value, '://' ) ) {
			$value = 'https://' . $value;
		}
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	private function endpoint_request( $action, $group_id = 0, $exclude_id = 0 ) {
		$settings = $this->settings();
		if ( 'server' === $settings['mode'] ) {
			$base = home_url( '/' );
		} else {
			$base = trailingslashit( $settings['main_url'] );
		}

		$args = array(
			'dca_ads_api' => $action,
			'token'       => $settings['token'],
			'client'      => $this->normalize_domain( home_url() ),
			'_dca'        => wp_generate_password( 8, false, false ),
		);
		if ( $group_id ) {
			$args['group'] = absint( $group_id );
		}
		if ( $exclude_id ) {
			$args['exclude'] = absint( $exclude_id );
		}

		$url      = add_query_arg( $args, $base );
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3, 'sslverify' => true ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['success'] ) ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Unable to connect to the main ad site.';
			return new WP_Error( 'dca_remote_error', $message );
		}
		return $data;
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'group'  => 0,
				'speed'  => 15,
				'align'  => 'center',
				'width'  => '100%',
				'height' => 'auto',
			),
			$atts,
			'dca_ads'
		);

		$group_id = absint( $atts['group'] );
		if ( ! $group_id ) {
			return current_user_can( 'manage_options' ) ? '<p>DCA Ads: select an ad group.</p>' : '';
		}

		$speed  = max( 5, absint( $atts['speed'] ) );
		$align  = in_array( $atts['align'], array( 'left', 'center', 'right' ), true ) ? $atts['align'] : 'center';
		$width  = $this->safe_dimension( $atts['width'], '100%' );
		$height = $this->safe_dimension( $atts['height'], 'auto' );
		$data   = $this->endpoint_request( 'render', $group_id );

		if ( is_wp_error( $data ) ) {
			return current_user_can( 'manage_options' ) ? '<p class="dca-ads-error">DCA Ads: ' . esc_html( $data->get_error_message() ) . '</p>' : '';
		}

		$id    = 'dca-ads-' . wp_generate_uuid4();
		$style = 'width:' . esc_attr( $width ) . ';height:' . esc_attr( $height ) . ';text-align:' . esc_attr( $align ) . ';max-width:100%;';
		$html  = '<div class="dca-ads-wrap" style="text-align:' . esc_attr( $align ) . ';">';
		$current_ad_id = ! empty( $data['ad_id'] ) ? absint( $data['ad_id'] ) : 0;
		$html .= '<div id="' . esc_attr( $id ) . '" class="dca-ads-box" data-group="' . esc_attr( $group_id ) . '" data-speed="' . esc_attr( $speed ) . '" data-current-ad="' . esc_attr( $current_ad_id ) . '" style="' . $style . '">';
		$html .= $data['html'];
		$html .= '</div></div>';
		$html .= '<style>.dca-ads-box img{max-width:100%!important;height:auto!important;display:inline-block}.dca-ads-box .adsanity-group,.dca-ads-box .adsanity-single{margin:0!important;padding:0!important}.dca-ads-box iframe{max-width:100%}</style>';
		$html .= $this->rotation_script( $id, $group_id, $speed, $current_ad_id );
		return $html;
	}

	private function rotation_script( $id, $group_id, $speed, $current_ad_id = 0 ) {
		$config = array(
			'id'        => $id,
			'group'     => $group_id,
			'speed'     => max( 5, absint( $speed ) ) * 1000,
			'currentAd' => absint( $current_ad_id ),
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		);

		return '<script>(function(c){
			var el=document.getElementById(c.id);
			if(!el){return;}
			var busy=false;
			function rotate(){
				if(busy||document.hidden){return;}
				busy=true;
				var current=parseInt(el.getAttribute("data-current-ad")||c.currentAd||0,10);
				var body=new URLSearchParams({action:"dca_ads_rotate",group:String(c.group),exclude:String(current),_dca:String(Date.now())});
				fetch(c.ajaxUrl,{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:body.toString()})
				.then(function(r){return r.json();})
				.then(function(r){
					if(r.success&&r.data&&r.data.html){
						el.innerHTML=r.data.html;
						if(r.data.ad_id){el.setAttribute("data-current-ad",String(r.data.ad_id));}
					}
				})
				.catch(function(){})
				.finally(function(){busy=false;});
			}
			window.setInterval(rotate,c.speed);
		})(' . wp_json_encode( $config ) . ');</script>';
	}

	private function safe_dimension( $value, $fallback ) {
		$value = trim( (string) $value );
		if ( 'auto' === $value ) {
			return 'auto';
		}
		if ( preg_match( '/^\d+(?:\.\d+)?(?:px|%|em|rem|vw|vh)$/', $value ) ) {
			return $value;
		}
		return $fallback;
	}

	public function ajax_rotate() {
		$group_id  = isset( $_POST['group'] ) ? absint( $_POST['group'] ) : 0;
		$exclude_id = isset( $_POST['exclude'] ) ? absint( $_POST['exclude'] ) : 0;

		if ( ! $group_id ) {
			wp_send_json_error( array( 'message' => 'Invalid ad group.' ), 400 );
		}

		$data = $this->endpoint_request( 'render', $group_id, $exclude_id );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ), 502 );
		}

		wp_send_json_success(
			array(
				'html'  => $data['html'],
				'ad_id' => ! empty( $data['ad_id'] ) ? absint( $data['ad_id'] ) : 0,
			)
		);
	}

	public function ajax_get_groups() {
		check_ajax_referer( 'dca_ads_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		$data = $this->endpoint_request( 'groups' );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ), 502 );
		}
		wp_send_json_success( array( 'groups' => $data['groups'] ) );
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'dca_ads_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		$data = $this->endpoint_request( 'groups' );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ), 502 );
		}
		wp_send_json_success( array( 'message' => 'Connected successfully. ' . count( $data['groups'] ) . ' group(s) found.' ) );
	}
}

register_activation_hook( __FILE__, array( 'DCA_Ads_Publisher', 'activate' ) );
DCA_Ads_Publisher::instance();
