<?php
/**
 * WordPress HTTP API transport.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId\Transport;

/**
 * Sends requests through wp_remote_post() and wp_remote_get(), so proxies and the site's HTTP filters apply.
 * No request follows a redirect, and a response is read up to RESPONSE_LIMIT bytes (a longer one is
 * cut off, fails to parse and counts as "service unavailable"; docs/security.md §3).
 */
final class WpHttpTransport implements TransportInterface {

	/**
	 * Largest response body read, in bytes; the services answer with a few kilobytes.
	 */
	public const RESPONSE_LIMIT = 262144;

	/**
	 * JSON request.
	 *
	 * @param string               $url     Endpoint.
	 * @param array<string, mixed> $payload Data.
	 * @param int                  $timeout Seconds.
	 */
	public function post_json( string $url, array $payload, int $timeout ): ?TransportResponse {
		return $this->send(
			$url,
			array(
				'timeout'    => $timeout,
				'headers'    => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'       => (string) wp_json_encode( $payload ),
				'user-agent' => self::user_agent(),
			)
		);
	}

	/**
	 * XML (SOAP 1.1) request.
	 *
	 * @param string $url         Endpoint.
	 * @param string $body        XML document.
	 * @param int    $timeout     Seconds.
	 * @param string $soap_action SOAPAction header value.
	 */
	public function post_xml( string $url, string $body, int $timeout, string $soap_action = '' ): ?TransportResponse {
		return $this->send(
			$url,
			array(
				'timeout'    => $timeout,
				'headers'    => array(
					'Content-Type' => 'text/xml; charset=utf-8',
					// SOAP 1.1 quotes the action; an empty value stays empty, as VIES expects.
					'SOAPAction'   => '' === $soap_action ? '' : '"' . $soap_action . '"',
				),
				'body'       => $body,
				'user-agent' => self::user_agent(),
			)
		);
	}

	/**
	 * JSON GET request (the Brønnøysund register takes the number in the path).
	 *
	 * @param string $url     Resource.
	 * @param int    $timeout Seconds.
	 * @param string $accept  Accept header.
	 */
	public function get_json( string $url, int $timeout, string $accept = 'application/json' ): ?TransportResponse {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => self::RESPONSE_LIMIT,
				'headers'             => array( 'Accept' => $accept ),
				'user-agent'          => self::user_agent(),
			)
		);
		return self::wrap( $response );
	}

	/**
	 * Performs a POST request.
	 *
	 * @param string               $url  Endpoint.
	 * @param array<string, mixed> $args wp_remote_post() arguments.
	 */
	private function send( string $url, array $args ): ?TransportResponse {
		$args['redirection']         = 0;
		$args['limit_response_size'] = self::RESPONSE_LIMIT;
		return self::wrap( wp_remote_post( $url, $args ) );
	}

	/**
	 * Status and body of a WordPress HTTP response, null when no response was received.
	 *
	 * @param array<string, mixed>|\WP_Error $response wp_remote_*() result.
	 */
	private static function wrap( $response ): ?TransportResponse {
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return new TransportResponse( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Identifies the plugin, not the shop, towards the service (data minimisation).
	 */
	private static function user_agent(): string {
		return 'EuroB2B/' . ( defined( 'EUROB2B_VERSION' ) ? EUROB2B_VERSION : 'dev' ) . ' (WordPress; +https://wordpress.org/)';
	}
}
