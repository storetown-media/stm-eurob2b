<?php
/**
 * VIES validator (EU + Northern Ireland).
 *
 * @package EuroB2B
 */

declare( strict_types=1 );

namespace EuroB2B\Modules\Tax\VatId;

use DateTimeImmutable;
use DateTimeZone;
use EuroB2B\Modules\Countries\CountryRegistry;
use EuroB2B\Modules\Tax\VatId\Transport\TransportInterface;
use EuroB2B\Modules\Tax\VatId\Transport\TransportResponse;

/**
 * Checks a VAT ID against the European Commission's VIES service: REST first, SOAP as fallback
 * (docs/country-matrix.md §5). Only `valid=false` means invalid; every fault, HTTP error or
 * transport failure maps to `unavailable` with the fault string as reason code, so a VIES outage
 * can never be mistaken for a non-existent number.
 *
 * This class makes no WordPress calls: it is unit-tested with a fake transport.
 */
final class ViesValidator implements VatIdValidatorInterface {

	public const ID       = 'vies';
	public const REST_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
	public const SOAP_URL = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';

	/**
	 * Northern Ireland (goods) is in VIES although the United Kingdom is not a member state.
	 */
	public const NORTHERN_IRELAND = 'XI';

	/**
	 * Fault strings documented in the VIES WSDL / REST error wrappers. All of them mean "ask again later".
	 */
	public const FAULTS = array( 'INVALID_INPUT', 'INVALID_REQUESTER_INFO', 'SERVICE_UNAVAILABLE', 'MS_UNAVAILABLE', 'TIMEOUT', 'VAT_BLOCKED', 'IP_BLOCKED', 'GLOBAL_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ_TIME', 'MS_MAX_CONCURRENT_REQ', 'MS_MAX_CONCURRENT_REQ_TIME' );

	public const REASON_TRANSPORT      = 'transport';
	public const REASON_NOT_REGISTERED = 'not_registered';
	public const REASON_UNEXPECTED     = 'unexpected_response';

	/**
	 * Transport.
	 *
	 * @var TransportInterface
	 */
	private TransportInterface $transport;

	/**
	 * Whether to try SOAP when REST gave no usable answer.
	 *
	 * @var bool
	 */
	private bool $soap_fallback;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Constructor.
	 *
	 * @param TransportInterface $transport     Transport.
	 * @param bool               $soap_fallback Try the SOAP endpoint when REST fails.
	 * @param int                $timeout       Seconds per request.
	 */
	public function __construct( TransportInterface $transport, bool $soap_fallback = true, int $timeout = 10 ) {
		$this->transport     = $transport;
		$this->soap_fallback = $soap_fallback;
		$this->timeout       = max( 1, $timeout );
	}

	/**
	 * Service id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * VIES covers the EU member states and Northern Ireland.
	 *
	 * @param string $prefix VAT prefix.
	 */
	public function supports( string $prefix ): bool {
		return in_array( strtoupper( $prefix ), self::prefixes(), true );
	}

	/**
	 * Prefixes VIES knows, derived from the country registry (member states as VAT prefixes, Greece as EL) plus XI.
	 *
	 * @return string[]
	 */
	public static function prefixes(): array {
		$prefixes   = array_map( static fn( string $iso ): string => CountryRegistry::iso_to_prefix( $iso ), CountryRegistry::EU_MEMBER_STATES );
		$prefixes[] = self::NORTHERN_IRELAND;
		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Performs the check.
	 *
	 * @param VatId      $vat_id    Number to validate.
	 * @param VatId|null $requester Shop's own VAT ID (consultation number), when VIES knows its prefix.
	 */
	public function validate( VatId $vat_id, ?VatId $requester = null ): ValidationResult {
		if ( null !== $requester && ! $this->supports( $requester->prefix ) ) {
			$requester = null;
		}

		$result = $this->via_rest( $vat_id, $requester );
		if ( null === $result && $this->soap_fallback ) {
			$result = $this->via_soap( $vat_id, $requester );
		}
		if ( null === $result ) {
			$result = $this->unavailable( $vat_id, $requester, self::REASON_TRANSPORT );
		}
		return $result;
	}

	/**
	 * REST call. Null when the service could not be reached at all (then SOAP is tried).
	 *
	 * @param VatId      $vat_id    Number.
	 * @param VatId|null $requester Requester.
	 */
	private function via_rest( VatId $vat_id, ?VatId $requester ): ?ValidationResult {
		$payload = array(
			'countryCode' => $vat_id->prefix,
			'vatNumber'   => $vat_id->number,
		);
		if ( null !== $requester ) {
			$payload['requesterMemberStateCode'] = $requester->prefix;
			$payload['requesterNumber']          = $requester->number;
		}
		$response = $this->transport->post_json( self::REST_URL, $payload, $this->timeout );
		if ( null === $response ) {
			return null;
		}
		return $this->interpret_rest( $response, $vat_id, $requester );
	}

	/**
	 * Interprets a REST answer.
	 *
	 * @param TransportResponse $response  Response.
	 * @param VatId             $vat_id    Number.
	 * @param VatId|null        $requester Requester.
	 */
	private function interpret_rest( TransportResponse $response, VatId $vat_id, ?VatId $requester ): ?ValidationResult {
		$data = json_decode( $response->body, true );
		$data = is_array( $data ) ? $data : array();

		// Error wrappers (HTTP 400/500) carry the fault string.
		if ( isset( $data['errorWrappers'] ) && is_array( $data['errorWrappers'] ) ) {
			foreach ( $data['errorWrappers'] as $wrapper ) {
				if ( is_array( $wrapper ) && isset( $wrapper['error'] ) && is_string( $wrapper['error'] ) ) {
					return $this->unavailable( $vat_id, $requester, self::fault_code( $wrapper['error'] ) );
				}
			}
		}
		if ( isset( $data['userError'] ) && is_string( $data['userError'] ) && ! in_array( $data['userError'], array( 'VALID', 'INVALID' ), true ) ) {
			return $this->unavailable( $vat_id, $requester, self::fault_code( $data['userError'] ) );
		}
		if ( $response->status >= 500 || ( $response->status >= 400 && ! array_key_exists( 'valid', $data ) ) ) {
			// A gateway/service error without a usable body: let SOAP try.
			return $response->status >= 500 ? null : $this->unavailable( $vat_id, $requester, 'http_' . $response->status );
		}
		if ( ! array_key_exists( 'valid', $data ) ) {
			return $this->unavailable( $vat_id, $requester, self::REASON_UNEXPECTED );
		}

		$valid = filter_var( $data['valid'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $valid ) {
			return $this->unavailable( $vat_id, $requester, self::REASON_UNEXPECTED );
		}
		return new ValidationResult(
			$vat_id,
			$valid ? ValidationStatus::VALID : ValidationStatus::INVALID,
			self::ID,
			self::now(),
			$valid ? null : self::REASON_NOT_REGISTERED,
			self::clean( $data['requestIdentifier'] ?? null ),
			null !== $requester ? $requester->canonical() : null,
			$valid ? self::clean( $data['name'] ?? null ) : null,
			$valid ? self::clean( $data['address'] ?? null ) : null
		);
	}

	/**
	 * SOAP call (checkVat / checkVatApprox). Null when unreachable.
	 *
	 * @param VatId      $vat_id    Number.
	 * @param VatId|null $requester Requester.
	 */
	private function via_soap( VatId $vat_id, ?VatId $requester ): ?ValidationResult {
		$response = $this->transport->post_xml( self::SOAP_URL, self::soap_envelope( $vat_id, $requester ), $this->timeout );
		if ( null === $response ) {
			return null;
		}
		return $this->interpret_soap( $response, $vat_id, $requester );
	}

	/**
	 * Builds the SOAP request (checkVatApprox when a requester is known, else checkVat).
	 *
	 * @param VatId      $vat_id    Number.
	 * @param VatId|null $requester Requester.
	 */
	public static function soap_envelope( VatId $vat_id, ?VatId $requester ): string {
		$country = htmlspecialchars( $vat_id->prefix, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$number  = htmlspecialchars( $vat_id->number, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		if ( null === $requester ) {
			$body = "<urn:checkVat><urn:countryCode>{$country}</urn:countryCode><urn:vatNumber>{$number}</urn:vatNumber></urn:checkVat>";
		} else {
			$rc   = htmlspecialchars( $requester->prefix, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
			$rn   = htmlspecialchars( $requester->number, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
			$body = "<urn:checkVatApprox><urn:countryCode>{$country}</urn:countryCode><urn:vatNumber>{$number}</urn:vatNumber><urn:requesterCountryCode>{$rc}</urn:requesterCountryCode><urn:requesterVatNumber>{$rn}</urn:requesterVatNumber></urn:checkVatApprox>";
		}
		return '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:ec.europa.eu:taxud:vies:services:checkVat:types"><soapenv:Header/><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
	}

	/**
	 * Interprets a SOAP answer (tag-based, namespace-agnostic).
	 *
	 * @param TransportResponse $response  Response.
	 * @param VatId             $vat_id    Number.
	 * @param VatId|null        $requester Requester.
	 */
	private function interpret_soap( TransportResponse $response, VatId $vat_id, ?VatId $requester ): ValidationResult {
		$fault = self::tag( $response->body, 'faultstring' );
		if ( null !== $fault ) {
			return $this->unavailable( $vat_id, $requester, self::fault_code( $fault ) );
		}
		$valid = self::tag( $response->body, 'valid' );
		if ( null === $valid ) {
			return $this->unavailable( $vat_id, $requester, $response->status >= 400 ? 'http_' . $response->status : self::REASON_UNEXPECTED );
		}
		$is_valid = 'true' === strtolower( trim( $valid ) );
		return new ValidationResult(
			$vat_id,
			$is_valid ? ValidationStatus::VALID : ValidationStatus::INVALID,
			self::ID,
			self::now(),
			$is_valid ? null : self::REASON_NOT_REGISTERED,
			self::clean( self::tag( $response->body, 'requestIdentifier' ) ),
			null !== $requester ? $requester->canonical() : null,
			$is_valid ? self::clean( self::tag( $response->body, 'name' ) ?? self::tag( $response->body, 'traderName' ) ) : null,
			$is_valid ? self::clean( self::tag( $response->body, 'address' ) ?? self::tag( $response->body, 'traderAddress' ) ) : null
		);
	}

	/**
	 * Unavailable result with a reason code.
	 *
	 * @param VatId      $vat_id    Number.
	 * @param VatId|null $requester Requester.
	 * @param string     $reason    Reason code (fault string or transport/http code).
	 */
	private function unavailable( VatId $vat_id, ?VatId $requester, string $reason ): ValidationResult {
		return new ValidationResult( $vat_id, ValidationStatus::UNAVAILABLE, self::ID, self::now(), $reason, null, null !== $requester ? $requester->canonical() : null );
	}

	/**
	 * Normalises a fault string to a stable reason code (known faults keep their name).
	 *
	 * @param string $fault Fault string from the service.
	 */
	public static function fault_code( string $fault ): string {
		$code = strtoupper( trim( (string) preg_replace( '/[^A-Za-z_]/', '_', trim( $fault ) ) ) );
		$code = trim( (string) preg_replace( '/_+/', '_', $code ), '_' );
		return in_array( $code, self::FAULTS, true ) ? $code : ( '' === $code ? self::REASON_UNEXPECTED : 'fault_' . strtolower( substr( $code, 0, 48 ) ) );
	}

	/**
	 * Text content of the first tag with the given local name, null when absent.
	 *
	 * @param string $xml  Document.
	 * @param string $name Local tag name.
	 */
	private static function tag( string $xml, string $name ): ?string {
		if ( 1 !== preg_match( '#<(?:[A-Za-z0-9_]+:)?' . preg_quote( $name, '#' ) . '(?:\s[^>]*)?>(.*?)</(?:[A-Za-z0-9_]+:)?' . preg_quote( $name, '#' ) . '>#s', $xml, $m ) ) {
			return null;
		}
		$value = (string) preg_replace( '/^\s*<!\[CDATA\[(.*)\]\]>\s*$/s', '$1', $m[1] );
		return html_entity_decode( trim( $value ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Trimmed string or null (VIES returns "---" for unknown names).
	 *
	 * @param mixed $value Raw value.
	 */
	private static function clean( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
		return '' === $value || '---' === $value ? null : substr( $value, 0, 255 );
	}

	/**
	 * Current UTC time.
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}
