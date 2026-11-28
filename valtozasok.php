<?php
/**
 * Célzott módosítások a többi oldalon (valtozasok.json).
 *
 * Minden módosításnak egyedi azonosítója van, és csak egyszer fut le. Amit nem talál
 * (mert közben kézzel átírták), azt nem erőlteti: kihagyja, és a naplóba írja.
 *
 * Műveletek:
 *  - {"id":"…","tipus":"szoveg","hol":"munkatarsaink","regi":"…","uj":"…"}
 *  - {"id":"…","tipus":"kep","hol":"munkatarsaink","regi":"vida-tunde.webp","uj":"media/vida-tunde.jpg","alt":"…"}
 *  - {"id":"…","tipus":"beallitas","hol":"rolunk","widget":"image","kepek":["napos-szoba"],"ertek":{"link_to":"file","open_lightbox":"yes"}}
 *  "hol": oldal slug, vagy "lablec" / "fejlec" (Elementor sablon), vagy "*" (minden oldal és sablon).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function tudasfa_valt_celok( $hol ) {
	$ids = array();
	if ( '*' === $hol || 'lablec' === $hol || 'fejlec' === $hol ) {
		$q = array( 'post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' );
		if ( '*' !== $hol ) { $q['meta_key'] = '_tudasfa_tpl'; $q['meta_value'] = ( 'lablec' === $hol ) ? 'footer' : 'header'; }
		$ids = get_posts( $q );
	}
	if ( '*' === $hol ) {
		$ids = array_merge( $ids, get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	} elseif ( 'lablec' !== $hol && 'fejlec' !== $hol ) {
		$p = get_page_by_path( $hol, OBJECT, 'page' );
		if ( $p ) { $ids[] = $p->ID; }
	}
	return $ids;
}

function tudasfa_valt_bejar( &$n, $fn ) {
	$c = 0;
	if ( is_array( $n ) ) {
		$c += $fn( $n );
		foreach ( $n as &$v ) { if ( is_array( $v ) ) { $c += tudasfa_valt_bejar( $v, $fn ); } }
		unset( $v );
	}
	return $c;
}

function tudasfa_valt_media( $rel, $alt ) {
	$src = __DIR__ . '/' . $rel;
	if ( ! file_exists( $src ) ) { return new WP_Error( 'nincs', 'Hiányzó fájl: ' . $rel ); }
	$tmp = wp_tempnam( basename( $rel ) );
	copy( $src, $tmp );
	$id = media_handle_sideload( array( 'name' => basename( $rel ), 'tmp_name' => $tmp ), 0 );
	if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
	if ( $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', $alt ); }
	return $id;
}

function tudasfa_valtozasok() {
	$f = __DIR__ . '/valtozasok.json';
	if ( ! file_exists( $f ) ) { return array(); }
	$lista = json_decode( file_get_contents( $f ), true );
	if ( ! is_array( $lista ) ) { return array( 'Hibás valtozasok.json' ); }
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$kesz  = get_option( 'tudasfa_valt_kesz', array() );
	$naplo = array();
	foreach ( $lista as $m ) {
		if ( empty( $m['id'] ) || isset( $kesz[ $m['id'] ] ) ) { continue; }
		$talalat = 0;
		$ujkep   = null;
		if ( 'kep' === $m['tipus'] ) {
			$ujkep = tudasfa_valt_media( $m['uj'], isset( $m['alt'] ) ? $m['alt'] : '' );
			if ( is_wp_error( $ujkep ) ) { $kesz[ $m['id'] ] = 'hiba: ' . $ujkep->get_error_message(); $naplo[] = $m['id'] . ': ' . $kesz[ $m['id'] ]; continue; }
			$ujurl = wp_get_attachment_url( $ujkep );
			$regi  = pathinfo( $m['regi'], PATHINFO_FILENAME );
		}
		foreach ( tudasfa_valt_celok( $m['hol'] ) as $pid ) {
			$d = json_decode( (string) get_post_meta( $pid, '_elementor_data', true ), true );
			if ( ! is_array( $d ) ) { continue; }
			if ( 'beallitas' === $m['tipus'] ) {
				$c = tudasfa_valt_bejar( $d, function ( &$n ) use ( $m ) {
					if ( ! isset( $n['elType'], $n['settings'] ) || 'widget' !== $n['elType'] ) { return 0; }
					if ( ! empty( $m['widget'] ) && ( ! isset( $n['widgetType'] ) || $n['widgetType'] !== $m['widget'] ) ) { return 0; }
					$url = isset( $n['settings']['image']['url'] ) ? $n['settings']['image']['url'] : '';
					if ( empty( $m['kepek'] ) || ! $url ) { return 0; }
					foreach ( $m['kepek'] as $k ) {
						if ( preg_match( '#/' . preg_quote( $k, '#' ) . '(-\d+x\d+|-scaled)?\.[a-z0-9]+$#i', $url ) ) {
							$n['settings'] = array_merge( $n['settings'], $m['ertek'] );
							return 1;
						}
					}
					return 0;
				} );
			} elseif ( 'szoveg' === $m['tipus'] ) {
				$c = tudasfa_valt_bejar( $d, function ( &$n ) use ( $m ) {
					$c = 0;
					foreach ( $n as $k => &$v ) {
						if ( is_string( $v ) && false !== strpos( $v, $m['regi'] ) ) { $v = str_replace( $m['regi'], $m['uj'], $v ); $c++; }
					}
					unset( $v );
					return $c;
				} );
			} else {
				$c = tudasfa_valt_bejar( $d, function ( &$n ) use ( $regi, $ujkep, $ujurl ) {
					$c = 0;
					if ( isset( $n['url'], $n['id'] ) && is_string( $n['url'] ) && preg_match( '#/' . preg_quote( $regi, '#' ) . '(-\d+x\d+|-scaled)?\.[a-z0-9]+$#i', $n['url'] ) ) {
						$n['url'] = $ujurl; $n['id'] = $ujkep; $c++;
					}
					foreach ( $n as $k => &$v ) {
						if ( is_string( $v ) && 'url' !== $k && preg_match( '#[^"\'\s()]*/' . preg_quote( $regi, '#' ) . '(-\d+x\d+|-scaled)?\.[a-z0-9]+#i', $v ) ) {
							$v = preg_replace( '#[^"\'\s()]*/' . preg_quote( $regi, '#' ) . '(-\d+x\d+|-scaled)?\.[a-z0-9]+#i', $ujurl, $v ); $c++;
						}
					}
					unset( $v );
					return $c;
				} );
			}
			if ( $c ) {
				update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $d ) ) );
				delete_post_meta( $pid, '_elementor_css' );
				$talalat += $c;
			}
		}
		$kesz[ $m['id'] ] = $talalat ? 'ok (' . $talalat . ')' : 'nem található';
		$naplo[]          = $m['id'] . ': ' . $kesz[ $m['id'] ];
	}
	update_option( 'tudasfa_valt_kesz', $kesz, false );
	return $naplo;
}

// Állapot lekérdezés (nyilvános, nem tartalmaz érzékeny adatot): /wp-json/tudasfa/v1/allapot
add_action( 'rest_api_init', function () {
	register_rest_route( 'tudasfa/v1', '/allapot', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			return array(
				'verzio'     => TUDASFA_DOK_VER,
				'alkalmazva' => get_option( 'tudasfa_dok_alkalmazva' ),
				'utolso'     => get_option( 'tudasfa_dok_naplo' ),
				'valtozasok' => get_option( 'tudasfa_valt_kesz', array() ),
			);
		},
	) );
} );

// Közös stílusjavítások (javitasok.css), minden verziónál a saját blokkját cseréli.
function tudasfa_valt_css() {
	$f = __DIR__ . '/javitasok.css';
	$css = (string) wp_get_custom_css();
	$css = preg_replace( '#\s*/\* TUDASFA-JAVITASOK START \*/.*?/\* TUDASFA-JAVITASOK END \*/#s', '', $css );
	if ( file_exists( $f ) ) { $css = rtrim( $css ) . "\n/* TUDASFA-JAVITASOK START */\n" . trim( file_get_contents( $f ) ) . "\n/* TUDASFA-JAVITASOK END */\n"; }
	wp_update_custom_css_post( $css );
}
