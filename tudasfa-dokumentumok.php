<?php
/**
 * Plugin Name: Tudásfa dokumentumok
 * Description: Az óvoda dokumentumai (PDF) a Dokumentumok oldalon, link a láblécben, és célzott módosítások a többi oldalon (valtozasok.json). Magától frissül a GitHubról (lassorta-tudasfa/tudasfa): óránként ellenőriz, az új verziót azonnal telepíti és alkalmazza. A többi oldalon csak a kért, célzott módosításokat végzi.
 * Version: 1.4.1
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TUDASFA_DOK_VER', '1.4.1' );
define( 'TUDASFA_DOK_REPO', 'https://github.com/lassorta-tudasfa/tudasfa/' );

/* ------------------------------------------------------------------ *
 * 1. Frissítéskezelő (Plugin Update Checker, GitHub main ág)
 * ------------------------------------------------------------------ */
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
require_once __DIR__ . '/valtozasok.php';
$GLOBALS['tudasfa_dok_puc'] = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( TUDASFA_DOK_REPO, __FILE__, 'tudasfa-dokumentumok', 1 );
$GLOBALS['tudasfa_dok_puc']->setBranch( 'main' );

// A WordPress saját automatikus frissítése is engedélyezve legyen erre a bővítményre.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) ? true : $update;
}, 10, 2 );

// Óránkénti ellenőrzés + azonnali telepítés.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'tudasfa_dok_orankent' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'tudasfa_dok_orankent' );
	}
} );
add_action( 'tudasfa_dok_orankent', 'tudasfa_dok_onfrissites' );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'tudasfa_dok_orankent' ); } );

function tudasfa_dok_onfrissites() {
	$puc = $GLOBALS['tudasfa_dok_puc'];
	$puc->checkForUpdates();
	$u = $puc->getUpdate();
	if ( ! $u || version_compare( $u->version, TUDASFA_DOK_VER, '<=' ) ) { return 'nincs'; }
	if ( get_transient( 'tudasfa_dok_frissit_zar' ) ) { return 'folyamatban'; }
	set_transient( 'tudasfa_dok_frissit_zar', 1, 10 * MINUTE_IN_SECONDS );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$res = $up->upgrade( plugin_basename( __FILE__ ) );
	delete_transient( 'tudasfa_dok_frissit_zar' );
	if ( ! is_plugin_active( plugin_basename( __FILE__ ) ) ) { activate_plugin( plugin_basename( __FILE__ ) ); }
	return ( true === $res ) ? 'frissitve:' . $u->version : 'hiba';
}

// Rejtett „azonnal frissíts” link adminoknak: /wp-admin/?tudasfa_frissit=1
add_action( 'admin_init', function () {
	if ( empty( $_GET['tudasfa_frissit'] ) || ! current_user_can( 'update_plugins' ) ) { return; }
	$r = tudasfa_dok_onfrissites();
	if ( 'nincs' === $r ) { tudasfa_dok_alkalmaz( true ); }
	set_transient( 'tudasfa_dok_uzenet', array( 'kezi' => $r ), 600 );
	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
}, 5 );

/* ------------------------------------------------------------------ *
 * 2. Alkalmazás: aktiváláskor és minden új verzió után egyszer
 * ------------------------------------------------------------------ */
register_activation_hook( __FILE__, function () { delete_option( 'tudasfa_dok_alkalmazva' ); } );

add_action( 'wp_loaded', function () {
	if ( get_option( 'tudasfa_dok_alkalmazva' ) === TUDASFA_DOK_VER ) { return; }
	tudasfa_dok_alkalmaz( false );
} );

function tudasfa_dok_alkalmaz( $kezi ) {
	if ( ! did_action( 'elementor/loaded' ) ) { return; }
	if ( get_transient( 'tudasfa_dok_alkalmaz_zar' ) ) { return; }
	set_transient( 'tudasfa_dok_alkalmaz_zar', 1, 5 * MINUTE_IN_SECONDS );
	$r = tudasfa_dok_telepit();
	delete_transient( 'tudasfa_dok_alkalmaz_zar' );
	if ( empty( $r['hiba'] ) ) { update_option( 'tudasfa_dok_alkalmazva', TUDASFA_DOK_VER, false ); }
	$r['ver'] = TUDASFA_DOK_VER;
	update_option( 'tudasfa_dok_naplo', array( 'ido' => current_time( 'mysql' ) ) + $r, false );
	if ( ! $kezi && is_admin() ) { set_transient( 'tudasfa_dok_uzenet', $r, 600 ); }
}

function tudasfa_dok_walk_footer( &$node ) {
	$changed = false;
	if ( is_array( $node ) ) {
		foreach ( $node as $k => &$v ) {
			if ( 'html' === $k && is_string( $v ) && false !== strpos( $v, 'tud-foot' ) && false === strpos( $v, '/dokumentumok/' ) ) {
				$new = str_replace( '<a href="/adatkezelesi-tajekoztato/">', '<a href="/dokumentumok/">Dokumentumok</a><a href="/adatkezelesi-tajekoztato/">', $v );
				if ( $new === $v ) { $new = str_replace( '</nav>', '<a href="/dokumentumok/">Dokumentumok</a></nav>', $v ); }
				if ( $new !== $v ) { $v = $new; $changed = true; }
			} elseif ( is_array( $v ) ) {
				if ( tudasfa_dok_walk_footer( $v ) ) { $changed = true; }
			}
		}
		unset( $v );
	}
	return $changed;
}

function tudasfa_dok_telepit() {
	$b = json_decode( file_get_contents( __DIR__ . '/dokumentumok.json' ), true );
	if ( ! is_array( $b ) ) { return array( 'hiba' => 'Az adatfájl nem olvasható.' ); }
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$ver    = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0';
	$saved  = get_option( 'tudasfa_dok_ids', array() );
	$hashes = get_option( 'tudasfa_dok_hash', array() );
	$urls   = array();

	// PDF-ek: csak az új vagy megváltozott fájlt tölti fel; a lecseréltet törli a médiatárból.
	foreach ( $b['docs'] as $key => $d ) {
		$src  = __DIR__ . '/docs/' . $d[0];
		$hash = md5_file( $src );
		$id   = isset( $saved[ $key ] ) ? (int) $saved[ $key ] : 0;
		$ok   = $id && get_post( $id ) && file_exists( (string) get_attached_file( $id ) );
		if ( $ok && isset( $hashes[ $key ] ) && $hashes[ $key ] !== $hash ) {
			wp_delete_attachment( $id, true );
			$ok = false;
		}
		if ( ! $ok ) {
			$tmp = wp_tempnam( $d[0] );
			copy( $src, $tmp );
			$id = media_handle_sideload( array( 'name' => $d[0], 'tmp_name' => $tmp ), 0, $d[1] );
			if ( is_wp_error( $id ) ) {
				@unlink( $tmp );
				return array( 'hiba' => 'Nem sikerült feltölteni: ' . $d[0] . ' (' . $id->get_error_message() . ')' );
			}
			$saved[ $key ] = $id;
		}
		$hashes[ $key ] = $hash;
		$urls[ '__DOC_' . $key . '__' ] = wp_make_link_relative( wp_get_attachment_url( $id ) );
	}
	update_option( 'tudasfa_dok_ids', $saved, false );
	update_option( 'tudasfa_dok_hash', $hashes, false );
	update_option( 'tudasfa_doc_urls', $urls, false );

	// Dokumentumok oldal.
	$up   = wp_upload_dir();
	$data = json_decode( strtr( wp_json_encode( $b['page']['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $urls + array( '__UPLOADS__' => untrailingslashit( $up['baseurl'] ) ) ), true );
	$ex   = get_page_by_path( 'dokumentumok', OBJECT, 'page' );
	$arr  = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Dokumentumok', 'post_name' => 'dokumentumok', 'post_content' => '' );
	if ( $ex ) { $arr['ID'] = $ex->ID; $pid = wp_update_post( $arr ); } else { $pid = wp_insert_post( $arr ); }
	if ( ! $pid || is_wp_error( $pid ) ) { return array( 'hiba' => 'Az oldalt nem sikerült létrehozni.' ); }
	update_post_meta( $pid, '_elementor_edit_mode', 'builder' );
	update_post_meta( $pid, '_elementor_template_type', 'wp-page' );
	update_post_meta( $pid, '_elementor_version', $ver );
	update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	update_post_meta( $pid, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );
	update_post_meta( $pid, '_wp_page_template', 'elementor_header_footer' );
	delete_post_meta( $pid, '_elementor_css' );

	// Link a láblécbe (csak ha még nincs benne).
	$footer_ok = false;
	$found     = get_posts( array( 'post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_tudasfa_tpl', 'meta_value' => 'footer', 'fields' => 'ids' ) );
	if ( $found ) {
		$fd = json_decode( (string) get_post_meta( $found[0], '_elementor_data', true ), true );
		if ( is_array( $fd ) ) {
			if ( tudasfa_dok_walk_footer( $fd ) ) {
				update_post_meta( $found[0], '_elementor_data', wp_slash( wp_json_encode( $fd ) ) );
				delete_post_meta( $found[0], '_elementor_css' );
			}
			$footer_ok = false !== strpos( wp_json_encode( $fd, JSON_UNESCAPED_SLASHES ), '/dokumentumok/' );
		}
	}

	// Stílus: a saját blokkját cseréli a Kiegészítő CSS végén.
	$css = (string) wp_get_custom_css();
	$css = preg_replace( '#\s*/\* TUDASFA-DOKUMENTUMOK START \*/.*?/\* TUDASFA-DOKUMENTUMOK END \*/#s', '', $css );
	wp_update_custom_css_post( rtrim( $css ) . "\n" . $b['css'] );

	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	$valt = tudasfa_valtozasok();
	tudasfa_valt_css();
	if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	return array( 'oldal' => $pid, 'lablec' => $footer_ok, 'db' => count( $urls ), 'valtozasok' => $valt );
}

/* ------------------------------------------------------------------ *
 * 3. Visszajelzés az adminban
 * ------------------------------------------------------------------ */
add_action( 'admin_notices', function () {
	$r = get_transient( 'tudasfa_dok_uzenet' );
	if ( ! $r ) { return; }
	delete_transient( 'tudasfa_dok_uzenet' );
	if ( isset( $r['kezi'] ) ) {
		$t = ( 0 === strpos( $r['kezi'], 'frissitve:' ) ) ? 'frissítve a ' . esc_html( substr( $r['kezi'], 10 ) ) . ' verzióra.' : ( 'nincs' === $r['kezi'] ? 'nincs újabb verzió, a mostani újra alkalmazva.' : 'a frissítés nem sikerült, óránként újrapróbálja.' );
		echo '<div class="notice notice-info"><p><strong>Tudásfa dokumentumok:</strong> ' . $t . '</p></div>';
		return;
	}
	if ( ! empty( $r['hiba'] ) ) {
		echo '<div class="notice notice-error"><p><strong>Tudásfa dokumentumok:</strong> ' . esc_html( $r['hiba'] ) . '</p></div>';
		return;
	}
	echo '<div class="notice notice-success"><p><strong>Tudásfa dokumentumok ' . esc_html( $r['ver'] ) . ':</strong> ' . intval( $r['db'] ) . ' PDF, Dokumentumok oldal ✓, ';
	echo $r['lablec'] ? 'lábléc link ✓. ' : 'a lábléc linket kézzel kell betenni. ';
	echo '<a href="' . esc_url( get_permalink( $r['oldal'] ) ) . '" target="_blank">Oldal megnyitása</a>. A bővítmény maradjon bekapcsolva: innentől magától frissül.</p></div>';
} );
