<?php

use Firebase\JWT\JWT;

/**
 * Class with static methods to generate and parse capability tokens.
 */
class Capability_Tokens {

  private static $payload = null;

  /**
   * Add actions and filters.
   */
  public static function setup() {
    $payload = Capability_Tokens::get_payload_from_token();
    Capability_Tokens::$payload = $payload;

    // Filter that modifies user capabilities during runtime.
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST && $payload ) {
      add_filter( 'user_has_cap', 'Capability_Tokens::user_has_cap', 20, 4);
    }
  }

  /**
   * Generate a capability token using the given payload.
   */
  public static function generate( $payload ) {
    // Get the current time to compute issue and expiration time.
    $issued_at = time();

    // Generate payload.
    $payload = array(
      "iat" => $issued_at,
      // Only 60 seconds.
      "exp"       => $issued_at + MINUTE_IN_SECONDS,
      'type'      => $payload['type'],
      'post_type' => $payload['post_type'],
      'post_id'   => $payload['post_id'],
    );

    return JWT::encode($payload, Capability_Tokens::get_private_key());
  }

  /**
   * Validate a token using args.
   */
  public static function check_capability( $args ) {
    $payload = (array) Capability_Tokens::$payload;

    if ( $payload['type'] === 'preview' ) {

      // Allowed HTTP methods when the token is type 'preview'.
      $allow_methods = array( 'GET' );
      
      $post_id = $payload['post_id'];
      $post_type = $payload['post_type'];
      
      // Allowed capabilites when the token is type 'preview'. You also need to
      // have permission to 'edit_post' or 'delete_post' for preview posts.
      $capabilities = array(
        'read_post'   => $post_id,
        'edit_post'   => $post_id,
        'delete_post' => $post_id,
      );

      // Prior to WordPress 5.5.1, capabilities should be specified with `page`
      // for pages, so we are adding them as well to support older versions of
      // WordPress.
      if ( $post_type === 'page' ) {
        $capabilities = array_merge( $capabilities, array(
          "read_page"   => $id,
          "edit_page"   => $id,
          "delete_page" => $id,
        ) );
      }
    }

    // If it is not an allowed HTTP method.
    if ( ! in_array( $_SERVER[ 'REQUEST_METHOD' ], allow_methods ) ) {
      return false;
    }

    // Use key-value to check capabilities with an associated ID.
    if ( count( $args ) === 3 ) {
      // Get capability and ID.
      list( $cap, $_, $id ) = $args;
      // Find that capability in the capabilities array.
      return isset( $capabilities[ $cap ] ) && $capabilities[ $cap ] === $id;
    }

    // If it is a global capability, check if it is included as value.
    list( $cap ) = $args;
    return in_array( $cap, $capabilities );
  }
  
  /**
   * Modify user capabilities on run time.
   */
  public static function user_has_cap( $allcaps, $caps, $args, $user ) {
    // Add capability if it is allowed in the token.
    if ( Capability_Tokens::check_capability( $args )) {
      foreach ( $caps as $cap ) {
        $allcaps[ $cap ] = true;
      }
    }
      
    // Return capabilities.
    return $allcaps;
  }

  /**
   * Return the private key used to encode and decode tokens.
   */
  private static function get_private_key() {
    if ( defined( 'FRONTITY_JWT_AUTH_KEY' ) )
      return FRONTITY_JWT_AUTH_KEY;

    if ( defined( 'SECURE_AUTH_KEY' ) )
      return SECURE_AUTH_KEY;

    // No secure auth key found. Throw an error.
    $error = new WP_Error( 
      'no-secure-auth-key', 
      'Please define either SECURE_AUTH_KEY or FRONTITY_JWT_AUTH_KEY in your wp-config.php file.'
    );
    throw new Exception( $error->get_error_message() );
  }

  /**
   * Decode capability tokens if present.
   */
  private static function get_payload_from_token() {
		// Get HTTP Authorization Header.
    $header = isset( $_SERVER['HTTP_AUTHORIZATION'] )
      ? sanitize_text_field( $_SERVER['HTTP_AUTHORIZATION'] )
      : false;

		// Check for alternative header.
		if ( ! $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
    }
    
    // No Authorization Header is present.
    if ( ! $header ) return null;

    // Get and parse the token.
    try {
      list( $token ) = sscanf( $header, 'Bearer %s' );
      $payload = JWT::decode(
        $token,
        Capability_Tokens::get_private_key(),
        array('HS256')
      );
    } catch (Exception $e) {
      // Token is not valid.
      return null;
    }

    // Return the parsed token.
		return $payload;
  }
}