<?php
/**
 * Plugin Name: FMG Security Hardening
 * Description: Baseline hardening for the Fernwell site: security headers, XML-RPC off, username enumeration closed, version fingerprint removed.
 * Version:     1.0.0
 * Author:      Fernwell Media Group
 *
 * Drop this file directly into wp-content/mu-plugins/ (no subfolder: mu-plugins
 * only autoloads top-level PHP files).
 *
 * @package Fernwell
 */

defined( 'ABSPATH' ) || exit;

/*
 * Security headers on every front-end response. HSTS is left to the host
 * (the host manages TLS termination); clickjacking, MIME sniffing, referrer
 * leakage, and unneeded browser APIs are closed here.
 */
add_action(
	'send_headers',
	function () {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
	}
);

/*
 * XML-RPC: nothing on this site uses it (no Jetpack, no remote publishing),
 * and it is the standard brute-force and pingback-amplification surface.
 */
add_filter( 'xmlrpc_enabled', '__return_false' );
// xmlrpc_enabled only gates the authenticated methods; emptying the method
// table also kills the unauthenticated ones (pingback.ping, demo.*, system.*).
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

/*
 * Username enumeration. The REST users endpoint and author archives both leak
 * the login name; the site has one author and uses neither publicly.
 */
add_filter(
	'rest_endpoints',
	function ( $endpoints ) {
		if ( ! is_user_logged_in() ) {
			unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}
);
add_action(
	'template_redirect',
	function () {
		if ( is_author() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}
);

/* WordPress version fingerprint out of head, feeds, and generator output. */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

/* One vague login error regardless of whether the username or password missed. */
add_filter(
	'login_errors',
	function () {
		return 'That login did not work. Check your username and password.';
	}
);
