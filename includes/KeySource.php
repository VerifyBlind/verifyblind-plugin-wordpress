<?php
namespace VerifyBlind;

interface KeySource {
	/** Enclave result-signing public key (PEM). @throws \RuntimeException when unavailable. */
	public function enclave_key( bool $refresh = false ): string;

	/** VerifyBlind webhook-signing public key (PEM). @throws \RuntimeException when unavailable. */
	public function webhook_key( bool $refresh = false ): string;
}
