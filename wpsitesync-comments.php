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
		const PLUGIN_KEY = '3139b83e5307e700b9253259d0fe8198';

		private function __construct()
		{
			add_action('spectrom_sync_init', array(&$this, 'init'));
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
			add_filter('spectrom_sync_api_request', array(&$this, 'add_comment_data'), 10, 3);
			add_action('spectrom_sync_api_process', array(&$this, 'handle_comment_data'), 10, 3);
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
	}
}

WPSiteSync_Comments::get_instance();

// EOF
