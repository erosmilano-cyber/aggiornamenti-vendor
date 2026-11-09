<?php
/**
 * Plugin Name:       Aggiornamenti Vendor
 * Description:       Aggrega lato server advisory e aggiornamenti dei vendor (feed RSS, CSAF, errata AlmaLinux, CISA KEV) e li mostra con lo shortcode [aggiornamenti_vendor].
 * Version:           2.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/erosmilano-cyber/aggiornamenti-vendor
 * License:           GPL-2.0-or-later
 * Text Domain:       aggiornamenti-vendor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Aggiornamenti_Vendor {

	const VERSION      = 2;
	const OPT_SETTINGS = 'agv_settings';
	const OPT_DATA     = 'agv_data';
	const OPT_CSAF     = 'agv_csaf_cache';
	const CRON_HOOK    = 'agv_refresh_feeds';
	const LOCK         = 'agv_refresh_lock';
	const REPO         = 'erosmilano-cyber/aggiornamenti-vendor'; // repository pubblico di distribuzione, = "Update URI"
	const SLUG         = 'aggiornamenti-vendor';
	const UPD_CACHE    = 'agv_update_info';

	/**
	 * Fonti predefinite.
	 * Formato riga: Etichetta | URL dati | Pagina pubblica | tipo | modo | nota
	 *   tipo: rss (predefinito) | csaf | almalinux | kev
	 *   modo: titolo (mostra il titolo del vendor) | id (mostra solo gli identificativi dell'advisory)
	 *   nota: attribuzione mostrata nell'elenco fonti
	 */
	public static function default_sources() {
		return <<<'TXT'
## FONTI CON LICENZA APERTA O PERMESSO ESPLICITO (attive, modo titolo)
Red Hat | https://security.access.redhat.com/data/meta/v1/rhsa.rss | https://access.redhat.com/security/security-updates/ | rss | titolo | Dati © Red Hat, Inc., licenza CC BY 4.0 (creativecommons.org/licenses/by/4.0)
Debian | https://www.debian.org/security/dsa.rdf | https://www.debian.org/security/ | rss | titolo | Contenuti debian.org, licenza MIT (Expat) / GPL
Sophos | https://www.sophos.com/security-advisories/feed | https://www.sophos.com/en-us/security-advisories | rss | titolo | Contenuti del feed © Sophos Ltd. Sito non affiliato né approvato da Sophos.
CSIRT Italia | https://www.acn.gov.it/portale/feedrss/-/journal/rss/20119/723192 | https://www.acn.gov.it/portale/csirt-italia/alert-e-bollettini | rss | titolo | Fonte: ACN – CSIRT Italia (dati aperti, art. 52 c. 2 CAD)
CISA KEV | https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json | https://www.cisa.gov/known-exploited-vulnerabilities-catalog | kev | titolo | Fonte: CISA Known Exploited Vulnerabilities Catalog

## FONTI SENZA TERMINI SPECIFICI SUL RIUSO (attive, modo id: nessun testo del vendor, solo identificativo + link)
Cisco | https://sec.cloudapps.cisco.com/security/center/psirtrss20/CiscoSecurityAdvisory.xml | https://sec.cloudapps.cisco.com/security/center/publicationListing.x | rss | id |
HPE Aruba | https://csaf.arubanetworking.hpe.com/ | https://csaf.arubanetworking.hpe.com/ | csaf | id |
SUSE / openSUSE | https://lists.opensuse.org/archives/list/security-announce@lists.opensuse.org/feed/ | https://www.suse.com/support/update/ | rss | id |
AlmaLinux 8 | https://errata.almalinux.org/8/errata.full.json | https://errata.almalinux.org/ | almalinux | id |
AlmaLinux 9 | https://errata.almalinux.org/9/errata.full.json | https://errata.almalinux.org/ | almalinux | id |
AlmaLinux 10 | https://errata.almalinux.org/10/errata.full.json | https://errata.almalinux.org/ | almalinux | id |
Rocky Linux | https://errata.rockylinux.org/api/v2/advisories:rss | https://errata.rockylinux.org/ | rss | id |
Proxmox (security) | https://forum.proxmox.com/forums/security-advisories.26/index.rss | https://forum.proxmox.com/forums/security-advisories.26/ | rss | id |
Proxmox (release) | https://forum.proxmox.com/forums/announcements.7/index.rss | https://forum.proxmox.com/forums/announcements.7/ | rss | id |

## FONTI DISATTIVATE: i termini d'uso dei siti limitano l'uso a fini personali/non commerciali
## o vietano riproduzione/accesso automatizzato. Attivarle (togliendo "# ") solo con autorizzazione scritta del vendor.
# Microsoft | https://api.msrc.microsoft.com/update-guide/rss | https://msrc.microsoft.com/update-guide | rss | id |
# Ubuntu | https://ubuntu.com/security/notices/rss.xml | https://ubuntu.com/security/notices | rss | id |
# Fortinet | https://filestore.fortinet.com/fortiguard/rss/ir.xml | https://www.fortiguard.com/psirt | rss | id |
# Palo Alto Networks | https://security.paloaltonetworks.com/rss.xml | https://security.paloaltonetworks.com/ | rss | id |
# Ubiquiti | https://community.ui.com/rss/releases/Security/f3f45fa2-1784-4594-b48d-28baf317b208 | https://community.ui.com/releases | rss | id |
# Juniper | https://supportportal.juniper.net/knowledgerss?type=Security | https://supportportal.juniper.net/ | rss | id |
# SonicWall | https://psirtapi.global.sonicwall.com/api/v1/feed/rss.xml | https://psirt.global.sonicwall.com/ | rss | id |
# WatchGuard | https://www.watchguard.com/wgrd-psirt/advisories.xml | https://www.watchguard.com/wgrd-psirt/advisories | rss | id |
TXT;
	}

	public static function defaults() {
		return array(
			'version'        => self::VERSION,
			'sources'        => self::default_sources(),
			'max_per_source' => 15,
			'max_days'       => 30,
			'interval_hours' => 3,
			'disclaimer'     => "Pagina a scopo informativo, aggiornata automaticamente da fonti pubbliche. L'elenco non è esaustivo: fanno fede esclusivamente gli advisory originali, raggiungibili dai link. Marchi e nomi citati appartengono ai rispettivi titolari; questo sito non è affiliato ai vendor elencati.",
		);
	}

	public static function settings() {
		$s = get_option( self::OPT_SETTINGS, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function init() {
		add_shortcode( 'aggiornamenti_vendor', array( __CLASS__, 'shortcode' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_post_agv_refresh_now', array( __CLASS__, 'handle_refresh_now' ) );
		add_action( 'admin_post_agv_reset_sources', array( __CLASS__, 'handle_reset_sources' ) );
		add_action( 'admin_post_agv_check_update', array( __CLASS__, 'handle_check_update' ) );
		add_action( 'update_option_' . self::OPT_SETTINGS, array( __CLASS__, 'reschedule' ), 10, 0 );
		// Aggiornamenti del plugin dalle GitHub Release (host dell'header "Update URI").
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update_check' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_download' ), 10, 2 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
	}

	/** Aggiornamento da v1: sostituisce le fonti con quelle verificate della v2. */
	public static function maybe_upgrade() {
		$s = get_option( self::OPT_SETTINGS, null );
		if ( is_array( $s ) && ( empty( $s['version'] ) || (int) $s['version'] < self::VERSION ) ) {
			$s['version'] = self::VERSION;
			$s['sources'] = self::default_sources();
			update_option( self::OPT_SETTINGS, $s );
			delete_option( self::OPT_DATA );
		}
	}

	/* ---------------------------------------------------------------- Cron */

	public static function cron_schedules( $schedules ) {
		$h = max( 1, (int) self::settings()['interval_hours'] );
		$schedules['agv_interval'] = array(
			'interval' => $h * HOUR_IN_SECONDS,
			'display'  => sprintf( 'Ogni %d ore (Aggiornamenti Vendor)', $h ),
		);
		return $schedules;
	}

	public static function activate() {
		self::reschedule( 5 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function reschedule( $delay = 60 ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + (int) $delay, 'agv_interval', self::CRON_HOOK );
	}

	/* ------------------------------------------------------------- Fonti */

	public static function parse_sources( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( count( $p ) < 2 ) {
				continue;
			}
			$feed = esc_url_raw( $p[1], array( 'https' ) );
			if ( '' === $feed ) {
				continue;
			}
			$type = isset( $p[3] ) ? strtolower( $p[3] ) : 'rss';
			$mode = isset( $p[4] ) ? strtolower( $p[4] ) : 'titolo';
			$out[] = array(
				'label' => sanitize_text_field( $p[0] ),
				'feed'  => $feed,
				'home'  => isset( $p[2] ) ? esc_url_raw( $p[2], array( 'https', 'http' ) ) : '',
				'type'  => in_array( $type, array( 'rss', 'csaf', 'almalinux', 'kev' ), true ) ? $type : 'rss',
				'mode'  => 'id' === $mode ? 'id' : 'titolo',
				'note'  => isset( $p[5] ) ? sanitize_text_field( $p[5] ) : '',
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------ Parser */

	private static function http_get( $url, $timeout = 20 ) {
		$r = wp_remote_get( $url, array(
			'timeout'             => $timeout,
			'redirection'         => 3,
			'limit_response_size' => 32 * MB_IN_BYTES,
			'headers'             => array( 'Accept' => 'application/json, text/csv, */*' ),
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 200 !== $code ) {
			return new WP_Error( 'agv_http', 'HTTP ' . $code );
		}
		return (string) wp_remote_retrieve_body( $r );
	}

	private static function clean( $s, $len = 300 ) {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}

	private static function parse_rss( $src, $max ) {
		include_once ABSPATH . WPINC . '/feed.php';
		$feed = fetch_feed( $src['feed'] );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		$items = array();
		foreach ( $feed->get_items( 0, $max * 4 ) as $it ) {
			$items[] = array(
				'title' => self::clean( $it->get_title() ),
				'link'  => (string) $it->get_permalink(),
				'ts'    => (int) $it->get_date( 'U' ),
			);
		}
		return $items;
	}

	/** Errata AlmaLinux (errata.full.json): solo advisory di tipo security. */
	private static function parse_almalinux( $src, $max ) {
		if ( ! preg_match( '#errata\.almalinux\.org/(\d+)/#', $src['feed'], $m ) ) {
			return new WP_Error( 'agv_alma', 'URL AlmaLinux non riconosciuto' );
		}
		$ver = $m[1];
		wp_raise_memory_limit( 'agv' );
		$body = self::http_get( $src['feed'], 60 );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$json = json_decode( $body, true );
		unset( $body );
		$list = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : ( is_array( $json ) ? $json : null );
		unset( $json );
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'agv_alma', 'JSON AlmaLinux non valido' );
		}
		$items = array();
		foreach ( $list as $e ) {
			if ( ! is_array( $e ) || empty( $e['id'] ) || ( isset( $e['type'] ) && 'security' !== $e['type'] ) ) {
				continue;
			}
			$ts = self::alma_ts( $e['issued_date'] ?? ( $e['updated_date'] ?? 0 ) );
			$id = (string) $e['id'];
			$items[] = array(
				'title' => self::clean( $id . ': ' . ( $e['title'] ?? '' ) ),
				'link'  => sprintf( 'https://errata.almalinux.org/%s/%s.html', $ver, rawurlencode( str_replace( ':', '-', $id ) ) ),
				'ts'    => $ts,
			);
		}
		unset( $list );
		usort( $items, function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		} );
		return array_slice( $items, 0, $max * 4 );
	}

	private static function alma_ts( $v ) {
		if ( is_array( $v ) && isset( $v['$date'] ) ) {
			$v = $v['$date'];
		}
		if ( is_numeric( $v ) ) {
			$v = (int) $v;
			return $v > 100000000000 ? (int) ( $v / 1000 ) : $v; // millisecondi -> secondi
		}
		$t = strtotime( (string) $v );
		return $t ? $t : 0;
	}

	/** CISA Known Exploited Vulnerabilities. */
	private static function parse_kev( $src, $max ) {
		$body = self::http_get( $src['feed'], 30 );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$json = json_decode( $body, true );
		if ( empty( $json['vulnerabilities'] ) || ! is_array( $json['vulnerabilities'] ) ) {
			return new WP_Error( 'agv_kev', 'JSON KEV non valido' );
		}
		$items = array();
		foreach ( $json['vulnerabilities'] as $v ) {
			if ( empty( $v['cveID'] ) || ! preg_match( '/^CVE-\d{4}-\d+$/', $v['cveID'] ) ) {
				continue;
			}
			$items[] = array(
				'title' => self::clean( sprintf( '%s %s – %s (%s)', $v['vendorProject'] ?? '', $v['product'] ?? '', $v['vulnerabilityName'] ?? '', $v['cveID'] ) ),
				'link'  => 'https://nvd.nist.gov/vuln/detail/' . $v['cveID'],
				'ts'    => (int) strtotime( ( $v['dateAdded'] ?? '' ) . ' 00:00:00 UTC' ),
			);
		}
		usort( $items, function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		} );
		return array_slice( $items, 0, $max * 4 );
	}

	/**
	 * Provider CSAF 2.0 (changes.csv nella directory TLP:WHITE).
	 * I documenti già letti restano in cache: a ogni giro si scaricano solo i nuovi.
	 */
	private static function parse_csaf( $src, $max ) {
		$base = trailingslashit( $src['feed'] );
		$csv  = self::http_get( $base . 'changes.csv', 20 );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		$rows = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $csv ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$c = str_getcsv( $line );
			if ( count( $c ) < 2 || ! preg_match( '/\.json$/i', $c[0] ) || false !== strpos( $c[0], '..' ) ) {
				continue;
			}
			$rows[] = array( 'path' => ltrim( $c[0], '/' ), 'ts' => (int) strtotime( $c[1] ) );
		}
		usort( $rows, function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		} );
		$rows = array_slice( $rows, 0, $max );

		$cache   = get_option( self::OPT_CSAF, array() );
		$cache   = is_array( $cache ) ? $cache : array();
		$fetched = 0;
		$items   = array();
		foreach ( $rows as $r ) {
			$url = $base . $r['path'];
			$key = md5( $url . '|' . $r['ts'] );
			if ( ! isset( $cache[ $key ] ) ) {
				if ( $fetched >= 20 ) {
					continue; // limite per giro: il resto al prossimo aggiornamento
				}
				$fetched++;
				$doc = self::http_get( $url, 20 );
				if ( is_wp_error( $doc ) ) {
					continue;
				}
				$j = json_decode( $doc, true );
				if ( empty( $j['document']['title'] ) ) {
					continue;
				}
				$d    = $j['document'];
				$link = '';
				foreach ( (array) ( $d['references'] ?? array() ) as $ref ) {
					$u = $ref['url'] ?? '';
					if ( ! $u || preg_match( '/\.json$/i', $u ) ) {
						continue;
					}
					if ( 'self' === ( $ref['category'] ?? '' ) || false !== stripos( $ref['summary'] ?? '', 'original advisory' ) ) {
						$link = $u;
						break;
					}
				}
				$id            = $d['tracking']['id'] ?? '';
				$cache[ $key ] = array(
					'title' => self::clean( ( $id ? $id . ': ' : '' ) . $d['title'] ),
					'link'  => $link ? $link : $url,
					'ts'    => (int) strtotime( $d['tracking']['current_release_date'] ?? ( $d['tracking']['initial_release_date'] ?? '' ) ) ?: $r['ts'],
					'seen'  => time(),
				);
			}
			$items[] = $cache[ $key ];
		}
		// Cache limitata alle 500 voci più recenti.
		if ( count( $cache ) > 500 ) {
			uasort( $cache, function ( $a, $b ) {
				return $b['seen'] <=> $a['seen'];
			} );
			$cache = array_slice( $cache, 0, 500, true );
		}
		update_option( self::OPT_CSAF, $cache, false );
		return $items;
	}

	/** Estrae gli identificativi di advisory/CVE (fatti, non testo del vendor). */
	public static function extract_ids( $title, $link ) {
		$hay = $title . ' ' . rawurldecode( $link );
		$re  = '/\b(CVE-\d{4}-\d{4,}|RHSA-\d{4}:\d+|RLSA-\d{4}:\d+|ALSA-\d{4}:\d+|DSA-\d+(?:-\d+)?|DLA-\d+(?:-\d+)?|USN-\d+-\d+|SUSE-SU-\d{4}:\d+-\d+|openSUSE-SU-\d{4}:\d+-\d+|FG-IR-\d{2}-\d+|PAN-SA-\d{4}-\d+|JSA\d{4,}|cisco-sa-[A-Za-z0-9_-]+|SNWLID-\d{4}-\d+|WGSA-\d{4}-\d+|HPESBNW\d+|ARUBA-PSA-\d{4}-\d+|PSA-\d{4}-\d+(?:-\d+)?|Security Advisory Bulletin \d+)\b/i';
		if ( ! preg_match_all( $re, $hay, $m ) ) {
			return array();
		}
		$ids = array();
		foreach ( $m[1] as $id ) {
			$k = strtoupper( $id );
			if ( ! isset( $ids[ $k ] ) ) {
				$ids[ $k ] = $id;
			}
		}
		return array_slice( array_values( $ids ), 0, 3 );
	}

	/* ------------------------------------------------------- Aggregazione */

	public static function feed_cache_lifetime() {
		return max( 1, (int) self::settings()['interval_hours'] ) * HOUR_IN_SECONDS - 300;
	}

	public static function refresh() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );

		$s       = self::settings();
		$max     = max( 1, (int) $s['max_per_source'] );
		$cutoff  = time() - max( 1, (int) $s['max_days'] ) * DAY_IN_SECONDS;
		$prev    = get_option( self::OPT_DATA, array() );
		$prev_by = array();
		foreach ( (array) ( $prev['sources'] ?? array() ) as $p ) {
			$prev_by[ $p['feed'] ] = $p;
		}

		add_filter( 'wp_feed_cache_transient_lifetime', array( __CLASS__, 'feed_cache_lifetime' ) );

		$sources = array();
		foreach ( self::parse_sources( $s['sources'] ) as $src ) {
			switch ( $src['type'] ) {
				case 'almalinux':
					$raw = self::parse_almalinux( $src, $max );
					break;
				case 'kev':
					$raw = self::parse_kev( $src, $max );
					break;
				case 'csaf':
					$raw = self::parse_csaf( $src, $max );
					break;
				default:
					$raw = self::parse_rss( $src, $max );
			}

			$entry = $src + array( 'items' => array(), 'ok' => true, 'error' => '', 'last_ok' => time() );

			if ( is_wp_error( $raw ) ) {
				$entry['ok']      = false;
				$entry['error']   = $raw->get_error_message();
				$entry['items']   = $prev_by[ $src['feed'] ]['items'] ?? array();
				$entry['last_ok'] = $prev_by[ $src['feed'] ]['last_ok'] ?? 0;
			} else {
				usort( $raw, function ( $a, $b ) {
					return $b['ts'] <=> $a['ts'];
				} );
				foreach ( $raw as $it ) {
					$link = esc_url_raw( $it['link'], array( 'https', 'http' ) );
					if ( '' === $it['title'] || '' === $link || ( $it['ts'] && $it['ts'] < $cutoff ) ) {
						continue;
					}
					$entry['items'][] = array(
						'title' => $it['title'],
						'ids'   => self::extract_ids( $it['title'], $link ),
						'link'  => $link,
						'ts'    => (int) $it['ts'],
					);
					if ( count( $entry['items'] ) >= $max ) {
						break;
					}
				}
			}
			$sources[] = $entry;
		}

		remove_filter( 'wp_feed_cache_transient_lifetime', array( __CLASS__, 'feed_cache_lifetime' ) );

		update_option( self::OPT_DATA, array( 'updated' => time(), 'sources' => $sources ), false );
		delete_transient( self::LOCK );
	}

	/* ----------------------------------------------------------- Frontend */

	/** Testo visualizzato: titolo del vendor o, in modo "id", solo identificativi. */
	public static function display_text( $src, $it ) {
		if ( 'id' !== $src['mode'] ) {
			return $it['title'];
		}
		$ids = $it['ids'] ?? array();
		return $ids ? 'Advisory ' . implode( ', ', $ids ) : 'Nuovo advisory ' . $src['label'];
	}

	/** Colori delle fonti: ben distinti tra loro e leggibili su sfondo chiaro e scuro. */
	const PALETTE = array( '#2563eb', '#16a34a', '#d97706', '#9333ea', '#0891b2', '#db2777', '#4f46e5', '#65a30d', '#ea580c', '#0d9488', '#7c3aed', '#64748b', '#b45309', '#e11d48' );

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'limite' => 200 ), $atts, 'aggiornamenti_vendor' );
		$data = get_option( self::OPT_DATA, array() );
		$s    = self::settings();

		if ( empty( $data['sources'] ) ) {
			return '<p class="agv-empty">Elenco in preparazione: le fonti verranno caricate al prossimo aggiornamento automatico.</p>';
		}

		$items  = array();
		$labels = array();
		foreach ( $data['sources'] as $src ) {
			$labels[ $src['label'] ] = true;
			foreach ( $src['items'] as $it ) {
				$items[] = array(
					'vendor' => $src['label'],
					'text'   => self::display_text( $src, $it ),
					'link'   => $it['link'],
					'ts'     => $it['ts'],
				);
			}
		}
		usort( $items, function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		} );
		$items = array_slice( $items, 0, max( 1, (int) $atts['limite'] ) );

		$fmt_date = get_option( 'date_format' );
		$fmt_full = $fmt_date . ' ' . get_option( 'time_format' );
		$uid      = 'agv-' . wp_unique_id();
		$now      = time();
		$today    = wp_date( 'Y-m-d', $now );
		$yday     = wp_date( 'Y-m-d', $now - DAY_IN_SECONDS );

		// Conteggio per fonte (solo fonti con avvisi nel periodo) e raggruppamento per giorno.
		$counts = array();
		$groups = array();
		foreach ( $items as $it ) {
			$counts[ $it['vendor'] ] = ( $counts[ $it['vendor'] ] ?? 0 ) + 1;
			$key = $it['ts'] ? wp_date( 'Y-m-d', $it['ts'] ) : '';
			if ( ! isset( $groups[ $key ] ) ) {
				if ( '' === $key ) {
					$label = 'Data non disponibile';
					$sub   = '';
				} elseif ( $today === $key ) {
					$label = 'Oggi';
					$sub   = wp_date( 'j F Y', $it['ts'] );
				} elseif ( $yday === $key ) {
					$label = 'Ieri';
					$sub   = wp_date( 'j F Y', $it['ts'] );
				} else {
					$label = wp_date( 'l j F', $it['ts'] );
					$sub   = wp_date( 'Y', $it['ts'] );
				}
				$groups[ $key ] = array( 'label' => $label, 'sub' => $sub, 'items' => array() );
			}
			$groups[ $key ]['items'][] = $it;
		}
		$total = count( $items );
		// Colore per fonte nell'ordine dell'elenco fonti: stabile finché l'elenco non cambia.
		$color = array();
		foreach ( array_keys( $labels ) as $i => $l ) {
			$color[ $l ] = self::PALETTE[ $i % count( self::PALETTE ) ];
		}

		ob_start();
		?>
		<div class="agv" id="<?php echo esc_attr( $uid ); ?>">
			<style>
				.agv{--agv-acc:#2563eb;--agv-line:color-mix(in srgb,currentColor 13%,transparent);--agv-soft:color-mix(in srgb,currentColor 4%,transparent);--agv-muted:color-mix(in srgb,currentColor 64%,transparent);font-size:.95rem;line-height:1.5}
				.agv *{box-sizing:border-box}
				.agv-meta{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem .6rem;margin:0 0 1rem;color:var(--agv-muted);font-size:.85rem}
				.agv-live{width:.5rem;height:.5rem;border-radius:50%;background:#16a34a;box-shadow:0 0 0 3px color-mix(in srgb,#16a34a 22%,transparent)}
				.agv-filters{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 1.25rem}
				.agv-filters button{display:inline-flex;align-items:center;gap:.45rem;margin:0;border:1px solid var(--agv-line);background:var(--agv-soft);color:inherit;border-radius:999px;padding:.4rem .85rem;font:inherit;font-size:.85rem;line-height:1.2;cursor:pointer;transition:background-color .15s,border-color .15s,color .15s}
				.agv-filters button:hover{border-color:color-mix(in srgb,currentColor 40%,transparent)}
				.agv-filters button[aria-pressed="true"]{background:var(--agv-acc);border-color:var(--agv-acc);color:#fff}
				.agv-filters button[aria-pressed="true"] .agv-dot{background:#fff}
				.agv-filters button:focus-visible,.agv-card:focus-visible{outline:2px solid var(--agv-acc);outline-offset:2px}
				.agv-dot{flex:none;width:.55rem;height:.55rem;border-radius:50%;background:var(--agv-c,#2563eb)}
				.agv-n{min-width:1.4em;padding:0 .35em;border-radius:999px;background:color-mix(in srgb,currentColor 12%,transparent);font-size:.75rem;font-variant-numeric:tabular-nums;text-align:center}
				.agv-day{display:flex;flex-wrap:wrap;align-items:baseline;gap:.5rem;margin:1.5rem 0 .6rem;padding:0;font-size:.78rem;font-weight:700;letter-spacing:.06em;line-height:1.3;text-transform:uppercase;color:var(--agv-muted)}
				.agv-day span{font-weight:400;letter-spacing:0;text-transform:none}
				.agv-group:first-child .agv-day{margin-top:.25rem}
				.agv-list ul{list-style:none;margin:0;padding:0;display:grid;gap:.55rem}
				.agv-list li{margin:0;padding:0}
				.agv-list li[hidden]{display:none}
				.agv-group[hidden]{display:none}
				.agv-card{position:relative;display:block;padding:.8rem 2.6rem .8rem 1rem;border:1px solid var(--agv-line);border-left:4px solid var(--agv-c,#2563eb);border-radius:.65rem;background:var(--agv-soft);color:inherit;text-decoration:none;transition:transform .15s,box-shadow .15s,border-color .15s}
				.agv-card:hover{transform:translateY(-1px);border-color:color-mix(in srgb,currentColor 25%,transparent);border-left-color:var(--agv-c,#2563eb);box-shadow:0 8px 20px -12px color-mix(in srgb,currentColor 45%,transparent)}
				.agv-top{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin:0 0 .3rem}
				.agv-vendor{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;font-weight:700;letter-spacing:.02em;color:var(--agv-muted)}
				.agv-new{padding:.05rem .45rem;border-radius:999px;background:#dc2626;color:#fff;font-size:.66rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
				.agv-title{display:block;font-weight:600;line-height:1.4;overflow-wrap:anywhere}
				.agv-card:hover .agv-title{text-decoration:underline;text-underline-offset:.15em}
				.agv-arrow{position:absolute;top:50%;right:1rem;transform:translateY(-50%);opacity:.35;transition:opacity .15s,transform .15s}
				.agv-card:hover .agv-arrow{opacity:.9;transform:translate(2px,-50%)}
				.agv-sr{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
				.agv-sources{margin-top:2rem;padding-top:1rem;border-top:1px solid var(--agv-line);font-size:.82rem;color:var(--agv-muted)}
				.agv-sources-title{margin:0 0 .5rem;font-size:.78rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
				.agv-sources ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:.4rem 1.5rem}
				.agv-sources li{margin:0}
				.agv-sources a{color:inherit;font-weight:600}
				.agv-warn{color:#b45309}
				.agv-disclaimer{margin:1rem 0 0;padding:.75rem 1rem;border-radius:.65rem;background:var(--agv-soft);font-size:.8rem;color:var(--agv-muted)}
				@media (max-width:640px){.agv-filters{flex-wrap:nowrap;overflow-x:auto;padding-bottom:.25rem;scrollbar-width:thin}.agv-filters button{flex:none}.agv-card{padding-right:2.2rem}.agv-arrow{right:.8rem}}
				@media (prefers-reduced-motion:reduce){.agv *{transition:none!important}.agv-card:hover{transform:none}}
			</style>

			<p class="agv-meta">
				<span class="agv-live" aria-hidden="true"></span>
				<span>Aggiornato il <?php echo esc_html( wp_date( $fmt_full, (int) $data['updated'] ) ); ?></span>
				<span aria-hidden="true">·</span>
				<span class="agv-count" aria-live="polite"><?php echo esc_html( sprintf( '%d %s', $total, 1 === $total ? 'avviso' : 'avvisi' ) ); ?></span>
			</p>

			<?php if ( empty( $items ) ) : ?>
				<p class="agv-empty">Nessun aggiornamento nel periodo considerato.</p>
			<?php else : ?>
				<div class="agv-filters" role="group" aria-label="Filtra per fonte">
					<button type="button" data-v="" aria-pressed="true">Tutti <span class="agv-n"><?php echo (int) $total; ?></span></button>
					<?php foreach ( $counts as $l => $n ) : ?>
						<button type="button" data-v="<?php echo esc_attr( $l ); ?>" aria-pressed="false" style="--agv-c:<?php echo esc_attr( $color[ $l ] ); ?>"><span class="agv-dot" aria-hidden="true"></span><?php echo esc_html( $l ); ?> <span class="agv-n"><?php echo (int) $n; ?></span></button>
					<?php endforeach; ?>
				</div>

				<div class="agv-list">
					<?php foreach ( $groups as $g ) : ?>
						<section class="agv-group">
							<h3 class="agv-day"><?php echo esc_html( $g['label'] ); ?><?php if ( '' !== $g['sub'] ) : ?> <span><?php echo esc_html( $g['sub'] ); ?></span><?php endif; ?></h3>
							<ul>
								<?php foreach ( $g['items'] as $it ) : ?>
									<li data-v="<?php echo esc_attr( $it['vendor'] ); ?>" style="--agv-c:<?php echo esc_attr( $color[ $it['vendor'] ] ); ?>">
										<a class="agv-card" href="<?php echo esc_url( $it['link'] ); ?>" target="_blank" rel="noopener noreferrer nofollow">
											<span class="agv-top">
												<span class="agv-vendor"><span class="agv-dot" aria-hidden="true"></span><?php echo esc_html( $it['vendor'] ); ?></span>
												<?php if ( $it['ts'] > $now - DAY_IN_SECONDS ) : ?>
													<span class="agv-new">Nuovo</span>
												<?php endif; ?>
											</span>
											<span class="agv-title"><?php echo esc_html( $it['text'] ); ?></span>
											<span class="agv-arrow" aria-hidden="true">↗</span>
											<span class="agv-sr">(si apre in una nuova scheda)</span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="agv-sources">
				<h3 class="agv-sources-title">Fonti e attribuzioni</h3>
				<ul>
					<?php foreach ( $data['sources'] as $src ) : ?>
						<li>
							<?php if ( $src['home'] ) : ?>
								<a href="<?php echo esc_url( $src['home'] ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $src['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $src['label'] ); ?>
							<?php endif; ?>
							<?php if ( '' !== $src['note'] ) : ?>
								— <?php echo esc_html( $src['note'] ); ?>
							<?php endif; ?>
							<?php if ( ! $src['ok'] ) : ?>
								<span class="agv-warn">— temporaneamente non raggiungibile<?php echo $src['last_ok'] ? esc_html( ', dati al ' . wp_date( $fmt_date, (int) $src['last_ok'] ) ) : ''; ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<?php if ( '' !== trim( $s['disclaimer'] ) ) : ?>
				<p class="agv-disclaimer"><?php echo esc_html( $s['disclaimer'] ); ?></p>
			<?php endif; ?>

			<script>
			(function(){
				var root=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
				if(!root)return;
				var btns=root.querySelectorAll('.agv-filters button'),rows=root.querySelectorAll('.agv-list li'),groups=root.querySelectorAll('.agv-group'),count=root.querySelector('.agv-count');
				btns.forEach(function(b){b.addEventListener('click',function(){
					var v=b.getAttribute('data-v'),n=0;
					btns.forEach(function(x){x.setAttribute('aria-pressed',x===b?'true':'false');});
					rows.forEach(function(r){r.hidden=!!v&&r.getAttribute('data-v')!==v;if(!r.hidden)n++;});
					groups.forEach(function(g){g.hidden=!g.querySelector('li:not([hidden])');});
					if(count)count.textContent=n+(n===1?' avviso':' avvisi')+(v?' · '+v:'');
				});});
			})();
			</script>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------- Aggiornamenti del plugin */

	/**
	 * Ultima GitHub Release del repository di distribuzione REPO (pubblico, nessun token).
	 * Ritorna array( version, package, checksum, notes, url, checked ) o WP_Error.
	 * Cache 1 ora (errori 15 minuti): la frequenza dei controlli la decide già WordPress.
	 * Gira solo in WP-Cron o nell'area admin.
	 */
	public static function latest_release( $force = false ) {
		$c = $force ? false : get_transient( self::UPD_CACHE );
		if ( is_array( $c ) ) {
			return isset( $c['error'] ) ? new WP_Error( 'agv_upd', $c['error'] ) : $c;
		}
		$info = self::fetch_release();
		if ( is_wp_error( $info ) ) {
			set_transient( self::UPD_CACHE, array( 'error' => $info->get_error_message(), 'checked' => time() ), 15 * MINUTE_IN_SECONDS );
		} else {
			set_transient( self::UPD_CACHE, $info, HOUR_IN_SECONDS );
		}
		return $info;
	}

	private static function fetch_release() {
		$body = self::http_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', 15 );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$j   = json_decode( $body, true );
		$tag = is_array( $j ) && isset( $j['tag_name'] ) ? (string) $j['tag_name'] : '';
		if ( ! preg_match( '/^v(\d+\.\d+\.\d+)$/', $tag, $m ) || ! empty( $j['draft'] ) || ! empty( $j['prerelease'] ) ) {
			return new WP_Error( 'agv_upd', 'Release non valida' );
		}
		// Gli URL non vengono presi dalla risposta: si costruiscono sul repository fissato e si controlla che esistano.
		$zip    = 'https://github.com/' . self::REPO . '/releases/download/' . $tag . '/' . self::SLUG . '-' . $m[1] . '.zip';
		$assets = array();
		foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
			if ( is_array( $a ) && isset( $a['browser_download_url'] ) ) {
				$assets[] = (string) $a['browser_download_url'];
			}
		}
		if ( ! in_array( $zip, $assets, true ) || ! in_array( $zip . '.sha256', $assets, true ) ) {
			return new WP_Error( 'agv_upd', 'Zip o checksum mancanti nella Release ' . $tag );
		}
		return array(
			'version'  => $m[1],
			'package'  => $zip,
			'checksum' => $zip . '.sha256',
			'notes'    => substr( (string) ( $j['body'] ?? '' ), 0, 20000 ),
			'url'      => 'https://github.com/' . self::REPO . '/releases/tag/' . $tag,
			'checked'  => time(),
		);
	}

	/** Filtro update_plugins_github.com: WordPress confronta la versione e propone l'aggiornamento. */
	public static function update_check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return $update;
		}
		$r = self::latest_release( self::is_manual_check() );
		if ( is_wp_error( $r ) ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'version'      => $r['version'],
			'url'          => $r['url'],
			'package'      => $r['package'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);
	}

	/**
	 * Verifica richiesta da una persona: pagina Bacheca → Aggiornamenti (dove WordPress
	 * ricontrolla al massimo una volta al minuto) o link "Verifica di nuovo".
	 */
	private static function is_manual_check() {
		// phpcs:ignore WordPress.Security.NonceVerification
		return ! empty( $_GET['force-check'] ) || ( isset( $GLOBALS['pagenow'] ) && 'update-core.php' === $GLOBALS['pagenow'] );
	}

	/** Pulsante "Verifica aggiornamenti ora": interroga GitHub e ricalcola gli aggiornamenti dei plugin. */
	public static function handle_check_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Permesso negato.' );
		}
		check_admin_referer( 'agv_check_update' );
		self::latest_release( true );
		delete_site_transient( 'update_plugins' );
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'options-general.php?page=agv&agv_upd=1' ) );
		exit;
	}

	/** Scarica lo zip della Release e lo accetta solo se lo SHA-256 coincide con il file .sha256 pubblicato. */
	public static function verify_download( $reply, $package ) {
		$prefix = 'https://github.com/' . self::REPO . '/releases/download/';
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, $prefix ) ) {
			return $reply;
		}
		$sum = self::http_get( $package . '.sha256', 15 );
		if ( is_wp_error( $sum ) || ! preg_match( '/^([a-f0-9]{64})\b/i', trim( $sum ), $m ) ) {
			return new WP_Error( 'agv_sha', 'Checksum SHA-256 non disponibile: aggiornamento annullato.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $package, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! hash_equals( strtolower( $m[1] ), (string) hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'agv_sha', 'Lo SHA-256 dello zip non corrisponde: aggiornamento annullato.' );
		}
		return $tmp;
	}

	/** Finestra "Visualizza dettagli": dati dalla Release, mai da wordpress.org (slug omonimi). */
	public static function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $res;
		}
		$r = self::latest_release();
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return (object) array(
			'name'          => 'Aggiornamenti Vendor',
			'slug'          => self::SLUG,
			'version'       => $r['version'],
			'homepage'      => $r['url'],
			'download_link' => $r['package'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'sections'      => array( 'changelog' => '<pre style="white-space:pre-wrap">' . esc_html( $r['notes'] ) . '</pre>' ),
		);
	}

	/* -------------------------------------------------------------- Admin */

	public static function admin_menu() {
		add_options_page( 'Aggiornamenti Vendor', 'Aggiornamenti Vendor', 'manage_options', 'agv', array( __CLASS__, 'admin_page' ) );
	}

	public static function register_settings() {
		register_setting( 'agv', self::OPT_SETTINGS, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			'default'           => self::defaults(),
		) );
	}

	public static function sanitize_settings( $in ) {
		$d = self::defaults();
		return array(
			'version'        => self::VERSION,
			'sources'        => isset( $in['sources'] ) ? sanitize_textarea_field( $in['sources'] ) : $d['sources'],
			'max_per_source' => isset( $in['max_per_source'] ) ? min( 100, max( 1, (int) $in['max_per_source'] ) ) : $d['max_per_source'],
			'max_days'       => isset( $in['max_days'] ) ? min( 365, max( 1, (int) $in['max_days'] ) ) : $d['max_days'],
			'interval_hours' => isset( $in['interval_hours'] ) ? min( 24, max( 1, (int) $in['interval_hours'] ) ) : $d['interval_hours'],
			'disclaimer'     => isset( $in['disclaimer'] ) ? sanitize_textarea_field( $in['disclaimer'] ) : $d['disclaimer'],
		);
	}

	public static function handle_refresh_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato.' );
		}
		check_admin_referer( 'agv_refresh_now' );
		delete_transient( self::LOCK );
		self::refresh();
		wp_safe_redirect( admin_url( 'options-general.php?page=agv&agv_done=1' ) );
		exit;
	}

	public static function handle_reset_sources() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato.' );
		}
		check_admin_referer( 'agv_reset_sources' );
		$s            = self::settings();
		$s['sources'] = self::default_sources();
		update_option( self::OPT_SETTINGS, $s );
		wp_safe_redirect( admin_url( 'options-general.php?page=agv&agv_reset=1' ) );
		exit;
	}

	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::settings();
		$data = get_option( self::OPT_DATA, array() );
		$next = wp_next_scheduled( self::CRON_HOOK );
		$upd  = get_transient( self::UPD_CACHE );
		?>
		<div class="wrap">
			<h1>Aggiornamenti Vendor</h1>
			<?php // phpcs:disable WordPress.Security.NonceVerification ?>
			<?php if ( isset( $_GET['agv_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Fonti aggiornate.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['agv_reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Fonti predefinite ripristinate.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['agv_upd'] ) ) : ?>
				<?php $wp_upd = get_site_transient( 'update_plugins' ); ?>
				<?php if ( is_object( $wp_upd ) && ! empty( $wp_upd->response[ plugin_basename( __FILE__ ) ] ) ) : ?>
					<div class="notice notice-warning is-dismissible"><p>Verifica completata: è disponibile la versione <?php echo esc_html( $wp_upd->response[ plugin_basename( __FILE__ ) ]->new_version ?? '' ); ?>. <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Vai a Plugin per aggiornare</a>.</p></div>
				<?php elseif ( is_array( $upd ) && isset( $upd['error'] ) ) : ?>
					<div class="notice notice-error is-dismissible"><p>Verifica non riuscita: <?php echo esc_html( $upd['error'] ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-success is-dismissible"><p>Verifica completata: il plugin è aggiornato.</p></div>
				<?php endif; ?>
			<?php endif; ?>
			<?php // phpcs:enable ?>
			<p>Inserisci nella pagina lo shortcode <code>[aggiornamenti_vendor]</code>.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'agv' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="agv-sources">Fonti</label></th>
						<td>
							<textarea id="agv-sources" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[sources]" rows="22" class="large-text code" style="white-space:pre;overflow-x:auto"><?php echo esc_textarea( $s['sources'] ); ?></textarea>
							<p class="description">
								Una fonte per riga: <code>Etichetta | URL dati (https) | Pagina pubblica | tipo | modo | nota</code>.<br>
								<strong>tipo</strong>: <code>rss</code>, <code>csaf</code> (URL della directory con changes.csv), <code>almalinux</code> (errata.full.json), <code>kev</code> (catalogo CISA).<br>
								<strong>modo</strong>: <code>titolo</code> mostra il titolo pubblicato dal vendor; <code>id</code> mostra solo identificativi (CVE, ID advisory) e link, senza testo del vendor.<br>
								Le righe che iniziano con <code>#</code> sono ignorate. Prima di attivare una fonte o passare a <code>titolo</code>, verifica i termini d'uso del vendor.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agv-max">Elementi per fonte</label></th>
						<td><input id="agv-max" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[max_per_source]" value="<?php echo esc_attr( $s['max_per_source'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="agv-days">Finestra (giorni)</label></th>
						<td><input id="agv-days" type="number" min="1" max="365" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[max_days]" value="<?php echo esc_attr( $s['max_days'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="agv-int">Aggiornamento ogni (ore)</label></th>
						<td><input id="agv-int" type="number" min="1" max="24" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[interval_hours]" value="<?php echo esc_attr( $s['interval_hours'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="agv-disc">Disclaimer</label></th>
						<td><textarea id="agv-disc" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[disclaimer]" rows="3" class="large-text"><?php echo esc_textarea( $s['disclaimer'] ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Stato fonti</h2>
			<p>
				Ultimo aggiornamento: <?php echo ! empty( $data['updated'] ) ? esc_html( wp_date( 'd/m/Y H:i', (int) $data['updated'] ) ) : '—'; ?>
				· Prossimo: <?php echo $next ? esc_html( wp_date( 'd/m/Y H:i', $next ) ) : 'non pianificato'; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5rem">
				<input type="hidden" name="action" value="agv_refresh_now">
				<?php wp_nonce_field( 'agv_refresh_now' ); ?>
				<?php submit_button( 'Aggiorna ora', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block" onsubmit="return confirm('Sostituire l\'elenco fonti con quello predefinito?');">
				<input type="hidden" name="action" value="agv_reset_sources">
				<?php wp_nonce_field( 'agv_reset_sources' ); ?>
				<?php submit_button( 'Ripristina fonti predefinite', 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( ! empty( $data['sources'] ) ) : ?>
				<table class="widefat striped" style="margin-top:1rem">
					<thead><tr><th>Fonte</th><th>Tipo / modo</th><th>Stato</th><th>Elementi</th><th>Errore</th></tr></thead>
					<tbody>
					<?php foreach ( $data['sources'] as $src ) : ?>
						<tr>
							<td><?php echo esc_html( $src['label'] ); ?><br><code style="font-size:11px"><?php echo esc_html( $src['feed'] ); ?></code></td>
							<td><?php echo esc_html( $src['type'] . ' / ' . $src['mode'] ); ?></td>
							<td><?php echo $src['ok'] ? '✅ OK' : '⚠️ Errore'; ?></td>
							<td><?php echo (int) count( $src['items'] ); ?></td>
							<td><?php echo esc_html( $src['error'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Aggiornamenti del plugin</h2>
			<p>
				Fonte: <code><?php echo esc_html( 'github.com/' . self::REPO ); ?></code> (GitHub Release, zip verificato con SHA-256).
				<?php if ( is_array( $upd ) && isset( $upd['error'] ) ) : ?>
					<br>⚠️ Ultima verifica <?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $upd['checked'] ) ); ?>: <?php echo esc_html( $upd['error'] ); ?>
				<?php elseif ( is_array( $upd ) ) : ?>
					<br>Ultima Release: <a href="<?php echo esc_url( $upd['url'] ); ?>"><?php echo esc_html( $upd['version'] ); ?></a>, verificata il <?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $upd['checked'] ) ); ?>.
				<?php else : ?>
					<br>Nessuna verifica nell'ultima ora.
				<?php endif; ?>
			</p>
			<?php if ( current_user_can( 'update_plugins' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="agv_check_update">
					<?php wp_nonce_field( 'agv_check_update' ); ?>
					<?php submit_button( 'Verifica aggiornamenti ora', 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'Aggiornamenti_Vendor', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Aggiornamenti_Vendor', 'deactivate' ) );
Aggiornamenti_Vendor::init();
