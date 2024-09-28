<?php
/* 
 * Plugin Name: Custom WP Update Source
 * Description: Redirects WordPress core, plugin, and theme updates and searches to an mirror when wp.org is not reachable.
 * Version: 1.3
 * Author: Blogvault
 */

if (!defined('ABSPATH')) { exit; // Exit if accessed directly }

class Custom_WP_Update_Source {

	/**
	 * URL of the custom WordPress mirror.
	 * @var string
	 */
	private $custom_mirror = 'https://wp-mirror.blogvault.net';

	/**
	 * Option name for storing the last successful request time.
	 * @var string
	 */
	private $last_success_option = 'custom_wp_update_source_last_success';

	public function __construct() {
		// Core Updates
		add_filter('site_transient_update_core', array($this, 'custom_add_core_updates'), 10, 2);

		// Plugin Updates
		add_filter('site_transient_update_plugins', array($this, 'custom_add_plugin_updates'), 10, 2);

		// Theme Updates
		add_filter('site_transient_update_themes', array($this, 'custom_add_theme_updates'), 10, 2);

		// Override Plugins API Result
		add_filter('plugins_api_result', array($this, 'custom_override_plugins_api_result'), 10, 3);

		// Override Themes API Result
		add_filter('themes_api_result', array($this, 'custom_override_themes_api_result'), 10, 3);

		// Schedule deactivation check
		if (!wp_next_scheduled('custom_wp_update_source_check_deactivation')) {
			wp_schedule_event(time(), 'hourly', 'custom_wp_update_source_check_deactivation');
		}
		add_action('custom_wp_update_source_check_deactivation', array($this, 'check_and_deactivate_if_needed'));

		// Deactivation hook
		register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));
	}

	public function custom_add_core_updates($transient, $transient_name) {
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

	public function custom_add_plugin_updates($transient, $transient_name) {
		if (!function_exists('get_plugins')) {
			return $transient;
		}

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

	public function custom_add_theme_updates($transient, $transient_name) {
		if (!function_exists('wp_get_themes')) {
			return $transient;
		}

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
			update_option($this->last_success_option, time());
			return $response;
		}

		return false;
	}

	public function check_and_deactivate_if_needed() {
		$last_success = get_option($this->last_success_option, 0);
		$current_time = time();

		if ($current_time - $last_success > 48 * 60 * 60) { // 48 hours
			$this->deactivate_plugin();
		}
	}

	private function deactivate_plugin() {
		$plugin_file = plugin_basename(__FILE__);
		deactivate_plugins($plugin_file);
		wp_die('Custom WP Update Source plugin has been deactivated due to failed requests for more than 48 hours.');
	}

	public function on_deactivation() {
		wp_clear_scheduled_hook('custom_wp_update_source_check_deactivation');
		delete_option($this->last_success_option);
	}
}

new Custom_WP_Update_Source();
