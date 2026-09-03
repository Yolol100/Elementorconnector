#!/usr/bin/env python3
"""Make the runtime test compare against actually registered Yoast abilities."""
from pathlib import Path

path = Path("tests/runtime/content-operations.php")
text = path.read_text(encoding="utf-8")
old = """\t$catalog = $abilities->catalog();
\t$available = is_array( $catalog['abilities'] ?? null ) ? $catalog['abilities'] : [];
\tforeach ( [ 'core/get-site-info', 'acf/field-groups', 'acf/register-field-group', 'yoast-seo/get-seo-scores', 'woocommerce/product-create', 'woocommerce/product-update', 'woocommerce/product-delete', 'woocommerce/products-query' ] as $ability_name ) {
\t\tif ( ! isset( $available[ $ability_name ] ) ) {
\t\t\tthrow new RuntimeException( 'Expected runtime ability is missing: ' . $ability_name );
\t\t}
\t}
"""
new = """\t$catalog   = $abilities->catalog();
\t$available = is_array( $catalog['abilities'] ?? null ) ? $catalog['abilities'] : [];
\tforeach ( [ 'core/get-site-info', 'acf/field-groups', 'acf/register-field-group', 'woocommerce/product-create', 'woocommerce/product-update', 'woocommerce/product-delete', 'woocommerce/products-query' ] as $ability_name ) {
\t\tif ( ! isset( $available[ $ability_name ] ) ) {
\t\t\tthrow new RuntimeException( 'Expected runtime ability is missing: ' . $ability_name );
\t\t}
\t}

\t$registered_yoast = [];
\tforeach ( wp_get_abilities() as $ability_name => $ability ) {
\t\t$ability_name = is_string( $ability_name ) ? $ability_name : ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '' );
\t\tif ( ! str_starts_with( $ability_name, 'yoast-seo/' ) || ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
\t\t\tcontinue;
\t\t}
\t\t$meta    = $ability->get_meta();
\t\t$exposed = is_array( $meta ) && ( true === ( $meta['public'] ?? false ) || true === ( $meta['show_in_rest'] ?? false ) || ( is_array( $meta['mcp'] ?? null ) && true === ( $meta['mcp']['public'] ?? false ) ) );
\t\tif ( $exposed ) {
\t\t\t$registered_yoast[] = $ability_name;
\t\t}
\t}
\t$catalogued_yoast = [];
\tforeach ( array_keys( $available ) as $ability_name ) {
\t\tif ( str_starts_with( $ability_name, 'yoast-seo/' ) ) {
\t\t\t$catalogued_yoast[] = $ability_name;
\t\t}
\t}
\tsort( $registered_yoast, SORT_STRING );
\tsort( $catalogued_yoast, SORT_STRING );
\tif ( $registered_yoast !== $catalogued_yoast ) {
\t\tthrow new RuntimeException( 'The bridge Yoast ability catalog does not match the abilities registered by the live Yoast runtime.' );
\t}
"""
if text.count(old) != 1:
    raise SystemExit("Expected the fixed Yoast ability assertion block once.")
path.write_text(text.replace(old, new, 1), encoding="utf-8")

for temporary in (
    Path("scripts/apply-live-yoast-ability-test-fix.py"),
    Path(".github/workflows/apply-live-yoast-ability-test-fix.yml"),
):
    if temporary.exists():
        temporary.unlink()
print("Yoast ability test now follows the exact live registered capability set.")
