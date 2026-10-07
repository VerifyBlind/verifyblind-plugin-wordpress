<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Messages;
use VerifyBlind\Placements\Comments;
use VerifyBlind\Placements\WcReview;
use VerifyBlind\Rules;

final class CommentsTest extends WcTestCase {
	protected function setUp(): void {
		parent::setUp();
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_is_comment_flood', '__return_false', 99 );
		parent::tearDown();
	}

	private function post(): int {
		$id               = wp_insert_post( array( 'post_title' => 'VB comments', 'post_status' => 'publish', 'comment_status' => 'open' ) );
		$this->wc_posts[] = $id; // wp_delete_post( …, true ) also deletes its comments
		return $id;
	}

	/** @return int|\WP_Error */
	private function comment( int $post_id ) {
		$user = wp_get_current_user();
		return wp_new_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => 'VB comment ' . wp_rand(),
				'comment_author'       => $user->exists() ? $user->user_login : 'Guest',
				'comment_author_email' => 'vbc' . wp_rand() . '@example.com',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_agent'        => '',
				'comment_type'         => 'product' === get_post_type( $post_id ) ? 'review' : 'comment',
				'user_id'              => $user->ID,
			),
			true
		);
	}

	public function test_placements_are_registered(): void {
		$this->assertArrayHasKey( Comments::KEY, Rules::placements() );
		$this->assertArrayHasKey( WcReview::KEY, Rules::placements() );
	}

	public function test_guests_must_verify_before_commenting(): void {
		$post = $this->post();
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '18+' ) );
		$refused = $this->comment( $post );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'verifyblind_required', $refused->get_error_code() );
		$this->assertSame( Messages::get( 'comment_required' ), $refused->get_error_message() );
		$this->verify_guest( '18+' );
		$this->assertIsInt( $this->comment( $post ) );
	}

	public function test_rule_targets_limit_where_it_applies(): void {
		$covered = $this->post();
		$free    = $this->post();
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '18+', 'targets' => array( 'post_ids' => array( $covered ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->comment( $covered ) );
		$this->assertIsInt( $this->comment( $free ) );
	}

	public function test_moderators_are_not_asked(): void {
		$post = $this->post();
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '18+' ) );
		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertIsInt( $this->comment( $post ) );
	}

	public function test_comment_form_shows_the_box_and_one_person_asks_guests_to_log_in(): void {
		$GLOBALS['post'] = get_post( $this->post() );
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '', 'unique' => true ) );
		ob_start();
		do_action( 'comment_form_before' );
		$this->assertStringContainsString( 'verifyblind-box--login', (string) ob_get_clean() );
		wp_set_current_user( $this->make_user() );
		ob_start();
		do_action( 'comment_form_before' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'verifyblind-start', $html );
		$this->assertStringContainsString( 'data-reload="0"', $html );
	}

	public function test_product_reviews_follow_their_own_rule(): void {
		$product = $this->product();
		$post    = $this->post();
		$this->rule( array( 'placement' => WcReview::KEY, 'age' => '18+' ) );
		$refused = $this->comment( $product );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( Messages::get( 'comment_required' ), $refused->get_error_message() );
		$this->assertIsInt( $this->comment( $post ), 'a review rule does not touch ordinary comments' );
		$GLOBALS['post'] = get_post( $product );
		ob_start();
		do_action( 'comment_form_before' );
		$this->assertStringContainsString( 'verifyblind-box', (string) ob_get_clean() );
		$this->verify_guest( '18+' );
		$this->assertIsInt( $this->comment( $product ) );
	}

	public function test_covered_pages_are_never_cached_whatever_the_visitor(): void {
		$post    = $this->post();
		$product = $this->product();
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '18+' ) );
		$this->rule( array( 'placement' => WcReview::KEY, 'age' => '18+' ) );
		$this->verify_guest( '18+' ); // this visitor meets both rules: no box is shown to them

		foreach ( array( 'post' => $post, 'product' => $product ) as $what => $id ) {
			Gate::reset_no_cache_flag();
			$this->query_post( $id );
			Gate::maybe_no_cache();
			$this->assertTrue( Gate::no_cache_requested(), $what . ': marked at template_redirect' );
			Gate::reset_no_cache_flag();
			ob_start();
			do_action( 'comment_form_before' );
			$this->assertSame( '', (string) ob_get_clean(), $what . ': no box for this visitor' );
			$this->assertTrue( Gate::no_cache_requested(), $what . ': marked by the comment form too' );
		}

		$closed           = wp_insert_post( array( 'post_title' => 'VB closed', 'post_status' => 'publish', 'comment_status' => 'closed' ) );
		$this->wc_posts[] = $closed;
		Gate::reset_no_cache_flag();
		$this->query_post( $closed );
		Gate::maybe_no_cache();
		$this->assertFalse( Gate::no_cache_requested(), 'closed comments: no form, nothing per visitor' );

		$q                       = new \WP_Query( array( 'post__in' => array( $post ) ) );
		$GLOBALS['wp_query']     = $q;
		$GLOBALS['wp_the_query'] = $q;
		$this->assertFalse( is_singular() );
		Gate::reset_no_cache_flag();
		Gate::maybe_no_cache();
		$this->assertFalse( Gate::no_cache_requested(), 'archives have no comment form' );
	}

	public function test_a_comment_without_a_post_is_not_judged_against_the_global_post(): void {
		$GLOBALS['post'] = get_post( $this->post() );
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '18+' ) );
		$this->assertSame( 1, Comments::check( 1, array( 'comment_content' => 'x' ) ) );
		$this->assertSame( 1, Comments::check( 1, array( 'comment_post_ID' => 0, 'comment_content' => 'x' ) ) );
	}

	/** Decision: pingbacks and trackbacks carry text from elsewhere past the rule, so a covered post refuses them too. */
	public function test_pingbacks_and_trackbacks_on_covered_posts_are_refused(): void {
		$post = $this->post();
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '18+' ) );
		foreach ( array( 'pingback', 'trackback' ) as $type ) {
			$refused = wp_new_comment(
				array(
					'comment_post_ID'      => $post,
					'comment_content'      => 'VB ' . $type . ' ' . wp_rand(),
					'comment_author'       => 'Elsewhere',
					'comment_author_email' => '',
					'comment_author_url'   => 'https://example.com/' . $type,
					'comment_author_IP'    => '',
					'comment_agent'        => '',
					'comment_type'         => $type,
					'user_id'              => 0,
				),
				true
			);
			$this->assertInstanceOf( \WP_Error::class, $refused, $type );
			$this->assertSame( 'verifyblind_required', $refused->get_error_code(), $type );
		}
	}

	public function test_a_comment_rule_does_not_touch_product_reviews(): void {
		$product = $this->product();
		$this->rule( array( 'placement' => Comments::KEY, 'age' => '18+' ) );
		$this->assertIsInt( $this->comment( $product ) );
	}
}
