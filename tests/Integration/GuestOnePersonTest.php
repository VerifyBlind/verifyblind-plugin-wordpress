<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Nonces;
use VerifyBlind\Owner;
use VerifyBlind\PendingIdentities;
use VerifyBlind\Placements\Comments;
use VerifyBlind\Placements\Registration;
use VerifyBlind\Placements\WcCheckout;
use VerifyBlind\Placements\WcProduct;
use VerifyBlind\Placements\WcReview;
use VerifyBlind\Placements\WcSite;
use VerifyBlind\Results;
use VerifyBlind\VerificationService;

/**
 * A guest runs the one-person check only to create an account. The guest's one-person pass from the sign-up
 * form opens the sign-up and nothing else: every other one-person rule needs an account.
 */
final class GuestOnePersonTest extends WcTestCase {
	/** @var array */
	private $signup;

	protected function setUp(): void {
		parent::setUp();
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		$this->signup = $this->rule( array( 'placement' => Registration::KEY, 'age' => '', 'unique' => true ) );
		$guest        = (string) Owner::current( true );
		$signer       = new Signer();
		Nonces::put( 'gop-n', $this->signup['id'], '', true, $guest, 960 );
		$r = ( new VerificationService( $signer ) )->verify( $signer->token( array( 'nonce' => 'gop-n', 'validations' => array( 'user_id' => 'P-GUEST' ) ) ), $guest, false );
		$this->assertSame( 'ok', $r['code'] );
		$this->assertContains( 'uid', Results::passed_conditions( $guest, 0, false ), 'the guest holds a one-person pass from the sign-up form' );
		$this->assertNotNull( PendingIdentities::find( $guest ) );
		WcProduct::reset_memo();
	}

	protected function tearDown(): void {
		remove_filter( 'wp_is_comment_flood', '__return_false', 99 );
		parent::tearDown();
	}

	private function one_person( string $placement, array $targets = array() ): array {
		$o = array( 'placement' => $placement, 'age' => '', 'unique' => true );
		if ( $targets ) {
			$o['targets'] = $targets;
		}
		return $this->rule( $o );
	}

	/** @return int|\WP_Error */
	private function comment( int $post_id ) {
		return wp_new_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => 'VB guest ' . wp_rand(),
				'comment_author'       => 'Guest',
				'comment_author_email' => 'vbg' . wp_rand() . '@example.com',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_agent'        => '',
				'comment_type'         => 'product' === get_post_type( $post_id ) ? 'review' : 'comment',
				'user_id'              => 0,
			),
			true
		);
	}

	public function test_the_pass_still_opens_the_sign_up(): void {
		$this->assertNull( Gate::blocking_rule( array( $this->signup ) ) );
		$this->assertNull( Registration::refusal() );
	}

	public function test_comments_stay_closed(): void {
		$post             = wp_insert_post( array( 'post_title' => 'VB gop', 'post_status' => 'publish', 'comment_status' => 'open' ) );
		$this->wc_posts[] = $post;
		$this->one_person( Comments::KEY );
		$refused = $this->comment( $post );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'verifyblind_required', $refused->get_error_code() );
	}

	public function test_product_reviews_stay_closed(): void {
		$product = $this->product();
		$this->one_person( WcReview::KEY );
		$refused = $this->comment( $product );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'verifyblind_required', $refused->get_error_code() );
	}

	public function test_product_pages_stay_closed(): void {
		$cat = $this->category( 'VB gop' );
		$pid = $this->product( array( $cat ) );
		$this->one_person( WcProduct::KEY, array( 'term_ids' => array( $cat ) ) );
		$this->assertNotNull( WcProduct::blocking( $pid ) );
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 ) );
		$this->assertStringContainsString( Gate::locked_text(), wc_get_product( $pid )->get_description() );
	}

	public function test_the_site_entrance_stays_closed(): void {
		$this->one_person( WcSite::KEY );
		$this->query_post( $this->product() );
		$this->assertSame( 'gate', WcSite::decide() );
	}

	public function test_locked_content_stays_closed(): void {
		$post             = wp_insert_post( array( 'post_title' => 'VB gop content', 'post_status' => 'publish', 'post_content' => 'GOP-SECRET' ) );
		$this->wc_posts[] = $post;
		$this->one_person( 'content', array( 'post_ids' => array( $post ) ) );
		$GLOBALS['post'] = get_post( $post );
		$this->assertStringNotContainsString( 'GOP-SECRET', apply_filters( 'the_content', 'GOP-SECRET' ) );
	}

	public function test_checkout_stays_closed(): void {
		$cat = $this->category( 'VB gop checkout' );
		WC()->cart->add_to_cart( $this->product( array( $cat ) ) );
		$this->one_person( WcCheckout::KEY, array( 'term_ids' => array( $cat ) ) );
		$this->assertNotNull( WcCheckout::blocking( WC()->cart ) );
	}
}
