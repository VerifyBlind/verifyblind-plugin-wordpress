<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Badge;
use VerifyBlind\Results;

final class BadgeTest extends WcTestCase {
	protected function setUp(): void {
		parent::setUp();
		Badge::reset_cache();
	}

	protected function tearDown(): void {
		Badge::reset_cache();
		parent::tearDown();
	}

	private function verified_user(): int {
		$uid = $this->make_user();
		Results::add( 'u:' . $uid, 'uid', true, 'b-' . $uid, false );
		return $uid;
	}

	private function post( int $author = 0 ): int {
		$id               = wp_insert_post( array( 'post_title' => 'VB badge', 'post_status' => 'publish', 'post_author' => $author ) );
		$this->wc_posts[] = $id;
		return $id;
	}

	private function comment_by( int $uid, int $post_id ): int {
		return (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'x',
				'comment_author'   => 'Name' . $uid,
				'user_id'          => $uid,
				'comment_approved' => 1,
				'comment_type'     => 'product' === get_post_type( $post_id ) ? 'review' : 'comment',
			)
		);
	}

	public function test_comment_authors_get_the_badge_only_when_one_person_verified(): void {
		$post = $this->post();
		$yes  = $this->comment_by( $this->verified_user(), $post );
		$no   = $this->comment_by( $this->make_user(), $post );
		$age  = $this->make_user();
		Results::add( 'u:' . $age, '18+', true, 'b-age', false );
		$only_age = $this->comment_by( $age, $post );
		$this->assertStringContainsString( 'verifyblind-badge', get_comment_author_link( $yes ) );
		$this->assertStringNotContainsString( 'verifyblind-badge', get_comment_author_link( $no ) );
		$this->assertStringNotContainsString( 'verifyblind-badge', get_comment_author_link( $only_age ), 'an age result alone earns no badge' );
	}

	public function test_product_reviews_get_it_through_comment_author(): void {
		$review = $this->comment_by( $this->verified_user(), $this->product() );
		ob_start();
		comment_author( $review );
		$this->assertStringContainsString( 'verifyblind-badge', (string) ob_get_clean() );
		$ordinary = $this->comment_by( $this->verified_user(), $this->post() );
		ob_start();
		comment_author( $ordinary );
		$this->assertStringNotContainsString( 'verifyblind-badge', (string) ob_get_clean(), 'ordinary comments use the author link filter instead' );
	}

	public function test_places_setting(): void {
		$this->assertSame( Badge::PLACES, Badge::places(), 'default: everywhere' );
		update_option( Badge::OPTION, array( 'author', 'nonsense' ) );
		$this->assertSame( array( 'author' ), Badge::places() );
		$comment = $this->comment_by( $this->verified_user(), $this->post() );
		$this->assertStringNotContainsString( 'verifyblind-badge', get_comment_author_link( $comment ) );
		update_option( Badge::OPTION, array() );
		$this->assertSame( array(), Badge::places() );
		$this->assertSame( array( 'comments', 'reviews' ), Badge::sanitize_places( array( 'reviews', 'comments', 'x' ) ) );
	}

	public function test_author_link_and_block_themes(): void {
		$uid  = $this->verified_user();
		$post = $this->post( $uid );
		$GLOBALS['post'] = get_post( $post );
		setup_postdata( $GLOBALS['post'] );
		$this->assertStringContainsString( 'verifyblind-badge', apply_filters( 'the_author_posts_link', '<a href="#">Author</a>' ) );
		$block = Badge::render_block( '<div class="wp-block-post-author-name">Author</div>', array( 'blockName' => 'core/post-author-name' ), (object) array( 'context' => array( 'postId' => $post ) ) );
		$this->assertStringContainsString( 'verifyblind-badge', $block );
		$this->assertStringEndsWith( '</div>', $block, 'the badge goes inside the block wrapper' );
		$comment = $this->comment_by( $uid, $post );
		$this->assertStringContainsString( 'verifyblind-badge', Badge::render_block( '<div class="wp-block-comment-author-name">Name</div>', array( 'blockName' => 'core/comment-author-name' ), (object) array( 'context' => array( 'commentId' => $comment ) ) ) );
		$this->assertSame( '<p>x</p>', Badge::render_block( '<p>x</p>', array( 'blockName' => 'core/paragraph' ), (object) array( 'context' => array( 'postId' => $post ) ) ) );
	}

	public function test_markup_is_fixed_and_escaped(): void {
		$html = Badge::html();
		$this->assertStringContainsString( 'src="https://verifyblind.com/badges/verified-with-verifyblind-icon-', $html );
		$this->assertMatchesRegularExpression( '#href="https://verifyblind.com/(tr|en)/how-it-works"#', $html );
		$this->assertStringContainsString( 'alt="' . esc_attr__( 'Verified with VerifyBlind', 'verifyblind' ) . '"', $html );
		$this->assertStringContainsString( 'rel="noopener"', $html );
	}
}
