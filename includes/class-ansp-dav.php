<?php
/**
 * The WebDAV address a singer can mount.
 *
 * The Hub page is one tap per file. That is the right default and it needs no
 * extra app. But a singer with fifty scores does not want fifty taps, and the
 * whole point of the mirror is that the same music can be pulled in bulk and
 * then re-pulled later with only what changed coming down the wire. The worker
 * exposes a read-only WebDAV surface for exactly that; this class is the part
 * that tells a singer where it is.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It does not show the password. The credential is shared per group today, and
 * a shared secret rendered into a page is a shared secret in every browser
 * cache, every screenshot and every "can you send me that page" forward. The
 * page shows the address and the username; the password reaches singers the
 * way any other credential does, from a person. `show_password` exists as an
 * explicit opt-in for a future per-singer credential, where the page would be
 * showing a viewer only their own - and it defaults to off.
 *
 * THE GROUP NAME PROBLEM, AGAIN
 *
 * A WordPress group slug is not a mirror group id. Chamber Singers is `cs` in
 * WordPress and `chamber-singers` in the bucket, and v1.15.0 shipped a feature
 * that silently found nothing by assuming otherwise. So nothing here derives
 * one from the other. The mirror address is already stated per project - that
 * is what ANSP_Scores_Source::mirror_target() reads - and a tab's WebDAV
 * folder is whatever its own projects say it is. If a tab's projects disagree,
 * the panel points at the root of the tree and lets the credential decide what
 * is visible, which is true rather than clever.
 *
 * @package ArsNovaSingersPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebDAV addressing for the published mirror.
 */
class ANSP_Dav {

	/** Option holding everything this needs. */
	const OPTION = 'ansp_dav';

	/**
	 * Defaults, and the whole shape of the setting in one place.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'       => false,
			'base'          => '',
			'users'         => array(),
			'show_password' => false,
			'note'          => '',
		);
	}

	/**
	 * The stored settings, merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$out = array_merge( self::defaults(), $stored );

		$out['enabled']       = (bool) $out['enabled'];
		$out['show_password'] = (bool) $out['show_password'];
		$out['base']          = untrailingslashit( trim( (string) $out['base'] ) );
		$out['note']          = trim( (string) $out['note'] );
		$out['users']         = is_array( $out['users'] ) ? $out['users'] : array();

		return $out;
	}

	/**
	 * Write settings, merging field-by-field over what is already there.
	 *
	 * array_key_exists rather than isset: isset is false for null, so a caller
	 * clearing a value would silently no-op. That exact trap is recorded
	 * against save_materials() and is not worth repeating here.
	 *
	 * @param array<string,mixed> $changes Fields to change.
	 * @return array<string,mixed> The settings as they now stand.
	 */
	public static function update( $changes ) {
		$current = self::settings();
		if ( ! is_array( $changes ) ) {
			$changes = array();
		}

		foreach ( array( 'enabled', 'show_password' ) as $flag ) {
			if ( array_key_exists( $flag, $changes ) ) {
				$current[ $flag ] = (bool) filter_var( $changes[ $flag ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		if ( array_key_exists( 'base', $changes ) ) {
			$current['base'] = untrailingslashit( esc_url_raw( trim( (string) $changes['base'] ) ) );
		}
		if ( array_key_exists( 'note', $changes ) ) {
			$current['note'] = sanitize_textarea_field( (string) $changes['note'] );
		}
		if ( array_key_exists( 'users', $changes ) && is_array( $changes['users'] ) ) {
			$clean = array();
			foreach ( $changes['users'] as $group => $username ) {
				$group    = self::clean_group( (string) $group );
				$username = trim( (string) $username );
				if ( '' === $group ) {
					continue;
				}
				if ( '' === $username ) {
					continue; // An empty username removes the mapping.
				}
				$clean[ $group ] = $username;
			}
			$current['users'] = $clean;
		}

		update_option( self::OPTION, $current, false );
		return self::settings();
	}

	/**
	 * A mirror group id, kept exactly as the worker holds it.
	 *
	 * NOT sanitize_title(). "Full Group" must stay "Full Group" — running it
	 * through a slugger would make it "full-group" and match nothing, in
	 * silence, the first time anyone published for a non-Chamber ensemble.
	 * This mirrors ANSP_Scores_Source::clean_group() on purpose.
	 *
	 * @param string $group Raw group id.
	 * @return string Cleaned id, or '' if it could escape a path.
	 */
	public static function clean_group( $group ) {
		$group = trim( (string) $group );
		if ( false !== strpos( $group, '/' ) || false !== strpos( $group, '\\' ) ) {
			return '';
		}
		if ( '.' === $group || '..' === $group ) {
			return '';
		}
		return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $group ) );
	}

	/**
	 * The root of the WebDAV tree, without a trailing slash. '' when unusable.
	 *
	 * Derived from the worker URL rather than stored separately, because two
	 * fields naming the same host is two fields that can disagree. `base`
	 * overrides it for the case where the DAV surface is ever fronted by its
	 * own hostname.
	 *
	 * @return string
	 */
	public static function base_url() {
		$settings = self::settings();
		if ( '' !== $settings['base'] ) {
			return $settings['base'];
		}
		$worker = '';
		if ( class_exists( 'ANSP_Scores_Source' ) ) {
			$worker = ANSP_Scores_Source::worker_url();
		}
		if ( '' === $worker ) {
			return '';
		}
		return untrailingslashit( $worker ) . '/dav';
	}

	/**
	 * The address of one group's folder.
	 *
	 * Each path segment is encoded, but the slashes between them are not —
	 * rawurlencode() on the whole path would turn the separators into %2F and
	 * hand a client one long filename. Project folders here have spaces in
	 * them ("26-27 CS"), so this is not a theoretical distinction.
	 *
	 * @param string $group Mirror group id.
	 * @return string
	 */
	public static function url_for_group( $group ) {
		$base = self::base_url();
		if ( '' === $base ) {
			return '';
		}
		$group = self::clean_group( $group );
		if ( '' === $group ) {
			return trailingslashit( $base );
		}
		return trailingslashit( $base ) . rawurlencode( $group ) . '/';
	}

	/**
	 * The username configured for a group, or ''.
	 *
	 * @param string $group Mirror group id.
	 * @return string
	 */
	public static function username_for_group( $group ) {
		$settings = self::settings();
		$group    = self::clean_group( $group );
		if ( '' === $group ) {
			return '';
		}
		return isset( $settings['users'][ $group ] ) ? (string) $settings['users'][ $group ] : '';
	}

	/**
	 * Which mirror groups a set of projects actually live in.
	 *
	 * Read from the projects rather than from the WordPress group, for the
	 * reason in this file's header. Order is preserved and duplicates dropped,
	 * so a tab whose projects all point at one folder yields exactly one.
	 *
	 * @param WP_Post[]|int[] $projects Projects, or their ids.
	 * @return string[] Distinct mirror group ids.
	 */
	public static function groups_for_projects( $projects ) {
		if ( ! is_array( $projects ) || ! class_exists( 'ANSP_Scores_Source' ) ) {
			return array();
		}

		$found = array();
		foreach ( $projects as $project ) {
			$id = $project instanceof WP_Post ? (int) $project->ID : (int) $project;
			if ( ! $id ) {
				continue;
			}
			$target = ANSP_Scores_Source::mirror_target( $id );
			if ( empty( $target['groups'] ) || ! is_array( $target['groups'] ) ) {
				continue;
			}
			foreach ( $target['groups'] as $group ) {
				$group = self::clean_group( (string) $group );
				if ( '' !== $group && ! in_array( $group, $found, true ) ) {
					$found[] = $group;
				}
			}
		}
		return $found;
	}

	/**
	 * Everything a tab needs to render the panel, or null if it should not.
	 *
	 * Returns null rather than an empty shell whenever anything required is
	 * missing — no worker, switched off, or no username for this group. A
	 * panel showing an address nobody can log into is worse than no panel:
	 * it looks like a feature and behaves like a dead end.
	 *
	 * @param WP_Post[]|int[] $projects The projects this tab is showing.
	 * @return array<string,string>|null
	 */
	public static function panel_for( $projects ) {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return null;
		}

		$base = self::base_url();
		if ( '' === $base ) {
			return null;
		}

		$groups = self::groups_for_projects( $projects );

		/*
		 * One group: point at its folder. More than one, or none identified:
		 * point at the root and let the credential decide what is listed. The
		 * server already refuses a group the credential does not carry, so the
		 * root is never an over-share — it is just less specific.
		 */
		if ( 1 === count( $groups ) ) {
			$group = $groups[0];
			$url   = self::url_for_group( $group );
		} else {
			$group = '';
			$url   = trailingslashit( $base );
		}

		$username = '' !== $group ? self::username_for_group( $group ) : '';
		if ( '' === $username ) {
			// Fall back to a single configured credential when there is only
			// one, which is the shape of the pilot.
			if ( 1 === count( $settings['users'] ) ) {
				$username = (string) reset( $settings['users'] );
			}
		}
		if ( '' === $username ) {
			return null;
		}

		return array(
			'url'      => $url,
			'group'    => $group,
			'username' => $username,
			'note'     => $settings['note'],
		);
	}
}
