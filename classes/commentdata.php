<?php

class SyncCommentData
{
	/**
	 * Adds the comment information to the $data[] array of information being constructed for the 'post' API action
	 * @param array $data The data array being constructed for the API request
	 * @param array $request_args The request args passed to $api->api('push')
	 * @return array The modified $data array, with comment data added to it
	 */
	public function add_data($data, $request_args)
	{
		/**
		 * Builds the following elements into the $data array:
		 *  ['comment_data'] = an array of the comments associated with the content
		 *  ['comment_meta'] = an array of the comment's meta data
		 *  ['comment_meta'][{id}] => the comment id is the index to the comment meta data
		 */
		$post_id = intval($data['post_id']);
SyncDebug::log(__METHOD__.'() post #' . $post_id);
		if (0 !== $post_id) {
SyncDebug::log(__METHOD__.'() syncing comments for post #' . $post_id);
			$args = array(
				'post_id' => $post_id,
				'status' => array('hold', 'approve'),	// don't sync trashed/spam comments
				'orderby' => 'comment_date',
				'order' => 'ASC',
			);
			$query = new WP_Comment_Query($args);
			$comments = $query->get_comments();
SyncDebug::log(__METHOD__.'() found ' . count($comments) . ' comments for this content');
			foreach ($comments as $comment) {
				// add comment data to the array
				$comment_data = get_object_vars($comment);
SyncDebug::log(__METHOD__.'() comment: ' . var_export($comment_data, TRUE));
				$data['comment_data'][] = $comment_data;
				// add comment meta data to the array
				$comment_meta = get_comment_meta($comment->comment_ID);
SyncDebug::log(__METHOD__.'() comment meta: ' . var_export($comment_meta, TRUE));
				if (FALSE !== $comment_meta) {
					$data['comment_meta'][strval($comment->comment_ID)] = $comment_meta;
				}
			}
		}

		return $data;
	}
}

// EOF: