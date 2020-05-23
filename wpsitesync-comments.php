<?php
/*
Plugin Name: WPSiteSync for Comments
Plugin URI: http://wpsitesync.com
Description: Provides features for synchronizing Comments data attached to a post/page between two WordPress sites.
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.0
Text Domain: wpsitesync-comments
Domain path: /language

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

// this is only needed for systems that the .htaccess won't work on
defined('ABSPATH') or (header('Forbidden', TRUE, 403) || die('Restricted'));

if (!class_exists('WPSiteSync_Comments', FALSE)) {
	class WPSiteSync_Comments
	{
		private static $_instance = NULL;

		const PLUGIN_NAME = 'WPSiteSync for Comments';
		const PLUGIN_VERSION = '1.0';
		const PLUGIN_KEY = '308ccc03463de8dd7ffd9642083b05b4'; // '3139b83e5307e700b9253259d0fe8198';

		private function __construct()
		{
			add_action('spectrom_sync_init', array(&$this, 'init'));
			add_action('wp_loaded', array($this, 'wp_loaded'));
		}

		/**
		 * Create singleton instance of the WPSiteSync_Comments class
		 * @return WPSiteSync_Comments instance
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		public function init()
		{
SyncDebug::log(__METHOD__.'()');
			add_filter('spectrom_sync_active_extensions', array(&$this, 'filter_active_extensions'), 10, 2);

			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_comments', self::PLUGIN_KEY, self::PLUGIN_NAME)) {
SyncDebug::log(__METHOD__ . '() no license');
				return;
			}

			// TODO: move into 'spectrom_sync_api_init' callback
			add_filter('spectrom_sync_api_request', array(&$this, 'add_comment_data'), 10, 3);
			add_action('spectrom_sync_api_process', array(&$this, 'handle_comment_data'), 10, 3);
		}

		/**
		 * Called when WP is loaded so we can check if parent plugin is active.
		 */
		public function wp_loaded()
		{
			if (is_admin() && !class_exists('WPSiteSyncContent', FALSE) && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_requires_wpss'));
				add_action('admin_init', array($this, 'disable_plugin'));
			}
		}

		/**
		 * Displays the warning message stating that WPSiteSync is not present.
		 */
		public function notice_requires_wpss()
		{
			$install = admin_url('plugin-install.php?tab=search&s=wpsitesync');
			$activate = admin_url('plugins.php');
			$msg = sprintf(__('The <em>WPSiteSync for Comments</em> plugin requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please %1$sclick here</a> to install or %2$sclick here</a> to activate.', 'wpsitesync-comments'),
						'<a href="' . $install . '">',
						'<a href="' . $activate . '">');
			$this->_show_notice($msg, 'notice-warning');
		}

		/**
		 * Disables the plugin if WPSiteSync not installed or ACF is too old
		 */
		public function disable_plugin()
		{
			deactivate_plugins(plugin_basename(__FILE__));
		}

		/**
		 * Helper method to display notices
		 * @param string $msg Message to display within notice
		 * @param string $class The CSS class used on the <div> wrapping the notice
		 * @param boolean $dismissable TRUE if message is to be dismissable; otherwise FALSE.
		 */
		private function _show_notice($msg, $class = 'notice-success', $dismissable = FALSE)
		{
			echo '<div class="notice ', $class, ' ', ($dismissable ? 'is-dismissible' : ''), '">';
			echo '<p>', $msg, '</p>';
			echo '</div>';
		}

		/**
		 * Adds the Comment information associated with the Content being Sync'd
		 * @param array $data The data array being build for sending to the Target system
		 * @param string $action The SYNC API action, 'push' for example
		 * @param array $request_args The request arguments sent to the SyncApiRequest::api() method
		 * @return array The modified data array, with comment informaion added to it
		 */
		public function add_comment_data($data, $action, $request_args)
		{
SyncDebug::log(__METHOD__.'()');
			if ('push' === $action) {
SyncDebug::log(' - adding comment data');
				$comments = $this->_load_class('CommentData', TRUE);
				$data = $comments->add_data($data, $request_args);
			}
			return $data;
		}

		/**
		 * Handles the API after processing by SyncApiController. This is where the Comment data is sync'd
		 * @param string $action The API action, 'push' for example
		 * @param SyncApiResponse $response The response object
		 */
		public function handle_comment_data($action, $response, $controller)
		{
SyncDebug::log(__METHOD__."('{$action}') writing comment data");
			if ('push' === $action) {
SyncDebug::log(__METHOD__.'() handling push action- sync the comments');
				$write = $this->_load_class('CommentWrite', TRUE);
				$write->write_data($response, $controller);
			}
		}

		/**
		 * Loads a specified class file name and optionally creates an instance of it
		 * @param string $name Name of class to load
		 * @param boolean $create TRUE to create an instance of the loaded class
		 * @return object Created instance of $create is TRUE; otherwise FALSE
		 */
		private function _load_class($name, $create = FALSE)
		{
			$file = dirname(__FILE__) . '/classes/' . strtolower($name) . '.php';
			if (file_exists($file))
				require_once($file);
			if ($create) {
				$instance = 'Sync' . $name;
//SyncDebug::log(__METHOD__.'() creating instance of "' . $instance . '"');
				return new $instance();
			}
			return;
		}

		/**
		 * Add the Comments add-on to the list of known WPSiteSync extensions
		 * @param array $extensions The list to add to
		 * @param boolean $set
		 * @return array The list of extensions, with the WPSiteSync Comments add-on included
		 */
		public function filter_active_extensions($extensions, $set = FALSE)
		{
//SyncDebug::log(__METHOD__.'()');
			if ($set || WPSiteSyncContent::get_instance()->get_license()->check_license('sync_comments', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_comments'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
			return $extensions;
		}
	}
}

WPSiteSync_Comments::get_instance();

// EOF
