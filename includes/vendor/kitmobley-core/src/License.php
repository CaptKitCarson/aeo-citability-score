<?php
/**
 * License handling for the Pro tier.
 *
 * Storage: wp_options row keyed by Config->option_key(), holding:
 *   array{
 *     key: string,
 *     status: string,          // active | invalid | expired | canceled | limit_reached | not_activated | inactive
 *     tier: ?string,           // "solo" | "agency"
 *     expires_at: ?string,     // ISO-8601 date
 *     max_sites: ?int,
 *     activated_sites: string[],
 *     last_checked: ?int,      // UNIX ts of last remote check
 *     message: ?string,
 *   }
 *
 * All plugin-specific coupling (slug, option key, API base) is passed
 * via a Config. No global state.
 */

namespace KitMobley\PluginCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class License {

	/** @var Config */
	private $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function config() {
		return $this->config;
	}

	public function get_state() {
		$defaults = array(
			'key'             => '',
			'status'          => 'inactive',
			'tier'            => null,
			'expires_at'      => null,
			'max_sites'       => null,
			'activated_sites' => array(),
			'last_checked'    => null,
			'message'         => null,
		);
		$state = get_option( $this->config->option_key(), array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array_merge( $defaults, $state );
	}

	public function save_state( array $patch ) {
		$state = array_merge( $this->get_state(), $patch );
		update_option( $this->config->option_key(), $state, false );
		return $state;
	}

	public function clear() {
		delete_option( $this->config->option_key() );
	}

	public function is_pro() {
		$s = $this->get_state();
		if ( 'active' !== $s['status'] ) {
			return false;
		}
		if ( ! empty( $s['expires_at'] ) ) {
			$expires_ts = strtotime( $s['expires_at'] );
			if ( $expires_ts && $expires_ts < time() ) {
				return false;
			}
		}
		return true;
	}

	public function tier() {
		$s = $this->get_state();
		return $s['tier'];
	}

	public function status_label() {
		$s = $this->get_state();
		$labels = array(
			'active'         => __( 'Active', 'kitmobley-plugin-core' ),
			'inactive'       => __( 'No license', 'kitmobley-plugin-core' ),
			'invalid'        => __( 'Invalid key', 'kitmobley-plugin-core' ),
			'expired'        => __( 'Expired', 'kitmobley-plugin-core' ),
			'canceled'       => __( 'Canceled', 'kitmobley-plugin-core' ),
			'limit_reached'  => __( 'Site limit reached', 'kitmobley-plugin-core' ),
			'not_activated'  => __( 'Not activated on this site', 'kitmobley-plugin-core' ),
		);
		return isset( $labels[ $s['status'] ] ) ? $labels[ $s['status'] ] : ucfirst( $s['status'] );
	}

	public function activate( $key ) {
		$key      = strtoupper( trim( $key ) );
		$site_url = home_url( '/' );
		$response = $this->call_api( 'validate', array(
			'license_key' => $key,
			'plugin_slug' => $this->config->slug(),
			'site_url'    => $site_url,
			'action'      => 'activate',
		) );
		if ( is_wp_error( $response ) ) {
			return $this->save_state( array(
				'key'          => $key,
				'status'       => 'invalid',
				'message'      => $response->get_error_message(),
				'last_checked' => time(),
			) );
		}
		return $this->save_state( array(
			'key'             => $key,
			'status'          => $response['valid'] ? 'active' : ( $response['status'] ?? 'invalid' ),
			'tier'            => $response['tier']          ?? null,
			'expires_at'      => $response['expires_at']    ?? null,
			'max_sites'       => isset( $response['max_sites'] ) ? (int) $response['max_sites'] : null,
			'activated_sites' => isset( $response['activated_sites'] ) ? array_values( (array) $response['activated_sites'] ) : array(),
			'message'         => $response['message']       ?? null,
			'last_checked'    => time(),
		) );
	}

	public function deactivate() {
		$state = $this->get_state();
		if ( ! empty( $state['key'] ) ) {
			$this->call_api( 'validate', array(
				'license_key' => $state['key'],
				'plugin_slug' => $this->config->slug(),
				'site_url'    => home_url( '/' ),
				'action'      => 'deactivate',
			) );
		}
		$this->clear();
	}

	public function revalidate() {
		$state = $this->get_state();
		if ( empty( $state['key'] ) ) {
			return;
		}
		$response = $this->call_api( 'validate', array(
			'license_key' => $state['key'],
			'plugin_slug' => $this->config->slug(),
			'site_url'    => home_url( '/' ),
			'action'      => 'check',
		) );
		if ( is_wp_error( $response ) ) {
			// Network hiccup — try again next cycle. Don't clobber state.
			return;
		}
		$this->save_state( array(
			'status'          => $response['valid'] ? 'active' : ( $response['status'] ?? 'invalid' ),
			'tier'            => $response['tier']       ?? $state['tier'],
			'expires_at'      => $response['expires_at'] ?? $state['expires_at'],
			'max_sites'       => isset( $response['max_sites'] ) ? (int) $response['max_sites'] : $state['max_sites'],
			'activated_sites' => isset( $response['activated_sites'] ) ? array_values( (array) $response['activated_sites'] ) : $state['activated_sites'],
			'message'         => $response['message']    ?? null,
			'last_checked'    => time(),
		) );
	}

	/**
	 * @param string $action
	 * @param array  $payload
	 * @return array|\WP_Error
	 */
	private function call_api( $action, $payload ) {
		$endpoints = array(
			'validate' => '/plugin-license-validate',
		);
		if ( ! isset( $endpoints[ $action ] ) ) {
			return new \WP_Error( 'kmpc_bad_action', 'Unknown license action' );
		}
		$url = $this->config->api_base() . $endpoints[ $action ];

		$res = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'kmpc_bad_response', sprintf( __( 'License server returned an unexpected response (HTTP %d).', 'kitmobley-plugin-core' ), $code ) );
		}
		if ( $code >= 500 ) {
			return new \WP_Error( 'kmpc_server_error', $data['message'] ?? __( 'License server error. Try again later.', 'kitmobley-plugin-core' ) );
		}
		return $data;
	}
}
