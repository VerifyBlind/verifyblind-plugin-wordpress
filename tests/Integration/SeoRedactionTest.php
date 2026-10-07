<?php
namespace VerifyBlind\Tests\Integration;

use VerifyBlind\Gate;
use VerifyBlind\Placements\WcProduct;
use VerifyBlind\SeoRedaction;

final class SeoRedactionTest extends WcTestCase {
	private function post(): int {
		$id               = wp_insert_post( array( 'post_title' => 'VB seo', 'post_status' => 'publish', 'post_content' => 'x' ) );
		$this->wc_posts[] = $id;
		return $id;
	}

	public function test_descriptions_of_locked_posts_are_replaced(): void {
		$post = $this->post();
		$this->rule( array( 'targets' => array( 'post_ids' => array( $post ) ) ) );
		$this->query_post( $post );
		foreach ( SeoRedaction::FILTERS as $filter ) {
			$this->assertSame( Gate::locked_text(), apply_filters( $filter, 'SECRET-META' ), $filter );
		}
		$this->verify_guest( '18+' );
		foreach ( SeoRedaction::FILTERS as $filter ) {
			$this->assertSame( 'SECRET-META', apply_filters( $filter, 'SECRET-META' ), $filter );
		}
	}

	public function test_untargeted_posts_keep_theirs_and_locked_products_lose_theirs(): void {
		$this->query_post( $this->post() );
		$this->assertSame( 'SECRET-META', apply_filters( 'wpseo_metadesc', 'SECRET-META' ) );
		$cat = $this->category( 'VB seo' );
		$pid = $this->product( array( $cat ) );
		$this->rule( array( 'placement' => WcProduct::KEY, 'targets' => array( 'term_ids' => array( $cat ) ) ) );
		$this->query_post( $pid );
		$this->assertSame( Gate::locked_text(), apply_filters( 'rank_math/frontend/description', 'SECRET-META' ) );
	}

	public function test_editors_see_the_real_description(): void {
		$post = $this->post();
		$this->rule( array( 'targets' => array( 'post_ids' => array( $post ) ) ) );
		$this->query_post( $post );
		wp_set_current_user( $this->make_user( 'editor' ) );
		$this->assertSame( 'SECRET-META', apply_filters( 'wpseo_metadesc', 'SECRET-META' ) );
	}
}
