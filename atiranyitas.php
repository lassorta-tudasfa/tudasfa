<?php
/**
 * Régi (Webgenerator) címek átirányítása az új oldalakra, 301-gyel (atiranyitasok.json).
 * Csak akkor lép közbe, ha a kért cím nem létezik az új oldalon (404).
 *  - {"regi":"/hu/elerhetosegunk", "uj":"/kapcsolat/"}            pontos egyezés
 *  - {"regi":"/hu/csoportjaink", "uj":"/#csoportok", "alatta":true} a cím és minden alatta lévő
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'template_redirect', function () {
	if ( ! is_404() ) { return; }
	$f = __DIR__ . '/atiranyitasok.json';
	if ( ! file_exists( $f ) ) { return; }
	$szabalyok = json_decode( file_get_contents( $f ), true );
	if ( ! is_array( $szabalyok ) ) { return; }
	$ut = strtolower( rawurldecode( (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) );
	$ut = '/' . trim( $ut, '/' );
	foreach ( $szabalyok as $r ) {
		$regi = '/' . trim( strtolower( $r['regi'] ), '/' );
		$talal = ( $ut === $regi ) || ( ! empty( $r['alatta'] ) && 0 === strpos( $ut . '/', $regi . '/' ) );
		if ( $talal ) {
			wp_safe_redirect( home_url( $r['uj'] ), 301, 'Tudasfa' );
			exit;
		}
	}
}, 1 );
