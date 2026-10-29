<?php
/**
 * Outbound HTTP for validation services.
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId\Transport;

/**
 * The only place where a validator talks to the network. Tests use a fake implementation, so no
 * real VIES call ever happens in CI (docs/testing.md).
 */
interface TransportInterface {

	/**
	 * POSTs a JSON body and returns the response, or null when no response was received
	 * (DNS failure, connection error, timeout).
	 *
	 * @param string               $url     Endpoint.
	 * @param array<string, mixed> $payload Data, encoded as JSON by the transport.
	 * @param int                  $timeout Seconds.
	 */
	public function post_json( string $url, array $payload, int $timeout ): ?TransportResponse;

	/**
	 * POSTs an XML body (SOAP 1.1) and returns the response, or null when no response was received.
	 *
	 * @param string $url         Endpoint.
	 * @param string $body        XML document.
	 * @param int    $timeout     Seconds.
	 * @param string $soap_action SOAPAction header value ('' when the service dispatches on the body, as VIES does).
	 */
	public function post_xml( string $url, string $body, int $timeout, string $soap_action = '' ): ?TransportResponse;

	/**
	 * GETs a JSON resource and returns the response, or null when no response was received.
	 * Redirects are not followed, so a request never reaches a host other than the documented one.
	 *
	 * @param string $url     Resource.
	 * @param int    $timeout Seconds.
	 * @param string $accept  Accept header (a versioned media type where the service offers one).
	 */
	public function get_json( string $url, int $timeout, string $accept = 'application/json' ): ?TransportResponse;
}
