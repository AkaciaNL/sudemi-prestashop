<?php
/**
 * Collecting a claimed pairing from cron (SudemiPairing::pollInBackground): asks once per run
 * while a pairing is open, waits between asks (a minute, later twenty minutes, longer when a 429
 * says so), stores the install like "Check again" does, and gives up after the 24-hour lifetime
 * with one log line.
 *
 * Runs without PrestaShop: Configuration, SudemiClient, SudemiLogger and SudemiHeartbeat are
 * replaced by stand-ins.
 */

declare(strict_types=1);

define( '_PS_VERSION_', 'test' );

class Configuration {
	public static $values = array();

	public static function get( $key ) {
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : false;
	}

	public static function updateValue( $key, $value ) {
		self::$values[ $key ] = $value;
		return true;
	}

	public static function deleteByName( $key ) {
		unset( self::$values[ $key ] );
		return true;
	}
}

/** Records each unsigned call and answers with the queued response. */
class SudemiClient {
	const CONF_INSTALL_ID = 'SUDEMI_INSTALL_ID';
	public static $calls = array();
	public static $next  = null;

	public static function postUnsigned( $path, array $body ) {
		self::$calls[] = array( $path, $body );
		return self::$next;
	}
}

class SudemiLogger {
	public static $lines = array();

	public static function warn( $message ) {
		self::$lines[] = 'warn ' . $message;
	}

	public static function info( $message ) {
		self::$lines[] = 'info ' . $message;
	}
}

/** Counts the calls that drop a sync an earlier connection left in progress. */
class SudemiSync {
	public static $abandoned = 0;

	public static function abandonForeign() {
		self::$abandoned++;
	}
}

class SudemiHeartbeat {
	public static function cronToken() {
		return str_repeat( 'a', 32 );
	}
}

require __DIR__ . '/../classes/SudemiPairing.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

function open_pairing( int $age ): void {
	Configuration::$values = array(
		SudemiPairing::CONF_CHALLENGE => 'challenge-token-xxxxxxxxxxxx',
		SudemiPairing::CONF_CODE      => 'ABCD-1234',
		SudemiPairing::CONF_POLL      => 'poll-secret',
		SudemiPairing::CONF_CLAIM_URL => 'https://sudemi.com/seller/claim/ABCD-1234',
		SudemiPairing::CONF_STARTED   => time() - $age,
	);
	SudemiClient::$calls = array();
	SudemiLogger::$lines = array();
}

// Nothing started: no call at all.
Configuration::$values = array();
SudemiClient::$calls   = array();
check( 'no pairing open: nothing is asked', false === SudemiPairing::pollInBackground() && array() === SudemiClient::$calls );

// Open, not claimed yet: one ask, then the next run within the minute asks nothing.
open_pairing( 10 );
SudemiClient::$next = array( 'status' => 202, 'body' => array( 'status' => 'pending' ), 'retry_after' => 0 );
$first = SudemiPairing::pollInBackground();
check( 'open pairing: one poll with the code and the poll token',
	false === $first && 1 === count( SudemiClient::$calls )
	&& '/integration/v1/pair/poll' === SudemiClient::$calls[0][0]
	&& array( 'publicCode' => 'ABCD-1234', 'pollToken' => 'poll-secret' ) === SudemiClient::$calls[0][1] );
$next = (int) Configuration::get( SudemiPairing::CONF_NEXT_POLL );
check( 'the next background ask is a minute away', $next >= time() + 59 && $next <= time() + 61 );
SudemiPairing::pollInBackground();
check( 'a second run within the minute asks nothing', 1 === count( SudemiClient::$calls ) );
check( 'still pending', SudemiPairing::pending() );

// After the first half hour: every twenty minutes.
open_pairing( 2000 );
SudemiPairing::pollInBackground();
$next = (int) Configuration::get( SudemiPairing::CONF_NEXT_POLL );
check( 'after half an hour the next ask is twenty minutes away', 1 === count( SudemiClient::$calls ) && $next >= time() + 1199 );

// 429: wait as long as Retry-After says.
open_pairing( 10 );
SudemiClient::$next = array( 'status' => 429, 'body' => array( 'error' => 'too_many_requests' ), 'retry_after' => 600 );
SudemiPairing::pollInBackground();
check( 'a 429 holds the next ask for its Retry-After', (int) Configuration::get( SudemiPairing::CONF_NEXT_POLL ) >= time() + 599 && SudemiPairing::pending() );

// Claimed: stored exactly as the button does.
open_pairing( 10 );
SudemiClient::$next = array( 'status' => 200, 'body' => array( 'status' => 'ready', 'install_id' => 'inst-1' ), 'retry_after' => 0 );
$connected = SudemiPairing::pollInBackground();
check( 'claimed: connected, install id stored', true === $connected && 'inst-1' === Configuration::get( 'SUDEMI_INSTALL_ID' ) );
check( 'claimed: the challenge and the pairing are forgotten',
	false === Configuration::get( SudemiPairing::CONF_CHALLENGE ) && ! SudemiPairing::pending()
	&& false === Configuration::get( SudemiPairing::CONF_STARTED ) && false === Configuration::get( SudemiPairing::CONF_NEXT_POLL ) );
check( 'claimed: logged once', array( 'info Connected to sudemi.' ) === SudemiLogger::$lines );
check( 'claimed: a sync left by an earlier connection is dropped once', 1 === SudemiSync::$abandoned );
SudemiClient::$calls = array();
SudemiPairing::pollInBackground();
check( 'claimed: the next run asks nothing', array() === SudemiClient::$calls );

// Older than the 24-hour lifetime: given up without asking, one log line.
open_pairing( 86400 + 5 );
check( 'expired: not asked', false === SudemiPairing::pollInBackground() && array() === SudemiClient::$calls );
check( 'expired: pairing forgotten', ! SudemiPairing::pending() && false === Configuration::get( SudemiPairing::CONF_STARTED ) );
check( 'expired: one line in the log', 1 === count( SudemiLogger::$lines ) && 0 === strpos( SudemiLogger::$lines[0], 'warn The connection attempt expired after 24 hours' ) );
SudemiPairing::pollInBackground();
check( 'expired: the next run logs nothing more', 1 === count( SudemiLogger::$lines ) );

// 410 from sudemi: forgotten, as before.
open_pairing( 10 );
SudemiClient::$next = array( 'status' => 410, 'body' => array( 'error' => 'pairing_unavailable' ), 'retry_after' => 0 );
SudemiPairing::pollInBackground();
check( 'a 410 ends the pairing', ! SudemiPairing::pending() );

// Started by an older version (no start time): counted from now, and asked.
open_pairing( 10 );
unset( Configuration::$values[ SudemiPairing::CONF_STARTED ] );
SudemiClient::$next = array( 'status' => 202, 'body' => array( 'status' => 'pending' ), 'retry_after' => 0 );
SudemiPairing::pollInBackground();
check( 'no start time: asked, and the lifetime counts from now',
	1 === count( SudemiClient::$calls ) && abs( (int) Configuration::get( SudemiPairing::CONF_STARTED ) - time() ) <= 1 );

// The button (poll()) still asks whenever pressed, even inside the background wait.
open_pairing( 10 );
Configuration::$values[ SudemiPairing::CONF_NEXT_POLL ] = time() + 600;
SudemiPairing::poll();
check( '"Check again" asks even while the background poll waits', 1 === count( SudemiClient::$calls ) );

if ( $failures > 0 ) {
	echo "\n", $failures, " check(s) failed\n";
	exit( 1 );
}
echo "\nall pairing checks passed\n";
