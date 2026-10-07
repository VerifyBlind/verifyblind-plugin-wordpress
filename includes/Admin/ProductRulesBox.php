<?php
namespace VerifyBlind\Admin;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\Rules;
use VerifyBlind\Targets;

/**
 * "VerifyBlind" box on the product edit screen: tick which WooCommerce rules apply to this product.
 * Ticking writes the product id into the rule's targets (unticking removes it). Rules are a site setting,
 * so only administrators (manage_options) see and save the box.
 */
final class ProductRulesBox {
	const PLACEMENTS = array( 'wc_checkout', 'wc_product', 'wc_review' );
	const NONCE      = 'verifyblind_product_rules';
	const FIELD      = 'verifyblind_product_rules_nonce';
	const NOTICE     = 'verifyblind_box_notice_';

	/** A review rule with no products and no categories applies to every product; the box never rescopes it. */
	private static function applies_to_all( array $rule ): bool {
		return 'wc_review' === $rule['placement'] && ! $rule['targets']['post_ids'] && ! $rule['targets']['term_ids'];
	}

	public static function hooks(): void {
		add_action( 'add_meta_boxes_product', array( self::class, 'add' ) );
		add_action( 'save_post_product', array( self::class, 'save' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	public static function add(): void {
		if ( current_user_can( 'manage_options' ) ) {
			add_meta_box( 'verifyblind-rules', 'VerifyBlind', array( self::class, 'render' ), 'product', 'side' );
		}
	}

	/** @param mixed $post WP_Post */
	public static function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::FIELD );
		$labels = Rules::placements();
		$rules  = array();
		foreach ( Rules::all() as $rule ) {
			if ( in_array( $rule['placement'], self::PLACEMENTS, true ) ) {
				$rules[] = $rule;
			}
		}
		if ( ! $rules ) {
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'No WooCommerce rules yet.', 'verifyblind' ),
				esc_url( admin_url( 'admin.php?page=' . RulesPage::SLUG . '&action=new' ) ),
				esc_html__( 'Add rule', 'verifyblind' )
			);
			return;
		}
		echo '<p class="description">' . esc_html__( 'Tick the rules that apply to this product.', 'verifyblind' ) . '</p>';
		foreach ( $rules as $rule ) {
			if ( self::applies_to_all( $rule ) ) {
				printf(
					'<label style="display:block;margin:4px 0"><input type="checkbox" checked disabled> %1$s <span class="description">(%2$s)</span> <em>%3$s</em></label>',
					esc_html( $rule['name'] ),
					esc_html( isset( $labels[ $rule['placement'] ] ) ? $labels[ $rule['placement'] ] : $rule['placement'] ),
					esc_html__( 'Applies to all products — edit the rule to narrow it', 'verifyblind' )
				);
				continue;
			}
			$direct       = in_array( (int) $post->ID, $rule['targets']['post_ids'], true );
			$via_category = ! $direct && Targets::covers( $post, array( 'targets' => array( 'post_ids' => array(), 'term_ids' => $rule['targets']['term_ids'] ) ) );
			printf(
				'<label style="display:block;margin:4px 0"><input type="checkbox" name="verifyblind_rules[]" value="%1$s"%2$s> %3$s <span class="description">(%4$s)</span>%5$s</label>',
				esc_attr( $rule['id'] ),
				checked( $direct, true, false ),
				esc_html( $rule['name'] ),
				esc_html( isset( $labels[ $rule['placement'] ] ) ? $labels[ $rule['placement'] ] : $rule['placement'] ),
				$via_category ? ' <em>' . esc_html__( 'already applies through a category', 'verifyblind' ) . '</em>' : ''
			);
		}
	}

	/** @param mixed $post_id */
	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$ticked = isset( $_POST['verifyblind_rules'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['verifyblind_rules'] ) ) : array();
		self::apply( $post_id, $ticked );
	}

	public static function apply( int $product_id, array $ticked_rule_ids ): void {
		$refused = array();
		foreach ( Rules::all() as $rule ) {
			if ( ! in_array( $rule['placement'], self::PLACEMENTS, true ) || self::applies_to_all( $rule ) ) {
				continue;
			}
			$ids  = $rule['targets']['post_ids'];
			$has  = in_array( $product_id, $ids, true );
			$want = in_array( $rule['id'], $ticked_rule_ids, true );
			if ( $has === $want ) {
				continue;
			}
			$rule['targets']['post_ids'] = $want ? array_merge( $ids, array( $product_id ) ) : array_values( array_diff( $ids, array( $product_id ) ) );
			// Unlinking the last product of a review rule would silently widen it to every product.
			if ( self::applies_to_all( $rule ) ) {
				$refused[] = $rule['name'];
				continue;
			}
			try {
				Rules::save( $rule );
			} catch ( \InvalidArgumentException $e ) {
				continue; // a stored rule that no longer validates (e.g. its placement is gone) is left alone
			}
		}
		if ( $refused ) {
			set_transient( self::NOTICE . get_current_user_id(), $refused, 300 );
		}
	}

	public static function notices(): void {
		$key   = self::NOTICE . get_current_user_id();
		$names = get_transient( $key );
		if ( ! is_array( $names ) || ! $names ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( /* translators: %s: rule names */ __( 'VerifyBlind: the product was kept in %s because removing the last product would make the review rule apply to every product. Edit the rule to change that.', 'verifyblind' ), implode( ', ', $names ) ) )
		);
	}
}
