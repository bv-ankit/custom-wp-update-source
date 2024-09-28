<?php
/* 
 * Plugin Name: Custom WP Update Source
 * Description: Redirects WordPress core, plugin, and theme updates and searches to an mirror when wp.org is not reachable.
 * Version: 1.2
 * Author: Blogvault
 */

if (!defined('ABSPATH')) { exit; // Exit if accessed directly }

class Custom_WP_Update_Source {

	/**
	 * URL of the custom WordPress mirror.
	 * @var string
	 */
	private $custom_mirror = 'https://wp-mirror.blogvault.net';

	public function __construct() {
		// Core Updates
		add_filter('site_transient_update_core', array($this, 'custom_add_core_updates'), 10, 1);

		// Plugin Updates
		add_filter('site_transient_update_plugins', array($this, 'custom_add_plugin_updates'), 10, 1);

		// Theme Updates
		add_filter('site_transient_update_themes', array($this, 'custom_add_theme_updates'), 10, 1);

		// Override Plugins API Result
		add_filter('plugins_api_result', array($this, 'custom_override_plugins_api_result'), 10, 3);

		// Override Themes API Result
		add_filter('themes_api_result', array($this, 'custom_override_themes_api_result'), 10, 3);

		// Modify Package Download URLs
		add_filter('upgrader_package_options', array($this, 'custom_modify_package_options'), 10, 1);
	}

	public function custom_add_core_updates($transient) {
		if (empty($transient->updates)) {
			$response = wp_remote_get($this->custom_mirror . '/core-update-check/', array(
				'timeout'   => 15,
				'sslverify' => false,
			));

			if (!is_wp_error($response)) {
				$data = json_decode(wp_remote_retrieve_body($response));
				if ($data && !empty($data->updates)) {
					$transient->updates = $data->updates;
				}
			}
		}
		return $transient;
	}

	public function custom_add_plugin_updates($transient) {
		if (!is_object($transient)) {
			$transient = new stdClass();
		}

		if (!isset($transient->response)) {
			$transient->response = array();
		}

		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		foreach ($all_plugins as $plugin_file => $plugin_data) {
			if (!isset($transient->response[$plugin_file])) {
				$plugin_info = $this->custom_fetch_plugin_info($plugin_file);
				if ($plugin_info) {
					$transient->response[$plugin_file] = $plugin_info;
				}
			}
		}

		return $transient;
	}

	private function custom_fetch_plugin_info($plugin_file) {
		$plugin_slug = dirname($plugin_file);
		if ($plugin_slug === '.') {
			$plugin_slug = basename($plugin_file, '.php');
		}

		$response = wp_remote_get($this->custom_mirror . '/plugin-info/' . $plugin_slug, array(
			'timeout'   => 15,
			'sslverify' => false,
		));

		if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
			$plugin_info = json_decode(wp_remote_retrieve_body($response));
			if ($plugin_info && isset($plugin_info->new_version)) {
				return $plugin_info;
			}
		}

		return null;
	}

	public function custom_add_theme_updates($transient) {
		if (!is_object($transient)) {
			$transient = new stdClass();
		}

		if (!isset($transient->response)) {
			$transient->response = array();
		}

		$all_themes = wp_get_themes();

		foreach ($all_themes as $theme_slug => $theme) {
			if (!isset($transient->response[$theme_slug])) {
				$theme_info = $this->custom_fetch_theme_info($theme_slug);
				if ($theme_info) {
					$transient->response[$theme_slug] = $theme_info;
				}
			}
		}

		return $transient;
	}

	private function custom_fetch_theme_info($theme_slug) {
		$response = wp_remote_get($this->custom_mirror . '/theme-info/' . $theme_slug, array(
			'timeout'   => 15,
			'sslverify' => false,
		));

		if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
			$theme_info = json_decode(wp_remote_retrieve_body($response));
			if ($theme_info && isset($theme_info->new_version)) {
				return $theme_info;
			}
		}

		return null;
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

		$response = wp_remote_post($url, array(
			'body' => $body,
			'timeout' => 15,
			'sslverify' => false,
		));

		if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
			$data = json_decode(wp_remote_retrieve_body($response));
			if ($data && !empty($data->{$type})) {
				return $data;
			}
		}

		return false;
	}

	public function custom_modify_package_options($options) {
		if (isset($options['package'])) {
			$original_url = $options['package'];

			// Modify plugin download URLs
			$options['package'] = str_replace(
				'https://downloads.wordpress.org/plugin/',
				trailingslashit($this->custom_mirror) . 'plugins/',
				$options['package']
			);

			// Modify theme download URLs
			$options['package'] = str_replace(
				'https://downloads.wordpress.org/theme/',
				trailingslashit($this->custom_mirror) . 'themes/',
				$options['package']
			);

			// If the URL was changed, add it to the hook extra
			if ($original_url !== $options['package']) {
				$options['hook_extra']['custom_source'] = true;
			}
		}
		return $options;
	}

}

new Custom_WP_Update_Source();
