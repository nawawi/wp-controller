<?php 

class Wpmc_Client_Access_Handler {

	protected $keys_length = 40;
	protected $tokens_length = 40;
	protected $refresh_token_expires = 900;	/* 15 Minutes. */
	protected $authorization_code_expires = 30;

	private $tokens = array(
		'access' => null,
		'refresh' => null,
		'refresh_expires' => null,
	);

	private $authorization = array(
		'code'	=> null,
		'expires' => null
	);

	private $client = array(
		'id'	=> null,
		'secret' => null
	);

	protected function generate_key(){
		$key = "";
		$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		for ($i=0; $i<$this->keys_length; $i++) {
			$key .= $chars[ rand( 0, strlen( $chars ) - 1 ) ];
		}
		return $key;
	}

	protected function generate_token(){
		return strtolower( wp_generate_password( $this->tokens_length, false, false ) );
	}

	protected function create_access_token( $user_id ){
		$this->tokens['access'] = $this->generate_token();
		update_option( 'wpmc_access_token[' . $user_id . ']', $this->tokens['access'] );
	}

	protected function create_refresh_token( $user_id ){
		$this->tokens['refresh'] = $this->generate_token();
		update_option( 'wpmc_refresh_token[' . $user_id . ']', $this->tokens['refresh'] );
	}

	protected function create_refresh_expire_token( $user_id ){
		$this->tokens['refresh_expires'] = time() + $this->refresh_token_expires;
		update_option( 'wpmc_refresh_token_expires[' . $user_id . ']', $this->tokens['refresh_expires'] );
	}

	protected function throw_request_error($message = ''){
		echo esc_html( $message );
		wp_die();
	}

	protected function set_invalid_request_error($status = 500){
		return new WP_Error( 'invalid-request', __( 'Invalid request', 'wp-management-controller' ), array( 'status' => $status ) );
	}

	protected function unpackage_request($args){

		$package = Wpmc_Rsa_Handler::decrypt( base64_decode( $args['package'] ) );

		$signature = base64_decode( $args['signature'] );
		$verified_signature =  Wpmc_Rsa_Handler::verify( $package, $signature );

		if( ! $verified_signature ){
			$error = $this->set_invalid_request_error( 403 );
			$this->throw_request_error( $error->get_error_message() );
		}

		$package = json_decode( $package, true );
		
		return $package;
	}

	protected function prepare_response($args){
		$plaintext = json_encode( $args );
	    $signature = base64_encode( Wpmc_Rsa_Handler::sign( $plaintext ) );
	    $package = base64_encode( Wpmc_Rsa_Handler::encrypt( $plaintext ) );
	    return  array( 'signature' => $signature, 'package' => $package );
	}

	protected function create_tokens($user){
		$this->create_access_token($user->ID);
		$this->create_refresh_token($user->ID);
		$this->create_refresh_expire_token($user->ID);
		return $this->get_tokens();
	}

	protected function refresh_tokens($user){
		$this->tokens['access'] = get_option( 'wpmc_access_token[' . $user->ID . ']' );
		$this->create_refresh_token($user->ID);
		$this->create_refresh_expire_token($user->ID);
		return $this->get_tokens();
	}

	protected function create_authorization_code( $user ){
		
		$this->authorization = array(
			'code'	=> $this->generate_token(),
			'expires' => time() + $this->authorization_code_expires
		);

		update_option( 'wpmc_authorization_code[' . $user->ID . ']', $this->authorization['code'] );
		update_option( 'wpmc_authorization_expires[' . $user->ID . ']', $this->authorization['expires'] );
		
		return $this->get_authorization_code();
	}

	protected function create_client( $user ){

		$this->client = array(
			'id'	=> $this->generate_key(),
			'secret' => $this->generate_key()
		);

		update_option( 'wpmc_client_id[' . $user->ID . ']', $this->client['id'] );
		update_option( 'wpmc_client_secret[' . $user->ID . ']', $this->client['secret'] );

		return $this->get_client($user);
	}

	protected function get_tokens(){
		return $this->tokens;
	}

	protected function get_authorization_code(){
		return $this->authorization;
	}

	protected function get_authorization_code_expire($user_id){
		return (int) get_option( 'wpmc_authorization_expires[' . $user_id . ']' );
	}

	protected function get_client($user){
		$this->client['id'] = null === $this->client['id'] ? get_option( 'wpmc_client_id[' . $user->ID . ']' ) : $this->client['id'];
		$this->client['secret'] = null === $this->client['secret'] ? get_option( 'wpmc_client_secret[' . $user->ID . ']' ) : $this->client['secret'];
		return $this->client;
	}

	protected function user_id_by_token_or_key($type, $value){
		global $wpdb;
		$db_table = $wpdb->prefix . 'options';
		$sql = $wpdb->prepare('SELECT option_name FROM ' . $db_table . ' WHERE option_value=%s LIMIT 1', $value);
		$result = $wpdb->get_var($sql);
		switch($type){
			case 'client_id':
				$regex = "/wpmc_client_id\[(.*?)\]/i";
				break;
			case 'client_secret':
				$regex = "/wpmc_client_secret\[(.*?)\]/i";
				break;
			case 'authorization_code':
				$regex = "/wpmc_authorization_code\[(.*?)\]/i";
				break;
			case 'access_token':
				$regex = "/wpmc_access_token\[(.*?)\]/i";
				break;
			case 'refresh_token':
				$regex = "/wpmc_refresh_token\[(.*?)\]/i";
				break;
		}

		if ( preg_match( $regex, $result, $match ) ){
			return (int) $match[1];
		}
		return 0;
	}

	protected function invalid_access_redirect($url, $error, $site_id, $request_url, $request_action){
		$redirect_args = $this->prepare_response( array( 'site_id' => $site_id, 'request_url' => $request_url, 'request_action' => $request_action, 'error' => $error ) );
		$redirect_args_len = count($redirect_args);
		$url .= ( parse_url( $url, PHP_URL_QUERY ) ? '&' : '?' );
		$cntr = 0;
        foreach ($redirect_args as $k => $v) {
        	$cntr++;
        	$url .= $k . '=' . $v . ( $cntr < $redirect_args_len ? '&' : '' ); 
        }
		wp_redirect($url);
		exit;
	}

	protected function invalid_access_response($url, $error, $site_id, $request_url, $request_action){
		return $this->prepare_response( array( 'site_id' => $site_id, 'request_url' => $request_url, 'request_action' => $request_action, 'error' => $error ) );
	}

	public function ping_endpoint( $args ){
		$package = $this->unpackage_request($args);
		$site_id = isset( $package['site_id'] ) && $package['site_id'] ? $package['site_id'] : null;
		$security_arg = isset( $package['security_arg'] ) && $package['security_arg'] ? $package['security_arg'] : null;
		$response_args = array( 
			'site_id' => $site_id,
			'security_arg' => $security_arg
		);
		return $this->prepare_response( $response_args );
	}

	public function access_endpoint( $args ){

		$sec_arg = isset( $args['sec_arg'] ) ? $args['sec_arg'] : null;

		if( $sec_arg ){

			$access_user_data = get_transient( 'wpmc_access_user_data' );

			if( $access_user_data && isset( $access_user_data['user'] ) && isset( $access_user_data['security_arg'] ) ){

				delete_transient( 'wpmc_access_user_data' );

				if( $sec_arg === $access_user_data['security_arg'] ){

					$user = $access_user_data['user'];
						
					wp_set_current_user( $user->ID, $user->user_login );
			        wp_set_auth_cookie( $user->ID );
			        do_action( 'wp_login', $user->user_login, $user, false );

			        update_user_meta( $user->ID, 'last_login_time', current_time('mysql') );

			        wp_safe_redirect( admin_url() );
					exit;
				}
			}
		}

		wp_redirect( site_url() );
		exit;
	}

	public function login_endpoint( $args ){

		$valid_actions = array('login');

		$package = $this->unpackage_request($args);

		$action = isset( $package['action'] ) && $package['action'] ? $package['action'] : null;
		$site_id = isset( $package['site_id'] ) && $package['site_id'] ? $package['site_id'] : null;
		$request_url = isset( $package['request_url'] ) && $package['request_url'] ? $package['request_url'] : null;
		$access_token = isset( $package['access_token'] ) && $package['access_token'] ? $package['access_token'] : null;
		$username = isset( $package['username'] ) && $package['username'] ? $package['username'] : null;
		$invalid_redirect = isset( $package['invalid_redirect'] ) && $package['invalid_redirect'] ? $package['invalid_redirect'] : null;

		$user_id_by_access_token = $this->user_id_by_token_or_key( 'access_token', $access_token );

		/* Invalid request data. */
		if( null === $action || null === $site_id || null === $request_url || null === $access_token ||  null === $username || ! in_array( $action, $valid_actions, true ) ){
			
			if( null !== $invalid_redirect ){
				$response_args = array( 'error' => ! $access_token ? 'invalid-access' : 'invalid-request' );
				return $this->prepare_response( $response_args );
			}
			else{
				/* Redirect in home page. */
				$response_args = array( 'error' => false, 'redirect' => site_url() );
				return $this->prepare_response( $response_args );
			}
		}

		if ( ! function_exists('get_user_by') ) { require_once (ABSPATH . WPINC . '/pluggable.php'); }

		$user = get_user_by( 'login', $username );

		/*  Invalid username. */
		if( ! $user ){
			if( null !== $invalid_redirect ){
				$response_args = array( 'error' => 'invalid-user' );
				return $this->prepare_response( $response_args );
			}
			else{
				/* Redirect in home page. */
				$response_args = array( 'error' => false, 'redirect' => site_url() );
				return $this->prepare_response( $response_args );
			}
		}

		$user_id_by_access_token = $this->user_id_by_token_or_key( 'access_token', $access_token );

		/* Invalid access token. */
		if( $user_id_by_access_token !== $user->ID ){
			if( null !== $invalid_redirect ){
				$response_args = array( 'error' => 'invalid-access-token' );
				return $this->prepare_response( $response_args );
			}
			else{
				/* Redirect in login page.*/
				$response_args = array( 'error' => false, 'redirect' => wp_login_url() );
				return $this->prepare_response( $response_args );
			}
		}

		switch( $action ){
			case 'login':
				$security_arg = sha1( uniqid( mt_rand(), true ) );
				set_transient( 'wpmc_access_user_data', 
								array( 
									'user' => $user,
									'security_arg' => $security_arg 
								),
								30	/* Keep it fresh only for 30 seconds. */
							);
				$response_args = array( 'error' => false, 'redirect' => site_url( 'wp-json/' . WPMC_SLUG . '/access?sec_arg=' . $security_arg ) );
				return $this->prepare_response( $response_args );
				break;
		}
	}

	public function available_updates_endpoint( $args ){

		$package = $this->unpackage_request($args);

		$valid_actions = array('updates', 'updates-2');

		$action = isset( $package['action'] ) && $package['action'] ? $package['action'] : null;
		$updates_type = isset( $package['type'] ) && $package['type'] ? $package['type'] : null;
		$only_count = isset( $package['count'] ) && $package['count'] ? $package['count'] : null;
		$site_id = isset( $package['site_id'] ) && $package['site_id'] ? $package['site_id'] : null;
		$request_url = isset( $package['request_url'] ) && $package['request_url'] ? $package['request_url'] : null;
		$access_token = isset( $package['access_token'] ) && $package['access_token'] ? $package['access_token'] : null;
		$username = isset( $package['username'] ) && $package['username'] ? $package['username'] : null;
		$invalid_redirect = isset( $package['invalid_redirect'] ) && $package['invalid_redirect'] ? $package['invalid_redirect'] : null;

		if( null === $action || null === $updates_type || null === $only_count || null === $site_id || null === $request_url || null === $access_token ||  null === $username || ! in_array( $action, $valid_actions, true ) ){

			if( null !== $invalid_redirect ){
				$error = ! $access_token ? 'invalid-access' : 'invalid-request';
				return $this->invalid_access_response( $invalid_redirect, $error, $site_id, $request_url, $action );
			}
			else{
				/* Redirect in home page. */
				wp_safe_redirect( site_url() );
				exit;
			}

		}

		$valid_types = array('all', 'core', 'themes', 'plugins');
		
		if( ! in_array( $updates_type, $valid_types, true ) ){
			return $this->prepare_response( $this->set_invalid_request_error() );
		}

		/* Return only number of available updates. */
		$only_count = 'false' === $only_count ? false : true;

		if ( ! function_exists('get_user_by') ) { require_once (ABSPATH . WPINC . '/pluggable.php'); }

		$user = get_user_by( 'login', $username );

		/* Invalid username. */
		if( ! $user ){

			if( null !== $invalid_redirect ){
				$error = 'invalid-user';
				return $this->invalid_access_response( $invalid_redirect, $error, $site_id, $request_url, $action );
			}
			else{
				/* Redirect in home page. */
				wp_safe_redirect(site_url());
				exit;
			}
		}

		$user_id_by_access_token = $this->user_id_by_token_or_key( 'access_token', $access_token );
		
		/* Invalid access token. */
		if( $user_id_by_access_token !== $user->ID ){
			if( null !== $invalid_redirect ){
				$error = 'invalid-access';
				return $this->invalid_access_response( $invalid_redirect, $error, $site_id, $request_url, $action );
			}
			else{
				/* Redirect in login page. */
				wp_safe_redirect(wp_login_url());
				exit;
			}
		}

		if( 'all' === $updates_type ){

			if( $only_count ){
				$ret = array(
					'core' => wpmc_core_upgrade() ? 1 : 0,
					'themes' => count( wpmc_themes_updates() ),
					'plugins' => count( wpmc_plugins_updates() ),
				);
			}
			else{
				$ret = array(
					'core' => wpmc_core_upgrade(),
					'themes' => wpmc_themes_updates(),
					'plugins' => wpmc_plugins_updates(),
				);
			}
		}
		else{
			switch( $updates_type ){
				case 'core':
					$ret = $only_count ? ( wpmc_core_upgrade() ? 1 : 0 ) : wpmc_core_upgrade();
					break;
				case 'themes':
					$ret = $only_count ? count( wpmc_themes_updates() ) : wpmc_themes_updates();
					break;
				case 'plugins':
					$ret = $only_count ? count( wpmc_plugins_updates() ) : wpmc_plugins_updates();
					break;
			}
		}

		$ret['manage_options'] = user_can( $user, 'manage_options') ? 1 : 0;

		$ret['can_update_plugins'] = user_can( $user, 'update_plugins') ? 1 : 0;
		$ret['can_update_themes'] = user_can( $user, 'update_themes') ? 1 : 0;
		$ret['can_update_core'] = user_can( $user, 'update_core') ? 1 : 0;

		return $this->prepare_response( $ret );
	}

	public function available_update_now_endpoint( $args ){

		$package = $this->unpackage_request($args);

		$valid_actions = array('update_now');

		$action = isset( $package['action'] ) && $package['action'] ? $package['action'] : null;
		$updates_data = isset( $package['data'] ) && $package['data'] ? $package['data'] : null;
		$site_id = isset( $package['site_id'] ) && $package['site_id'] ? $package['site_id'] : null;
		$access_token = isset( $package['access_token'] ) && $package['access_token'] ? $package['access_token'] : null;
		$username = isset( $package['username'] ) && $package['username'] ? $package['username'] : null;
		$request_url = isset( $package['request_url'] ) && $package['request_url'] ? $package['request_url'] : null;
		$invalid_redirect = isset( $package['invalid_redirect'] ) && $package['invalid_redirect'] ? $package['invalid_redirect'] : null;

		if( null === $action || null === $updates_data || null === $site_id || null === $access_token ||  null === $username || null === $request_url || ! in_array( $action, $valid_actions, true ) ){
			$error = ! $access_token ? 'invalid-access' : 'invalid-request';
			return $this->invalid_access_response( $invalid_redirect, $error, $site_id, $request_url, $action );
		}

		if ( ! function_exists('get_user_by') ) { require_once (ABSPATH . WPINC . '/pluggable.php'); }

		$user = get_user_by( 'login', $username );

		/* Invalid username. */
		if( ! $user ){
			$error = 'invalid-user';
			return $this->invalid_access_response( $invalid_redirect, $error, $site_id, $request_url, $action );
		}

		$user_id_by_access_token = $this->user_id_by_token_or_key( 'access_token', $access_token );
		
		/* Invalid access token. */
		if( $user_id_by_access_token !== $user->ID ){
			$error = 'invalid-access';
			return $this->invalid_access_response( $invalid_redirect, $error, $site_id, $request_url, $action );
		}

		$return = array();

		if( isset( $updates_data['plugins'] ) ){
			$return['plugins'] = wpmc_update_plugins( $updates_data['plugins'] );
		}

		if( isset( $updates_data['themes'] ) ){
			$return['themes'] = wpmc_update_themes( $updates_data['themes'] );
		}

		if( isset( $updates_data['core'] ) ){
			$return['core'] = wpmc_update_core();
		}

		return $this->prepare_response( $return );
	}
}