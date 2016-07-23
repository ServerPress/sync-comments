<?php

if (!class_exists('SyncCommentWrite', FALSE)) {
	class SyncCommentWrite extends SyncInput
	{
		private $_comments = NULL;
		protected $controller = NULL;					// saved copy of the SyncApiController instance
		private $_target_post_id = 0;					// the post ID of the content on the Target site

		/**
		 * Writes the Comment information on local system from data found in the $_POST array
		 * @param SyncApiResponse $response The response object, used to indicate any errors/warnings, etc.
		 * @param SyncApiController $controller A reference to the controller object
		 * @return type
		 */
		public function write_data(SyncApiResponse $response, SyncApiController $controller)
		{
			$this->_comments = $this->post_raw('comment_data', array());
			$this->controller = $controller;
			$this->_target_post_id = $controller->post_id;		// save the post id that is being updated
SyncDebug::log(__METHOD__.'() writing comment data to post #' . $this->_target_post_id);

			if (empty($this->_comments)) {
SyncDebug::log(__METHOD__.'() no comment data for this post, exiting');
				return;
			}

			$sync_model = new SyncModel();

//			add_filter('comments_clauses', array(&$this, 'filter_comment_clauses'), 10, 2);
			while (NULL !== ($comment = $this->_get_next_comment())) {
				$source_comment_id = $comment['comment_ID'];
SyncDebug::log(__METHOD__.'() processing source comment id #' . $source_comment_id . ': ' . $comment['comment_content']);
				$target_comment = $this->_lookup_comment($comment);

				// adjust the comment parent information
				$parent_comment = NULL;
				if (0 !== intval($comment['comment_parent'])) {
					$parent_data = $this->_find_comment_id($comment['comment_parent']); // _find_comment($comment['comment_date'], $comment['comment_author_email']);
SyncDebug::log(__METHOD__.'() looked up parent: ' . var_export($parent_data, TRUE));
					if (NULL !== $parent_data) {
						// find the parent comment on the local system
						$parent_comment = $this->_lookup_comment($parent_data);
						if (NULL === $parent_comment) {
SyncDebug::log(__METHOD__.'() ERROR: could not find parent comment ' . var_export($parent_data, TRUE));
							$comment['comment_parent'] = 0;
						} else {
SyncDebug::log(__METHOD__.'() looking up parent: ' . var_export($parent_comment, TRUE));
							$comment['comment_parent'] = intval($parent_comment['comment_ID']);
						}
					}
				}

				// TODO: handle the user_id values

				// search for existing comment, or add new
				if (NULL === $target_comment) {
SyncDebug::log(__METHOD__.'() no comment found - inserting');
					unset($comment['comment_ID']);							// remove these from being inserted
					unset($comment['user_id']);								// TODO: handle this later
					$comment['comment_post_ID'] = $this->_target_post_id;
					$target_comment_id = wp_insert_comment($comment);
				} else {
SyncDebug::log(__METHOD__.'() found a matching comment #' . $target_comment['comment_ID'] . ' - updating');
					$target_comment_id = intval($target_comment['comment_ID']);
					// mmove all data from the comment information obtained from API request into the current comment data
					// note: comment_post_id, comment_parent and user_id columns are skipped
					foreach (array('comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP',
						'comment_date', 'comment_date_gmt', 'comment_content', 'comment_karma', 'comment_approved',
						'comment_agent', 'comment_type') as $col) {
						$target_comment[$col] = $comment[$col];
					}
					if (NULL !== $parent_comment) {
SyncDebug::log(__METHOD__.'() setting parent comment to ' . $parent_comment['comment_ID']);
						$target_comment['comment_parent'] = $parent_comment['comment_ID'];
					}
SyncDebug::log(__METHOD__.'() calling wp_update_comment() ' . var_export($target_comment, TRUE));
					wp_update_comment($target_comment);
				}

				// set this comment as "handled"
				$this->_set_handled(intval($source_comment_id));
				// store the comment association in the database
				$sync_data = array(
					'site_key' => $controller->source_site_key,
					'source_content_id' => $source_comment_id,
					'target_content_id' => $target_comment_id,
					'content_type' => 'comment',
				);
				$sync_model->save_sync_data($sync_data);
			}
			// done with the filter, remove it
//			remove_filter('comments_clauses', array(&$this, 'filter_comment_clauses'), 10, 2);

			// remove any existing Comments that are not in the list provided via the API
			$args = array(
				'post_id' => $this->_target_post_id,
				'status' => array('hold', 'approve'),	// don't sync trashed/spam comments
				'orderby' => 'comment_date',
				'order' => 'ASC',
			);
			$query = new WP_Comment_Query($args);
			$comments = $query->get_comments();
			foreach ($comments as $comment) {
				$found = $this->_find_comment($comment->comment_date, $comment->comment_author_email, $comment->comment_author_IP);
				if (NULL === $found) {
					// comment on Target was not found in list of comments from Source- so let's remove it
					wp_delete_comment($comment->comment_ID, TRUE);
					// and remove SYNC's relationship as well
					$sync_model->remove_sync_data($comment->comment_ID, 'comment');
				}
			}
		}

		/**
		 * Returns the comment meta associated with the specified comment id
		 * @param string $id The ID of the comment
		 * @return array|NULL The array of the comment's meta data or NULL if the metadata is not present
		 */
		private function _find_meta($id)
		{
			$id = strval($id);
			if (isset($this->_comments['comment_meta'][$id]))
				return $this->_comments['comment_meta'][$id];
			return NULL;
		}

		/**
		 * Lookup a comment in the database with the email and datetime provided
		 * @param array $comment The array of comment information to use for searching
		 * @return array|NULL The comment information found or NULL if a matching comment was not found
		 */
		private function _lookup_comment($comment)
		{
			$ts = strtotime($comment['comment_date']);
			$args = array(
				'post_id' => $this->_target_post_id,
				'author_email' => $comment['comment_author_email'],
				// TODO: add comment_author_ip match as well
				'date_query' => array(
					'=' => array(
						'year' => date('Y', $ts),
						'month' => date('m', $ts),
						'day' => date('d', $ts),
						'hour' => date('H', $ts),
						'minute' => date('i', $ts),
						'second' => date('s', $ts),
					)
				),
			);
			$query = new WP_Comment_Query($args);
//SyncDebug::log(__METHOD__.'() query=' . var_export($query, TRUE));

			// if no matching comments found, return NULL
			if (0 === count($query->comments))
				return NULL;

SyncDebug::log(__METHOD__.'() found comment: ' . var_export($query->comments[0], TRUE));
			return get_object_vars($query->comments[0]);
		}

		/**
		 * Finds the next comment to be processed within the list of comments sent via the API.
		 * Uses recursion to find the parent-most comment first in order to have the correct parent ID set on the local system.
		 * @return array|NULL Returns the next comment to be processed or NULL if no more comments exist
		 */
		private function _get_next_comment($parent_id = 0)
		{
SyncDebug::log(__METHOD__.'(' . $parent_id . ')');
			$found = NULL;
			foreach ($this->_comments as $comment) {
				// skip any comments that have already been handled
				if (isset($comment['_spectrom_sync_handled']))
					continue;
				$found = $comment;			// set to the comment that was found
SyncDebug::log(__METHOD__.'() checking comment #' . $found['comment_ID']);

				// if requesting a specific parent, it this isn't the parent- keep looking
				if (0 !== $parent_id && $parent_id !== intval($comment['comment_ID']))
					continue;

				// if this has a parent comment, go find it
				if (0 !== intval($comment['comment_parent'])) {
					$parent_comment = $this->_get_next_comment($comment['comment_parent']);
					// if there was a parent found, return that
					if (NULL !== $parent_comment) {
						$found = $parent_comment;
SyncDebug::log(__METHOD__.'() found parent');
					} else {
SyncDebug::log(__METHOD__.'() no parent found');
					}
				}
				return $found;			// return the first comment found
			}

SyncDebug::log(__METHOD__.'() returning comment id #' . $found['comment_ID']);
			return $found;				// indicate no more comments
		}

		/**
		 * Sets the "handled" indicator in the comments array so _get_next_comment() skips it
		 * @param int $id The comment id to mark as having been handled
		 */
		private function _set_handled($id)
		{
			foreach ($this->_comments as &$comment) {
				if ($id == $comment['comment_ID']) {
SyncDebug::log(__METHOD__.'(' . $id . ')');
					$comment['_spectrom_sync_handled'] = 1;
					return;
				}
			}
SyncDebug::log(__METHOD__.'() id #' . $id . ' not found');
		}

		/**
		 * Finds a comment in the data provided via the API call with a matching date and email address
		 * @param string $date The date to match
		 * @param string $email The email to match
		 * @return array The comment information found that matches the date and email; otherwise NULL
		 */
		private function _find_comment($date, $email, $ip)
		{
			foreach ($this->_comments as $comment) {
				if ($date === $comment['comment_date'] && $email === $comment['comment_author_email'] &&
						$ip === $comment['comment_author_IP'])
					return $comment;
			}
			return NULL;
		}

		/**
		 * Lookup the comment based on it's id
		 * @param int $comment_id
		 * @return null
		 */
		private function _find_comment_id($comment_id)
		{
			foreach ($this->_comments as $comment) {
				// purposefully using == and not ===
				if ($comment_id == $comment['comment_ID'])
					return $comment;
			}
			return NULL;
		}

		/**
		 * Filters the comment query, adding the comment date to the WHERE clause
		 * @param array $clauses The array of SQL clauses for the query
		 * @param object $comment_query The WP_Comment_Query instance being created
		 * @return array The modified SQL clauses array
		 */
		public function filter_comment_clauses($clauses, $comment_query)
		{
//SyncDebug::log(__METHOD__.'() clauses: ' . var_export($clauses, TRUE));
//SyncDebug::log(__METHOD__.'() object: ' . var_export($comment_query, TRUE));
			if (isset($comment_query->query_vars['comment_date']))
				$clauses['where'] .= " AND `comment_date`='{$comment_query->query_vars['comment_date']}' ";
//SyncDebug::log(__METHOD__.'() modified clauses: ' . var_export($clauses, TRUE));
			return $clauses;
		}
	}
}

// EOF