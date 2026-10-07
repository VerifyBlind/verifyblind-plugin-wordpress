<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Owner;
use VerifyBlind\Results;

final class GateTest extends TestCase {
	/** @var int[] */
	private $posts = array();

	protected function tearDown(): void {
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

	public function test_editors_bypass(): void {
		$post = $this->post( 'EDITOR-SEES' );
		$this->rule( array( 'targets' => array( 'post_ids' => array( $post->ID ) ) ) );
		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertStringContainsString( 'EDITOR-SEES', $this->render( $post ) );
	}
}
