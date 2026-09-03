#!/usr/bin/env python3
"""Keep WooCommerce brand IDs and WordPress product_brand terms consistent."""
from pathlib import Path

path = Path("includes/Content/ProductRequest.php")
text = path.read_text(encoding="utf-8")

create_old = """\t\t$desired_content = $this->desired_content( $id, $current_content, $core, $request, true );
\t\t$desired_woo     = $this->desired_woo( $id, $current_woo, $woo );

\t\t$this->validate_core_extras( $core, true );"""
create_new = """\t\t$desired_content = $this->desired_content( $id, $current_content, $core, $request, true );
\t\t$desired_woo     = $this->desired_woo( $id, $current_woo, $woo );
\t\t[ $desired_content, $desired_woo ] = $this->synchronize_product_brand( $desired_content, $desired_woo, $request );

\t\t$this->validate_core_extras( $core, true );"""

update_old = """\t\t$desired_content = $this->desired_content( $id, $before_content, $core, $request, false );
\t\t$desired_woo     = $this->desired_woo( $id, $before_woo, $woo );

\t\t$this->validate_core_extras( $core, false );"""
update_new = """\t\t$desired_content = $this->desired_content( $id, $before_content, $core, $request, false );
\t\t$desired_woo     = $this->desired_woo( $id, $before_woo, $woo );
\t\t[ $desired_content, $desired_woo ] = $this->synchronize_product_brand( $desired_content, $desired_woo, $request );

\t\t$this->validate_core_extras( $core, false );"""

for old, new in ((create_old, create_new), (update_old, update_new)):
    if text.count(old) != 1:
        raise SystemExit("Expected exact ProductRequest synchronization anchor once.")
    text = text.replace(old, new, 1)

anchor = "\n\tprivate function apply_woo( int $id, array $woo ): void {"
if text.count(anchor) != 1:
    raise SystemExit("Expected apply_woo anchor once.")

method = r'''
	private function synchronize_product_brand( array $content, array $woo, array $request ): array {
		$woocommerce_request = is_array( $request['woocommerce'] ?? null ) ? $request['woocommerce'] : [];
		$taxonomy_request    = is_array( $request['taxonomies'] ?? null ) ? $request['taxonomies'] : [];
		$has_brand_ids       = array_key_exists( 'brand_ids', $woocommerce_request );
		$has_brand_slugs     = array_key_exists( 'product_brand', $taxonomy_request );

		if ( ! $has_brand_ids && ! $has_brand_slugs ) {
			return [ $content, $woo ];
		}
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			throw new RuntimeException( 'WooCommerce product brands are not registered on this site.' );
		}

		$brand_ids   = $has_brand_ids ? array_values( array_unique( array_map( 'intval', $woo['brand_ids'] ?? [] ) ) ) : [];
		$brand_slugs = $has_brand_slugs ? array_values( array_unique( array_map( 'strval', $content['taxonomies']['product_brand'] ?? [] ) ) ) : [];
		sort( $brand_ids, SORT_NUMERIC );
		sort( $brand_slugs, SORT_STRING );

		$ids_from_slugs = $has_brand_slugs ? $this->product_brand_ids( $brand_slugs ) : [];
		$slugs_from_ids = $has_brand_ids ? $this->product_brand_slugs( $brand_ids ) : [];
		if ( $has_brand_ids && $has_brand_slugs && $brand_ids !== $ids_from_slugs ) {
			throw new RuntimeException( 'WooCommerce brand_ids conflict with taxonomies.product_brand.' );
		}

		$woo['brand_ids']                          = $has_brand_ids ? $brand_ids : $ids_from_slugs;
		$content['taxonomies']['product_brand']     = $has_brand_slugs ? $brand_slugs : $slugs_from_ids;
		return [ $content, $woo ];
	}

	private function product_brand_ids( array $slugs ): array {
		$ids = [];
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_brand' );
			if ( ! $term instanceof \WP_Term ) {
				throw new RuntimeException( 'A requested WooCommerce product brand no longer exists.' );
			}
			$ids[] = (int) $term->term_id;
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private function product_brand_slugs( array $ids ): array {
		$slugs = [];
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_brand' );
			if ( ! $term instanceof \WP_Term ) {
				throw new RuntimeException( 'A requested WooCommerce product brand no longer exists.' );
			}
			$slugs[] = (string) $term->slug;
		}
		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs, SORT_STRING );
		return $slugs;
	}
'''
text = text.replace(anchor, "\n" + method + anchor, 1)
path.write_text(text, encoding="utf-8")

for temporary in (
    Path("scripts/apply-product-brand-sync-fix.py"),
    Path(".github/workflows/apply-product-brand-sync-fix.yml"),
    Path(".github/workflows/diagnose-product-readback.yml"),
):
    if temporary.exists():
        temporary.unlink()

print("Product brand IDs and taxonomy terms now share one validated desired state.")
