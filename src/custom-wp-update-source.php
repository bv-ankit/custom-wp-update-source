<?php
/* 
 * Plugin Name: Custom WP Update Source
 * Description: Redirects WordPress core, plugin, and theme updates and searches to a mirror when wp.org is not reachable.
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

		// Theme Updates
		add_filter('pre_set_site_transient_update_themes', array($this, 'custom_check_theme_updates'), 10, 2);

		// Override Plugins API Result
		add_filter('plugins_api_result', array($this, 'custom_override_plugins_api_result'), 10, 3);

		// Override Themes API Result
		add_filter('themes_api_result', array($this, 'custom_override_themes_api_result'), 10, 3);
	}

	public function custom_check_core_updates($transient, $transient_name) {
		if (empty($transient->updates)) {
			$response = $this->make_request('GET', '/core-update-check/');

			if ($response !== false) {
				$data = json_decode(wp_remote_retrieve_body($response));
				if ($data && !empty($data->updates)) {
					$transient->updates = $data->updates;
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
			if (!isset($transient->response[$plugin_file])) {
				$plugin_slug = dirname($plugin_file);
				if ($plugin_slug === '.') {
					$plugin_slug = basename($plugin_file, '.php');
				}
				$plugin_slugs[$plugin_file] = $plugin_slug;
			}
		}

		if (!empty($plugin_slugs)) {
			$response = $this->make_request('POST', '/plugin-info-bulk/', json_encode($plugin_slugs));

			if ($response !== false) {
				$plugin_info_bulk = json_decode(wp_remote_retrieve_body($response), true);
				foreach ($plugin_info_bulk as $plugin_file => $plugin_info) {
					if ($plugin_info && isset($plugin_info['new_version'])) {
						$transient->response[$plugin_file] = (object) $plugin_info;
					}
				}
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
			if (!isset($transient->response[$theme_slug])) {
				$theme_slugs[] = $theme_slug;
			}
		}

		if (!empty($theme_slugs)) {
			$response = $this->make_request('POST', '/theme-info-bulk/', json_encode($theme_slugs));

			if ($response !== false) {
				$theme_info_bulk = json_decode(wp_remote_retrieve_body($response), true);
				foreach ($theme_info_bulk as $theme_slug => $theme_info) {
					if ($theme_info && isset($theme_info['new_version'])) {
						$transient->response[$theme_slug] = $theme_info;
					}
				}
			}
		}

		return $transient;
	}

	public function custom_override_plugins_api_result($result, $action, $args) {
		if (is_wp_error($result) || empty($result->plugins)) {
			$custom_result = $this->custom_fetch_from_mirror('plugins', $action, $args);
			if ($custom_result) {
				return $custom_result;
			}
		}
		return $result;
	}

	public function custom_override_themes_api_result($result, $action, $args) {
		if (is_wp_error($result) || empty($result->themes)) {
			$custom_result = $this->custom_fetch_from_mirror('themes', $action, $args);
			if ($custom_result) {
				return $custom_result;
			}
		}
		return $result;
	}

	private function custom_fetch_from_mirror($type, $action, $args) {
		$url = $this->custom_mirror . "/{$type}-api/";
		$body = array(
			'action' => $action,
			'request' => serialize($args)
		);

		$response = $this->make_request('POST', "/{$type}-api/", $body);

		if ($response !== false) {
			$data = json_decode(wp_remote_retrieve_body($response));
			if ($data && !empty($data->{$type})) {
				return $data;
			}
		}

		return false;
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
