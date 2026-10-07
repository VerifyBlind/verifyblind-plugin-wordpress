<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Owner;
use VerifyBlind\Results;

final class GateTest extends TestCase {
	/** @var int[] */
	private $posts = array();
	/** @var int */
	private $inner_runs = 0;

	protected function setUp(): void {
		parent::setUp();
		Gate::reset_no_cache_flag();
	}

	protected function tearDown(): void {
		remove_shortcode( 'vb_test_inner' );
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		parent::tearDown();
	}

	private function post( string $content ): \WP_Post {
		$id            = wp_insert_post( array( 'post_title' => 'Gate test', 'post_content' => $content, 'post_status' => 'publish' ) );
		$this->posts[] = $id;
		return get_post( $id );
	}

	private function render( \WP_Post $post ): string {
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		return apply_filters( 'the_content', $post->post_content );
	}

	public function test_targeted_post_is_locked_until_verified(): void {
		$post = $this->post( 'SECRET-BODY' );
		$rule = $this->rule( array( 'age' => '18+', 'targets' => array( 'post_ids' => array( $post->ID ) ) ) );
		$html = $this->render( $post );
		$this->assertStringNotContainsString( 'SECRET-BODY', $html );
		$this->assertStringContainsString( 'verifyblind-box', $html );
		// Compare with describe() rather than literal English so the test survives the Turkish translation (Plan 3).
		$this->assertStringContainsString( esc_html( \VerifyBlind\Widget::describe( $rule ) ), $html );

		Results::add( (string) Owner::current( true ), '21+', true, 'n', false );
		$this->assertStringContainsString( 'SECRET-BODY', $this->render( $post ) );
	}

	public function test_category_target(): void {
		$cat  = wp_insert_term( 'VB Adult ' . wp_generate_password( 4, false ), 'category' );
		$post = $this->post( 'CAT-BODY' );
		wp_set_post_categories( $post->ID, array( $cat['term_id'] ) );
		$this->rule( array( 'targets' => array( 'term_ids' => array( $cat['term_id'] ) ) ) );
		$this->assertStringNotContainsString( 'CAT-BODY', $this->render( $post ) );
		Results::add( (string) Owner::current( true ), '21+', true, 'n', false );
		$this->assertStringContainsString( 'CAT-BODY', $this->render( $post ) );
		wp_delete_term( $cat['term_id'], 'category' );
	}

	public function test_shortcode_and_block(): void {
		$rule = $this->rule( array( 'age' => '60+' ) );
		$this->assertStringNotContainsString( 'INNER', do_shortcode( '[verifyblind_gate rule="' . $rule['id'] . '"]INNER[/verifyblind_gate]' ) );
		$this->assertStringNotContainsString( 'INNER', do_blocks( '<!-- wp:verifyblind/gate {"rule":"' . $rule['id'] . '"} --><p>INNER</p><!-- /wp:verifyblind/gate -->' ) );
		Results::add( (string) Owner::current( true ), '60+', true, 'n', false );
		$this->assertStringContainsString( 'INNER', do_shortcode( '[verifyblind_gate rule="' . $rule['id'] . '"]INNER[/verifyblind_gate]' ) );
		$this->assertStringContainsString( 'INNER', do_blocks( '<!-- wp:verifyblind/gate {"rule":"' . $rule['id'] . '"} --><p>INNER</p><!-- /wp:verifyblind/gate -->' ) );
	}

	public function test_unknown_rule_hides_content_from_visitors(): void {
		$this->assertSame( '', do_shortcode( '[verifyblind_gate rule="r_00000000"]INNER[/verifyblind_gate]' ) );
	}

	public function test_rest_and_feed_are_redacted(): void {
		$post = $this->post( 'API-BODY' );
		$this->rule( array( 'targets' => array( 'post_ids' => array( $post->ID ) ) ) );
		$res  = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post->ID ) );
		$data = $res->get_data();
		$this->assertStringNotContainsString( 'API-BODY', $data['content']['rendered'] );
		$this->assertSame( Gate::locked_text(), $data['content']['rendered'] );
		$GLOBALS['post'] = $post;
		$this->assertSame( Gate::locked_text(), apply_filters( 'the_content_feed', 'API-BODY', 'rss2' ) );
	}

	public function test_excerpt_is_redacted_until_unlocked(): void {
		$post = $this->post( 'EXC-BODY' );
		$this->rule( array( 'age' => '18+', 'targets' => array( 'post_ids' => array( $post->ID ) ) ) );
		$this->assertSame( Gate::locked_text(), apply_filters( 'get_the_excerpt', 'EXC-SECRET', $post ) );
		Results::add( (string) Owner::current( true ), '21+', true, 'n', false );
		$this->assertSame( 'EXC-SECRET', apply_filters( 'get_the_excerpt', 'EXC-SECRET', $post ) );
	}

	public function test_block_with_unknown_or_disabled_rule_renders_nothing(): void {
		$this->assertSame( '', do_blocks( '<!-- wp:verifyblind/gate {"rule":"r_00000000"} --><p>INNER</p><!-- /wp:verifyblind/gate -->' ) );
		$rule = $this->rule( array( 'enabled' => false ) );
		$this->assertSame( '', do_blocks( '<!-- wp:verifyblind/gate {"rule":"' . $rule['id'] . '"} --><p>INNER</p><!-- /wp:verifyblind/gate -->' ) );
	}

	public function test_unlocked_targeted_post_is_not_cacheable(): void {
		$post = $this->post( 'FREE-BODY' );
		$this->rule( array( 'age' => '18+', 'targets' => array( 'post_ids' => array( $post->ID ) ) ) );
		Results::add( (string) Owner::current( true ), '21+', true, 'n', false );
		Gate::reset_no_cache_flag();
		$this->assertStringContainsString( 'FREE-BODY', $this->render( $post ) );
		$this->assertTrue( Gate::no_cache_requested() );
	}

	public function test_rest_response_is_no_store(): void {
		$post = $this->post( 'REST-BODY' );
		$this->rule( array( 'targets' => array( 'post_ids' => array( $post->ID ) ) ) );
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post->ID ) );
		$this->assertSame( 'no-store, private', $res->get_headers()['Cache-Control'] );
	}

	public function test_child_category_is_locked_by_parent_target(): void {
		$parent = wp_insert_term( 'VB Parent ' . wp_generate_password( 4, false ), 'category' );
		$child  = wp_insert_term( 'VB Child ' . wp_generate_password( 4, false ), 'category', array( 'parent' => $parent['term_id'] ) );
		$post   = $this->post( 'CHILD-BODY' );
		wp_set_post_categories( $post->ID, array( $child['term_id'] ) );
		$this->rule( array( 'targets' => array( 'term_ids' => array( $parent['term_id'] ) ) ) );
		$this->assertStringNotContainsString( 'CHILD-BODY', $this->render( $post ) );
		wp_delete_term( $child['term_id'], 'category' );
		wp_delete_term( $parent['term_id'], 'category' );
	}

	public function test_locked_shortcode_does_not_run_inner_shortcodes(): void {
		add_shortcode( 'vb_test_inner', function () {
			$this->inner_runs++;
			return 'RAN';
		} );
		$rule = $this->rule( array( 'age' => '60+' ) );
		$sc   = '[verifyblind_gate rule="' . $rule['id'] . '"][vb_test_inner][/verifyblind_gate]';
		$this->assertStringNotContainsString( 'RAN', do_shortcode( $sc ) );
		$this->assertSame( 0, $this->inner_runs );
		Results::add( (string) Owner::current( true ), '60+', true, 'n', false );
		$this->assertStringContainsString( 'RAN', do_shortcode( $sc ) );
		$this->assertSame( 1, $this->inner_runs );
	}

	public function test_editors_bypass(): void {
		$post = $this->post( 'EDITOR-SEES' );
		$this->rule( array( 'targets' => array( 'post_ids' => array( $post->ID ) ) ) );
		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertStringContainsString( 'EDITOR-SEES', $this->render( $post ) );
	}
}
