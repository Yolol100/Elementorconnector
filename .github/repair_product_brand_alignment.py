from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{path}: expected one match, found {count}: {old[:120]!r}')
    file.write_text(text.replace(old, new, 1), encoding='utf-8')


path = 'includes/Content/ProductRequest.php'
replace_once(
    path,
    "\t\t$desired_content = $this->desired_content( $id, $current_content, $core, $request, true );\n\t\t$desired_woo     = $this->desired_woo( $id, $current_woo, $woo );",
    "\t\t$desired_content = $this->desired_content( $id, $current_content, $core, $request, true );\n\t\t$desired_woo     = $this->desired_woo( $id, $current_woo, $woo );\n\t\t$desired_content = $this->align_product_brand_taxonomy( $id, $desired_content, $desired_woo );",
)
replace_once(
    path,
    "\t\t$desired_content = $this->desired_content( $id, $before_content, $core, $request, false );\n\t\t$desired_woo     = $this->desired_woo( $id, $before_woo, $woo );",
    "\t\t$desired_content = $this->desired_content( $id, $before_content, $core, $request, false );\n\t\t$desired_woo     = $this->desired_woo( $id, $before_woo, $woo );\n\t\t$desired_content = $this->align_product_brand_taxonomy( $id, $desired_content, $desired_woo );",
)
anchor = "\n\tprivate function apply_woo( int $id, array $woo ): void {"
helper = r'''
	private function align_product_brand_taxonomy( int $id, array $content, array $woo ): array {
		if ( ! array_key_exists( 'brand_ids', $woo ) || ! isset( $content['taxonomies']['product_brand'] ) ) {
			return $content;
		}
		$slugs = [];
		foreach ( $woo['brand_ids'] as $brand_id ) {
			$term = get_term( (int) $brand_id, 'product_brand' );
			if ( ! $term instanceof \WP_Term ) {
				throw new RuntimeException( 'A WooCommerce brand disappeared before the product update could be applied.' );
			}
			$slugs[] = (string) $term->slug;
		}
		sort( $slugs, SORT_STRING );
		$content['taxonomies']['product_brand'] = $slugs;
		return $this->content->validate_array( $content, $id );
	}
'''.replace('\\t', '\t')
text = Path(path).read_text(encoding='utf-8')
if text.count(anchor) != 1:
    raise RuntimeError('ProductRequest apply_woo anchor changed')
Path(path).write_text(text.replace(anchor, helper + anchor, 1), encoding='utf-8')

# Static regression coverage makes the dual-writer invariant explicit.
replace_once(
    'tests/content-request-safety.php',
    "$assert(str_contains($products, 'WooCommerceProductExtras') && str_contains($products, 'woo_payload(') && str_contains($products, 'apply_woo('), 'Product requests do not merge current WooCommerce product-model extras.');",
    "$assert(str_contains($products, 'WooCommerceProductExtras') && str_contains($products, 'woo_payload(') && str_contains($products, 'apply_woo('), 'Product requests do not merge current WooCommerce product-model extras.');\n$assert(str_contains($products, 'align_product_brand_taxonomy(') && str_contains($products, \"$content['taxonomies']['product_brand'] = $slugs\"), 'WooCommerce brand_ids are not aligned with the canonical product_brand taxonomy envelope.');",
)

# Keep public documentation aligned with the ownership rule.
replace_once(
    'README.md',
    'WooCommerce catalog fields are intentionally request-driven: use `manage-product` / `manage-product-variation` so writes go through WooCommerce CRUD and exact readback instead of generic post-meta mutation.',
    'WooCommerce catalog fields are intentionally request-driven: use `manage-product` / `manage-product-variation` so writes go through WooCommerce CRUD and exact readback instead of generic post-meta mutation. When WooCommerce `brand_ids` are changed, the bridge aligns the canonical `product_brand` taxonomy envelope to the same desired brands so the two supported APIs cannot undo each other.',
)
replace_once(
    'readme.txt',
    'WooCommerce catalog fields use `manage-product` and `manage-product-variation` request files so product writes use WooCommerce CRUD and verified readback.',
    'WooCommerce catalog fields use `manage-product` and `manage-product-variation` request files so product writes use WooCommerce CRUD and verified readback. Product `brand_ids` are aligned with the canonical `product_brand` taxonomy envelope so the WooCommerce product model and WordPress taxonomy API cannot overwrite each other with different desired states.',
)

print('WooCommerce product brand alignment repair applied')
