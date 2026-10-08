<?php
namespace VerifyBlind\Admin;

defined( 'ABSPATH' ) || exit;

use VerifyBlind\AgeRule;
use VerifyBlind\Placements\Registry;
use VerifyBlind\Roles;
use VerifyBlind\Rules;

final class RulesPage {
	const SLUG = 'verifyblind';

	/** Which placements use which target pickers (data-vb-for: the editor shows a row only for these). */
	const POST_TARGETS    = 'content comments';
	const PRODUCT_TARGETS = 'wc_checkout wc_product wc_review';

	public static function hooks(): void {
		add_action( 'admin_post_verifyblind_save_rule', array( self::class, 'save' ) );
		add_action( 'admin_post_verifyblind_delete_rule', array( self::class, 'delete' ) );
	}

	private static function policy_labels(): array {
		return array(
			'reject'   => __( 'Reject the verification — the account stays, no badge or role', 'verifyblind' ),
			'block'    => __( 'Block the action — e.g. sign-up or coupon is refused', 'verifyblind' ),
			'flag'     => __( 'Accept and notify the admin — both accounts are marked "same person"', 'verifyblind' ),
			'transfer' => __( 'Move to the new account — the old account loses its verification', 'verifyblind' ),
		);
	}

	private static function error_text( string $code ): string {
		$map = array(
			'empty_name'    => __( 'Give the rule a name.', 'verifyblind' ),
			'bad_placement' => __( 'Choose where the rule applies.', 'verifyblind' ),
			'bad_age'       => __( 'The age condition is not valid (ages 1–150; a range needs a lower and a higher age).', 'verifyblind' ),
			'empty_request' => __( 'Ask for an age condition, the one-person check, or both.', 'verifyblind' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : __( 'The rule could not be saved.', 'verifyblind' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects what this screen shows
		if ( 'new' === $action || 'edit' === $action ) {
			$id = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects what this screen shows
			self::render_edit( 'edit' === $action ? Rules::get( $id ) : null );
			return;
		}
		self::render_list();
	}

	private static function render_list(): void {
		$placements = Rules::placements() + Registry::unavailable();
		$policies   = self::policy_labels();
		$msg        = isset( $_GET['vb_msg'] ) ? sanitize_key( wp_unslash( $_GET['vb_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects what this screen shows
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Rules', 'verifyblind' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add rule', 'verifyblind' ); ?></a>
			<hr class="wp-header-end">
			<?php if ( 'saved' === $msg || 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'saved' === $msg ? esc_html__( 'Rule saved.', 'verifyblind' ) : esc_html__( 'Rule deleted.', 'verifyblind' ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Rule', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Where', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Asks for', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Same person on another account', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Role for those who pass', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Status', 'verifyblind' ); ?></th>
					<th><?php esc_html_e( 'Shortcode', 'verifyblind' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! Rules::all() ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No rules yet.', 'verifyblind' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( Rules::all() as $rule ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&action=edit&rule=' . rawurlencode( $rule['id'] ) ) ); ?>"><?php echo esc_html( $rule['name'] ); ?></a></strong></td>
						<td><?php echo esc_html( isset( $placements[ $rule['placement'] ] ) ? $placements[ $rule['placement'] ] : $rule['placement'] ); ?></td>
						<td>
							<?php
							$asks = array();
							if ( '' !== $rule['age'] ) {
								$asks[] = $rule['age'];
							}
							if ( $rule['unique'] ) {
								$asks[] = __( 'one person', 'verifyblind' );
							}
							echo esc_html( implode( ' + ', $asks ) );
							?>
						</td>
						<td><?php echo $rule['unique'] ? esc_html( isset( $policies[ $rule['duplicate_policy'] ] ) ? $policies[ $rule['duplicate_policy'] ] : $rule['duplicate_policy'] ) : '—'; ?></td>
						<td><?php echo '' !== $rule['role'] ? esc_html( $rule['role'] ) : '—'; ?></td>
						<td><?php echo $rule['enabled'] ? esc_html__( 'On', 'verifyblind' ) : esc_html__( 'Off', 'verifyblind' ); ?></td>
						<td><code>[verifyblind_gate rule="<?php echo esc_html( $rule['id'] ); ?>"]…[/verifyblind_gate]</code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'A role is optional. It is only needed to connect another plugin (forum, membership, courses) that restricts access by role.', 'verifyblind' ); ?></p>
		</div>
		<?php
	}

	private static function render_edit( ?array $rule ): void {
		$rule       = $rule ? $rule : array( 'id' => '', 'name' => '', 'enabled' => true, 'placement' => 'content', 'targets' => array( 'post_ids' => array(), 'term_ids' => array() ), 'age' => '18+', 'unique' => false, 'duplicate_policy' => 'reject', 'role' => '', 'validity_days' => 0, 'guest_mode' => 'verify_each_order' );
		$guest_mode = isset( $rule['guest_mode'] ) ? (string) $rule['guest_mode'] : 'verify_each_order';
		$age        = '' !== $rule['age'] ? AgeRule::parse( $rule['age'] ) : null;
		$type       = null === $age ? 'none' : ( null === $age->max() ? 'at_least' : ( 0 === $age->min() ? 'under' : 'between' ) );
		$n          = null === $age ? 18 : ( 'under' === $type ? $age->max() : $age->min() );
		$m          = ( null !== $age && 'between' === $type ) ? $age->max() : '';
		$pages      = get_pages( array( 'number' => 200 ) );
		$page_ids   = array_map( 'intval', wp_list_pluck( $pages, 'ID' ) );
		$wc         = Registry::woocommerce_active();
		$products   = array();
		$coupons    = array();
		$cats       = array();
		if ( $wc ) {
			foreach ( $rule['targets']['post_ids'] as $pid ) {
				if ( 'product' === get_post_type( (int) $pid ) ) {
					$products[] = (int) $pid;
				}
			}
			$coupons = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
			$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			$cats    = is_array( $terms ) ? $terms : array();
		}
		$coupon_ids = array_map( 'intval', wp_list_pluck( $coupons, 'ID' ) );
		$other_ids  = array_diff( $rule['targets']['post_ids'], $page_ids, $products, $coupon_ids );
		$err        = isset( $_GET['vb_err'] ) ? sanitize_key( wp_unslash( $_GET['vb_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects what this screen shows
		?>
		<div class="wrap">
			<h1><?php echo '' === $rule['id'] ? esc_html__( 'Add rule', 'verifyblind' ) : esc_html__( 'Edit rule', 'verifyblind' ); ?></h1>
			<?php if ( '' !== $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( self::error_text( $err ) ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="verifyblind_save_rule">
				<input type="hidden" name="id" value="<?php echo esc_attr( $rule['id'] ); ?>">
				<?php wp_nonce_field( 'verifyblind_save_rule' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="vb-name"><?php esc_html_e( 'Name', 'verifyblind' ); ?></label></th>
						<td><input id="vb-name" name="name" class="regular-text" value="<?php echo esc_attr( $rule['name'] ); ?>" required>
						<label style="margin-left:12px"><input type="checkbox" name="enabled" value="1" <?php checked( $rule['enabled'] ); ?>> <?php esc_html_e( 'On', 'verifyblind' ); ?></label></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( '1 · Where?', 'verifyblind' ); ?></h2></th></tr>
					<tr><th><?php esc_html_e( 'Applies to', 'verifyblind' ); ?></th>
						<td><select name="placement">
							<?php foreach ( Rules::placements() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $rule['placement'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php foreach ( Registry::unavailable() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" disabled><?php echo esc_html( sprintf( /* translators: %s: placement name */ __( '%s — needs WooCommerce', 'verifyblind' ), $label ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'For a lock you can also place the "VerifyBlind lock" block or the shortcode in any content.', 'verifyblind' ); ?></p></td></tr>
					<tr data-vb-for="<?php echo esc_attr( self::POST_TARGETS ); ?>"><th><?php esc_html_e( 'Pages', 'verifyblind' ); ?></th>
						<td><div style="max-height:160px;overflow:auto;border:1px solid #dcdcde;padding:6px;background:#fff">
							<?php foreach ( $pages as $p ) : ?>
								<label style="display:block"><input type="checkbox" name="page_ids[]" value="<?php echo esc_attr( $p->ID ); ?>" <?php checked( in_array( (int) $p->ID, $rule['targets']['post_ids'], true ) ); ?>> <?php echo esc_html( $p->post_title ); ?></label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'A comment rule with nothing chosen here applies to every post.', 'verifyblind' ); ?></p></td></tr>
					<tr data-vb-for="<?php echo esc_attr( self::POST_TARGETS ); ?>"><th><?php esc_html_e( 'Categories', 'verifyblind' ); ?></th>
						<td><?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) : ?>
							<label style="margin-right:12px"><input type="checkbox" name="term_ids[]" value="<?php echo esc_attr( $c->term_id ); ?>" <?php checked( in_array( (int) $c->term_id, $rule['targets']['term_ids'], true ) ); ?>> <?php echo esc_html( $c->name ); ?></label>
						<?php endforeach; ?></td></tr>
					<tr data-vb-for="<?php echo esc_attr( self::POST_TARGETS ); ?>"><th><label for="vb-other"><?php esc_html_e( 'Other post IDs', 'verifyblind' ); ?></label></th>
						<td><input id="vb-other" name="other_post_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', $other_ids ) ); ?>" placeholder="12, 57"></td></tr>
					<?php if ( $wc ) : ?>
						<tr data-vb-for="<?php echo esc_attr( self::PRODUCT_TARGETS ); ?>"><th><label for="vb-products"><?php esc_html_e( 'Product IDs', 'verifyblind' ); ?></label></th>
							<td><input id="vb-products" name="product_ids" class="regular-text" value="<?php echo esc_attr( implode( ', ', $products ) ); ?>" placeholder="57, 58">
							<p class="description"><?php esc_html_e( 'You can also tick the rule in the VerifyBlind box on the product edit screen. Checkout and product-page rules apply only to the products and categories chosen here; a review rule with nothing chosen applies to every product.', 'verifyblind' ); ?></p></td></tr>
						<tr data-vb-for="<?php echo esc_attr( self::PRODUCT_TARGETS ); ?>"><th><?php esc_html_e( 'Product categories', 'verifyblind' ); ?></th>
							<td><?php foreach ( $cats as $c ) : ?>
								<label style="margin-right:12px"><input type="checkbox" name="product_cat_ids[]" value="<?php echo esc_attr( $c->term_id ); ?>" <?php checked( in_array( (int) $c->term_id, $rule['targets']['term_ids'], true ) ); ?>> <?php echo esc_html( $c->name ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Subcategories are included.', 'verifyblind' ); ?></p></td></tr>
						<tr data-vb-for="wc_coupon"><th><?php esc_html_e( 'Coupons', 'verifyblind' ); ?></th>
							<td>
							<?php if ( ! $coupons ) : ?>
								<?php esc_html_e( 'No coupons yet.', 'verifyblind' ); ?>
							<?php endif; ?>
							<?php foreach ( $coupons as $cp ) : ?>
								<label style="display:block"><input type="checkbox" name="coupon_ids[]" value="<?php echo esc_attr( $cp->ID ); ?>" <?php checked( in_array( (int) $cp->ID, $rule['targets']['post_ids'], true ) ); ?>> <code><?php echo esc_html( $cp->post_title ); ?></code></label>
							<?php endforeach; ?>
							</td></tr>
						<tr data-vb-for="wc_checkout"><th><?php esc_html_e( 'Guests', 'verifyblind' ); ?></th>
							<td><select name="guest_mode">
								<option value="verify_each_order" <?php selected( $guest_mode, 'verify_each_order' ); ?>><?php esc_html_e( 'Guests verify at checkout (kept for 24 hours in their browser)', 'verifyblind' ); ?></option>
								<option value="require_account" <?php selected( $guest_mode, 'require_account' ); ?>><?php esc_html_e( 'Require a customer account for these products', 'verifyblind' ); ?></option>
							</select></td></tr>
					<?php endif; ?>

					<tr><th colspan="2"><h2><?php esc_html_e( '2 · What is asked?', 'verifyblind' ); ?></h2></th></tr>
					<tr><th><?php esc_html_e( 'Age condition', 'verifyblind' ); ?></th>
						<td><select name="age_type">
							<option value="none" <?php selected( $type, 'none' ); ?>><?php esc_html_e( 'No age', 'verifyblind' ); ?></option>
							<option value="at_least" <?php selected( $type, 'at_least' ); ?>><?php esc_html_e( 'At least', 'verifyblind' ); ?></option>
							<option value="under" <?php selected( $type, 'under' ); ?>><?php esc_html_e( 'Younger than', 'verifyblind' ); ?></option>
							<option value="between" <?php selected( $type, 'between' ); ?>><?php esc_html_e( 'From … up to (not including)', 'verifyblind' ); ?></option>
						</select>
						<input type="number" name="age_n" min="1" max="150" value="<?php echo esc_attr( $n ); ?>" style="width:5em">
						<input type="number" name="age_m" min="1" max="150" value="<?php echo esc_attr( $m ); ?>" style="width:5em" placeholder="<?php esc_attr_e( 'upper', 'verifyblind' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'One-person check', 'verifyblind' ); ?></th>
						<td><label><input type="checkbox" name="unique" value="1" <?php checked( $rule['unique'] ); ?>> <?php esc_html_e( 'Recognise the same person across accounts (visitors log in first; on the sign-up form they verify while creating the account)', 'verifyblind' ); ?></label>
						<p class="description"><?php esc_html_e( 'Each requested item counts as one verification: age = 1, one-person check = 1. 2,000 verifications a month are free.', 'verifyblind' ); ?></p></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( '3 · Same person on another account', 'verifyblind' ); ?></h2></th></tr>
					<tr><th></th><td>
						<?php foreach ( self::policy_labels() as $key => $label ) : ?>
							<label style="display:block"><input type="radio" name="duplicate_policy" value="<?php echo esc_attr( $key ); ?>" <?php checked( $rule['duplicate_policy'], $key ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Only used when the one-person check is on.', 'verifyblind' ); ?></p></td></tr>

					<tr><th colspan="2"><h2><?php esc_html_e( '4 · For those who pass', 'verifyblind' ); ?></h2></th></tr>
					<tr><th><?php esc_html_e( 'Add role', 'verifyblind' ); ?></th>
						<td><select name="role"><option value=""><?php esc_html_e( '— none —', 'verifyblind' ); ?></option>
							<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
								<?php
								if ( ! Roles::is_grantable( (string) $slug ) ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $rule['role'], $slug ); ?>><?php echo esc_html( translate_user_role( $name ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php esc_html_e( 'or new role:', 'verifyblind' ); ?> <input name="new_role" class="regular-text" style="width:14em">
						<?php if ( '' !== $rule['role'] && ! Roles::is_grantable( (string) $rule['role'] ) ) : ?>
							<p class="description" style="color:#b32d2e"><?php echo esc_html( sprintf( /* translators: %s: role slug */ __( 'The role %s can no longer be granted automatically because it has editing or admin rights; choose another role.', 'verifyblind' ), $rule['role'] ) ); ?></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Added next to the existing role (Customer, Subscriber…), never replacing it. Only roles without editing or management powers are offered. What the role may do is set in the plugin that uses it.', 'verifyblind' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Valid for', 'verifyblind' ); ?></th>
						<td><select name="validity_days">
							<option value="0" <?php selected( $rule['validity_days'], 0 ); ?>><?php esc_html_e( 'No expiry', 'verifyblind' ); ?></option>
							<option value="182" <?php selected( $rule['validity_days'], 182 ); ?>><?php esc_html_e( '6 months', 'verifyblind' ); ?></option>
							<option value="365" <?php selected( $rule['validity_days'], 365 ); ?>><?php esc_html_e( '1 year', 'verifyblind' ); ?></option>
						</select></td></tr>
				</table>
				<?php submit_button( __( 'Save rule', 'verifyblind' ) ); ?>
			</form>
			<?php if ( '' !== $rule['id'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'verifyblind' ) ); ?>')">
					<input type="hidden" name="action" value="verifyblind_delete_rule">
					<input type="hidden" name="id" value="<?php echo esc_attr( $rule['id'] ); ?>">
					<?php wp_nonce_field( 'verifyblind_delete_rule' ); ?>
					<?php submit_button( __( 'Delete rule', 'verifyblind' ), 'delete', 'submit', false ); ?>
				</form>
				<p><code>[verifyblind_gate rule="<?php echo esc_html( $rule['id'] ); ?>"]…[/verifyblind_gate]</code></p>
			<?php endif; ?>
		</div>
		<?php
		// Show only the target rows of the chosen placement (all rows stay in the form; hidden ones still submit).
		wp_print_inline_script_tag( "(function(){var s=document.querySelector('select[name=\"placement\"]');if(!s){return;}function t(){var v=' '+s.value+' ';document.querySelectorAll('[data-vb-for]').forEach(function(r){r.style.display=(' '+r.getAttribute('data-vb-for')+' ').indexOf(v)===-1?'none':'';});}s.addEventListener('change',t);t();})();" );
	}

	/**
	 * Form fields -> array( 'input' => Rules::save() input, 'new_role' => null|array( 'slug', 'label' ) ).
	 * Creates nothing: the pending new role is created by save() once the rule validates.
	 */
	public static function input_from_post( array $p ): array {
		$role     = sanitize_key( isset( $p['role'] ) ? (string) $p['role'] : '' );
		$new_role = trim( sanitize_text_field( isset( $p['new_role'] ) ? (string) $p['new_role'] : '' ) );
		$pending  = null;
		if ( '' !== $new_role ) {
			$suffix = sanitize_key( str_replace( ' ', '_', remove_accents( $new_role ) ) );
			if ( '' !== $suffix ) {
				$pending = array( 'slug' => 'vb_' . $suffix, 'label' => $new_role );
				$role    = $pending['slug'];
			}
		}
		// Rules may only hand out roles without editing or management powers.
		if ( null === $pending && '' !== $role && ! Roles::is_grantable( $role ) ) {
			$role = '';
		}
		$other     = isset( $p['other_post_ids'] ) ? explode( ',', (string) $p['other_post_ids'] ) : array();
		$products  = isset( $p['product_ids'] ) ? explode( ',', (string) $p['product_ids'] ) : array();
		$placement = sanitize_key( isset( $p['placement'] ) ? (string) $p['placement'] : '' );
		$post_ids  = array();
		$term_ids  = array();
		// Keep only the targets that belong to the chosen placement (the form submits every hidden row too).
		if ( in_array( $placement, explode( ' ', self::POST_TARGETS ), true ) ) {
			$post_ids = array_merge( isset( $p['page_ids'] ) ? (array) $p['page_ids'] : array(), array_filter( $other, 'strlen' ) );
			$term_ids = isset( $p['term_ids'] ) ? (array) $p['term_ids'] : array();
		} elseif ( in_array( $placement, explode( ' ', self::PRODUCT_TARGETS ), true ) ) {
			$post_ids = array_filter( $products, 'strlen' );
			$term_ids = isset( $p['product_cat_ids'] ) ? (array) $p['product_cat_ids'] : array();
		} elseif ( 'wc_coupon' === $placement ) {
			$post_ids = isset( $p['coupon_ids'] ) ? (array) $p['coupon_ids'] : array();
		}
		$input     = array(
			'id'               => sanitize_text_field( isset( $p['id'] ) ? (string) $p['id'] : '' ),
			'name'             => sanitize_text_field( isset( $p['name'] ) ? (string) $p['name'] : '' ),
			'enabled'          => ! empty( $p['enabled'] ),
			'placement'        => $placement,
			'targets'          => array( 'post_ids' => $post_ids, 'term_ids' => $term_ids ),
			'age'              => Rules::age_from_form( sanitize_key( isset( $p['age_type'] ) ? (string) $p['age_type'] : '' ), isset( $p['age_n'] ) ? $p['age_n'] : 0, isset( $p['age_m'] ) ? $p['age_m'] : 0 ),
			'unique'           => ! empty( $p['unique'] ),
			'duplicate_policy' => sanitize_key( isset( $p['duplicate_policy'] ) ? (string) $p['duplicate_policy'] : 'reject' ),
			'role'             => $role,
			'validity_days'    => (int) ( isset( $p['validity_days'] ) ? $p['validity_days'] : 0 ),
			'guest_mode'       => sanitize_key( isset( $p['guest_mode'] ) ? (string) $p['guest_mode'] : 'verify_each_order' ),
		);
		// The editor has no marker field: an edited rule keeps the marker it was created with.
		$existing = '' !== $input['id'] ? Rules::get( $input['id'] ) : null;
		if ( $existing && ! empty( $existing['preset'] ) ) {
			$input['preset'] = $existing['preset'];
		}
		return array( 'input' => $input, 'new_role' => $pending );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'verifyblind_save_rule' );
		$parsed = self::input_from_post( wp_unslash( $_POST ) );
		$input  = $parsed['input'];
		try {
			// Validate first, so a failed save leaves no orphan role behind.
			Rules::sanitize( $input, array_keys( Rules::placements() ) );
			if ( null !== $parsed['new_role'] ) {
				Roles::create( $parsed['new_role']['slug'], $parsed['new_role']['label'] );
			}
			// A new-role name can collide with an existing privileged role; never store one.
			if ( '' !== $input['role'] && ! Roles::is_grantable( (string) $input['role'] ) ) {
				$input['role'] = '';
			}
			Rules::save( $input );
		} catch ( \InvalidArgumentException $e ) {
			$args = array( 'page' => self::SLUG, 'action' => '' !== $input['id'] ? 'edit' : 'new', 'vb_err' => $e->getMessage() );
			if ( '' !== $input['id'] ) {
				$args['rule'] = $input['id'];
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}
		Roles::sync_all(); // a new or changed role applies to people already verified
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'vb_msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'verifyblind_delete_rule' );
		Rules::delete( sanitize_text_field( wp_unslash( isset( $_POST['id'] ) ? (string) $_POST['id'] : '' ) ) );
		Roles::sync_all();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'vb_msg' => 'deleted' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
