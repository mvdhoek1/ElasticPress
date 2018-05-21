<?php
/**
 * Manage syncing of content between WP and Elasticsearch for users
 *
 * @since  2.6
 * @package elasticpress
 */

namespace ElasticPress\Indexable\User;

use ElasticPress\Indexables as Indexables;
use ElasticPress\Elasticsearch as Elasticsearch;
use ElasticPress\SyncManager as SyncManagerAbstract;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sync manager class
 */
class SyncManager extends SyncManagerAbstract {

	/**
	 * Setup actions and filters
	 *
	 * @since 2.6
	 */
	public function setup() {
		add_action( 'delete_user', [ $this, 'action_delete_user' ] );
		add_action( 'wpmu_delete_user', [ $this, 'action_delete_user' ] );
		add_action( 'profile_update', [ $this, 'action_sync_on_update' ] );
		add_action( 'user_register', [ $this, 'action_sync_on_update' ] );
		add_action( 'updated_user_meta', [ $this, 'action_queue_meta_sync' ], 10, 4 );
		add_action( 'added_user_meta', [ $this, 'action_queue_meta_sync' ], 10, 4 );

		/**
		 * @todo Handle deleted meta
		 */
	}

	/**
	 * When whitelisted meta is updated, queue the object for reindex
	 *
	 * @param  int    $meta_id Meta id.
	 * @param  int    $object_id Object id.
	 * @param  string $meta_key Meta key.
	 * @param  string $meta_value Meta value.
	 * @since  2.0
	 */
	public function action_queue_meta_sync( $meta_id, $object_id, $meta_key, $meta_value ) {
		global $importer;

		if ( ! Elasticsearch::factory()->get_elasticsearch_version() ) {
			return;
		}

		// If we have an importer we must be doing an import - let's abort.
		if ( ! empty( $importer ) ) {
			return;
		}

		$indexable = Indexables::factory()->get( 'user' );

		$prepared_document = $indexable->prepare_document( $object_id );

		// Make sure meta key that was changed is actually relevant.
		if ( ! isset( $prepared_document['meta'][ $meta_key ] ) ) {
			return;
		}

		$this->sync_queue[ $object_id ] = true;
	}

	/**
	 * Delete ES user when WP user is deleted
	 *
	 * @param int $post_id Post ID.
	 * @since 2.6
	 */
	public function action_delete_user( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		Indexables::factory()->get( 'user' )->delete( $user_id, false );
	}

	/**
	 * Sync ES index with what happened to the user being saved
	 *
	 * @param int $user_id User id.
	 * @since 2.6
	 */
	public function action_sync_on_update( $user_id ) {
		global $importer;

		// If we have an importer we must be doing an import - let's abort.
		if ( ! empty( $importer ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$this->sync_queue[ $user_id ] = true;
	}
}