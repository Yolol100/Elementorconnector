<?php

namespace Webactueel\ElementorJsonBridge\Support;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class BridgeException extends RuntimeException {
	public function __construct(
		private readonly string $error_code,
		string $message,
		private readonly int $http_status = 400,
		?Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function http_status(): int {
		return $this->http_status;
	}
}
