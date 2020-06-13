<?php
/**
 * Test search feature
 *
 * @package elasticpress
 */

namespace ElasticPressTest;

use ElasticPress;

/**
 * Search test class
 */
class TestSearch extends BaseTestCase {

	/**
	 * Setup each test.
	 *
	 * @since 2.1
	 */
	public function setUp() {
		global $wpdb;
		parent::setUp();
		$wpdb->suppress_errors();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		ElasticPress\Elasticsearch::factory()->delete_all_indices();
		ElasticPress\Indexables::factory()->get( 'post' )->put_mapping();

		ElasticPress\Indexables::factory()->get( 'post' )->sync_manager->sync_queue = [];

		$this->setup_test_post_type();
	}

	/**
	 * Clean up after each test. Reset our mocks
	 *
	 * @since 2.1
	 */
	public function tearDown() {
		parent::tearDown();

		// make sure no one attached to this
		remove_filter( 'ep_sync_terms_allow_hierarchy', array( $this, 'ep_allow_multiple_level_terms_sync' ), 100 );
		$this->fired_actions = array();
	}

	/**
	 * Test that search is on
	 *
	 * @since 2.1
	 * @group search
	 */
	public function testSearchOn() {
		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		$post_ids = array();

		Functions\create_and_sync_post();
		Functions\create_and_sync_post();
		Functions\create_and_sync_post( array( 'post_content' => 'findme' ) );

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		add_action( 'ep_wp_query_search', array( $this, 'action_wp_query_search' ), 10, 0 );

		$args = array(
			's' => 'findme',
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( ! empty( $this->fired_actions['ep_wp_query_search'] ) );
	}

	/**
	 * Test case for when index is deleted, request for Elasticsearch should fall back to WP Query
	 *
	 * @group search
	 */
	public function testSearchIndexDeleted() {
		global $wpdb;

		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		$post_ids = array();

		Functions\create_and_sync_post();
		Functions\create_and_sync_post();
		Functions\create_and_sync_post( array( 'post_content' => 'findme' ) );

		ElasticPress\Elasticsearch::factory()->delete_all_indices();

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$args = array(
			's' => 'findme',
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( empty( $query->elasticsearch_success ) );
		$this->assertEquals( 1, count( $query->posts ) );
	}

	/**
	 * Test if decaying is enabled.
	 *
	 * @since 2.4
	 * @group search
	 */
	public function testDecayingEnabled() {
		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		ElasticPress\Features::factory()->update_feature(
			'search',
			array(
				'active'           => true,
				'decaying_enabled' => true,
			)
		);

		Functions\create_and_sync_post(
			array(
				'post_content' => 'findme test 1',
				'tags_input'   => array(
					'one',
					'two',
				),
			)
		);
		ElasticPress\Elasticsearch::factory()->refresh_indices();

		add_filter( 'ep_formatted_args', array( $this, 'catch_ep_formatted_args' ) );
		$query = new \WP_Query(
			array(
				's' => 'test',
			)
		);

		$this->assertTrue( isset( $this->fired_actions['ep_formatted_args'] ) );
		$this->assertTrue(
			isset(
				$this->fired_actions['ep_formatted_args']['query'],
				$this->fired_actions['ep_formatted_args']['query']['function_score'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt']['scale'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt']['decay'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt']['offset']
			)
		);
	}

	/**
	 * Test if decaying is disabled.
	 *
	 * @since 2.4
	 * @group search
	 */
	public function testDecayingDisabled() {
		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		ElasticPress\Features::factory()->update_feature(
			'search',
			array(
				'active'           => true,
				'decaying_enabled' => false,
			)
		);

		Functions\create_and_sync_post(
			array(
				'post_content' => 'findme test 1',
				'tags_input'   => array(
					'one',
					'two',
				),
			)
		);
		ElasticPress\Elasticsearch::factory()->refresh_indices();

		add_filter( 'ep_formatted_args', array( $this, 'catch_ep_formatted_args' ) );

		$query = new \WP_Query(
			array(
				's' => 'test',
			)
		);

		$this->assertTrue( isset( $this->fired_actions['ep_formatted_args'] ) );
		$this->assertTrue(
			! isset(
				$this->fired_actions['ep_formatted_args']['query']['function_score'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt']['scale'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt']['decay'],
				$this->fired_actions['ep_formatted_args']['query']['function_score']['functions'][0]['exp']['post_date_gmt']['offset']
			)
		);
		$this->assertTrue(
			isset(
				$this->fired_actions['ep_formatted_args']['query']['bool'],
				$this->fired_actions['ep_formatted_args']['query']['bool']['should']
			)
		);
	}

	/**
	 * Catch ES query args.
	 *
	 * @group search
	 * @param array $args ES query args.
	 */
	public function catch_ep_formatted_args( $args ) {
		$this->fired_actions['ep_formatted_args'] = $args;
	}

	/**
	 * Test allowed tags for highlighting sub-feature.
	 *
	 * @group search
	 */
	public function testAllowedTags() {
		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		// a tag that is in the array of allowed tags
		$allowed_tag = 'span';
		$highlighting = ElasticPress\Features::factory()->get_registered_feature( 'search' )->highlighting;

		$this->assertTrue( 'span' === $highlighting->get_highlighting_tag( $allowed_tag ) );
	}

	/**
	 * Test not-allowed tags for highlighting sub-feature.
	 *
	 * @group search
	 */
	public function testNotAllowedTags() {
		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		// a tag that is not in the array of allowed tags
		$not_allowed_tag = 'div';
		$highlighting    = ElasticPress\Features::factory()->get_registered_feature( 'search' )->highlighting;

		$this->assertTrue( 'mark' === $highlighting->get_highlighting_tag( $not_allowed_tag ) );
	}

	/**
	 * Test default color for highlighting sub-feature.
	 *
	 * Default should be empty.
	 *
	 * @group search
	 */
	public function testDefaultColor() {
		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		$settings = ElasticPress\Features::factory()->get_registered_feature( 'search' )->get_settings();

		$highlighting  = ElasticPress\Features::factory()->get_registered_feature( 'search' )->highlighting->default_settings;
		$settings      = array_merge( $settings, $highlighting );
		$default_color = $settings['highlight_color'];

		$this->assertEmpty( $default_color, 'The default color is not empty' );
	}

	/**
	 * Testing changing color and tag settings for highlighting sub-feature.
	 *
	 * @group search
	 */
	public function testHighlightSetting() {

		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		ElasticPress\Features::factory()->update_feature(
			'search',
			array(
				'active' 			=> true,
				'highlight_enabled' => '1',
				'highlight_color' 	=> '#ff0',
				'highlight_tag' 	=> 'span',
			)
		);

		$settings = ElasticPress\Features::factory()->get_registered_feature( 'search' )->get_settings();

		$this->assertTrue( '#ff0' === $settings['highlight_color'] );
		$this->assertTrue( 'span' === $settings['highlight_tag'] );
	}

	/**
	 * Testing setting a tag that's not allowed
	 *
	 * Leverages the ep_highlighting_tag filter used when updating settings.
	 * Should return 'mark' as the tag
	 *
	 */
	public function testBadTagSetting() {

		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		ElasticPress\Features::factory()->update_feature(
			'search',
			array(
				'active' 			=> true,
				'highlight_enabled' => 1,
				'highlight_tag'		=> 'div'
			)
		);

		$settings = ElasticPress\Features::factory()->get_registered_feature( 'search' )->get_settings();
		$tag      = apply_filters( 'ep_highlighting_tag', $settings['highlight_tag'] );

		$this->assertTrue( 'mark' === $tag );
	}

	/**
	 * Testing excerpt settings
	 */
	public function testExcerptSetting() {

		ElasticPress\Features::factory()->activate_feature( 'search' );
		ElasticPress\Features::factory()->setup_features();

		// Need to call this since it's hooked to init
		ElasticPress\Features::factory()->get_registered_feature( 'search' )->search_setup();

		ElasticPress\Features::factory()->update_feature(
			'search',
			array(
				'active'			=> 1,
				'highlight_enabled' => '1',
				'highlight_excerpt' => '1'
			)
		);

		$settings = ElasticPress\Features::factory()->get_registered_feature( 'search' )->get_settings();

		$this->assertTrue( '1' === $settings['highlight_excerpt'] );
	}
}
