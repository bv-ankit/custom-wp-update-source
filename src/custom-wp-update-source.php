<?php
/* 
 * Plugin Name: Custom WP Update Source
 * Description: Redirects WordPress core, plugin, and theme updates to a mirror when wp.org is not reachable.
 * Version: 1.0
 * Author: Blogvault
 */

if (!defined('ABSPATH')) { exit; }

class Custom_WP_Update_Source {

	private $custom_mirror = 'https://wp-mirror.blogvault.net';

	public function __construct() {
		// Core Updates
		add_filter('pre_set_site_transient_update_core', array($this, 'custom_check_core_updates'), 10, 2);

		// Plugin Updates
		add_filter('pre_set_site_transient_update_plugins', array($this, 'custom_check_plugin_updates'), 10, 2);
		add_filter('site_transient_update_plugins', array($this, 'merge_plugin_updates'), 10, 2);

		// Theme Updates
		add_filter('pre_set_site_transient_update_themes', array($this, 'custom_check_theme_updates'), 10, 2);
		add_filter('site_transient_update_themes', array($this, 'merge_theme_updates'), 10, 2);
	}

	public function custom_check_core_updates($transient, $transient_name) {
		if (empty($transient->updates)) {
			$response = $this->make_request('GET', '/core-update-check/');

			if ($response !== false) {
				$data = json_decode(wp_remote_retrieve_body($response));
				if ($data && !empty($data->updates)) {
					$transient->updates = $data->updates;
					update_option('custom_mirror_core_updates', $data->updates);
				}
			}
		}
		return $transient;
	}

	public function custom_check_plugin_updates($transient, $transient_name) {
		if (!is_object($transient)) {
			$transient = new stdClass();
		}

		if (!isset($transient->response)) {
			$transient->response = array();
		}

		$all_plugins = get_plugins();
		$plugin_slugs = array();

		foreach ($all_plugins as $plugin_file => $plugin_data) {
			$plugin_slug = dirname($plugin_file);
			if ($plugin_slug === '.') {
				$plugin_slug = basename($plugin_file, '.php');
			}
			$plugin_slugs[$plugin_file] = $plugin_slug;
		}

		$response = $this->make_request('POST', '/plugin-info-bulk/', json_encode($plugin_slugs));

		if ($response !== false) {
			$plugin_info_bulk = json_decode(wp_remote_retrieve_body($response), true);
			update_option('custom_mirror_plugin_updates', $plugin_info_bulk);
			foreach ($plugin_info_bulk as $plugin_file => $plugin_info) {
				if ($plugin_info && isset($plugin_info['new_version'])) {
					$transient->response[$plugin_file] = (object) $plugin_info;
				}
			}
		}

		return $transient;
	}

	public function merge_plugin_updates($transient, $transient_name) {
		if (!is_object($transient)) {
			return $transient;
		}

		$mirror_updates = get_option('custom_mirror_plugin_updates', array());

		foreach ($mirror_updates as $plugin_file => $plugin_info) {
			if (!isset($transient->response[$plugin_file]) && isset($plugin_info['new_version'])) {
				$transient->response[$plugin_file] = (object) $plugin_info;
			}
		}

		return $transient;
	}

	public function custom_check_theme_updates($transient, $transient_name) {
		if (!is_object($transient)) {
			$transient = new stdClass();
		}

		if (!isset($transient->response)) {
			$transient->response = array();
		}

		$all_themes = wp_get_themes();
		$theme_slugs = array();

		foreach ($all_themes as $theme_slug => $theme) {
			$theme_slugs[] = $theme_slug;
		}

		$response = $this->make_request('POST', '/theme-info-bulk/', json_encode($theme_slugs));

		if ($response !== false) {
			$theme_info_bulk = json_decode(wp_remote_retrieve_body($response), true);
			update_option('custom_mirror_theme_updates', $theme_info_bulk);
			foreach ($theme_info_bulk as $theme_slug => $theme_info) {
				if ($theme_info && isset($theme_info['new_version'])) {
					$transient->response[$theme_slug] = $theme_info;
				}
			}
		}

		return $transient;
	}

	public function merge_theme_updates($transient, $transient_name) {
		if (!is_object($transient)) {
			return $transient;
		}

		$mirror_updates = get_option('custom_mirror_theme_updates', array());

		foreach ($mirror_updates as $theme_slug => $theme_info) {
			if (!isset($transient->response[$theme_slug]) && isset($theme_info['new_version'])) {
				$transient->response[$theme_slug] = $theme_info;
			}
		}

		return $transient;
	}

	private function make_request($method, $endpoint, $body = null) {
		$args = array(
			'method' => $method,
			'timeout' => 5,
			'sslverify' => true,
		);

		if ($body !== null) {
			$args['body'] = $body;
		}

		$response = wp_remote_request($this->custom_mirror . $endpoint, $args);

		if (is_wp_error($response) && strpos($response->get_error_message(), 'SSL certificate problem') !== false) {
			// If SSL fails, try without SSL verification
			$args['sslverify'] = false;
			$response = wp_remote_request($this->custom_mirror . $endpoint, $args);
		}

		if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
			return $response;
		}

		return false;
	}
}

new Custom_WP_Update_Source();
