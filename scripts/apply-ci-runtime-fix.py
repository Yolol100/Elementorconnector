#!/usr/bin/env python3
"""Apply the three confirmed CI fixes and remove temporary repair helpers."""
from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    target = Path(path)
    text = target.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected one replacement, found {count}")
    target.write_text(text.replace(old, new, 1), encoding="utf-8")


replace_once(
    "includes/Content/PostRequest.php",
    "\t\t$state = [\n\t\t\t'author'   => (int) $post->post_author,\n\t\t\t'date'     => (string) $post->post_date,\n\t\t\t'password' => (string) $post->post_password,\n\t\t\t'format'   => (string) ( get_post_format( $id ) ?: '' ),\n\t\t];",
    "\t\t$post_format = get_post_format( $id );\n\t\t$state       = [\n\t\t\t'author'   => (int) $post->post_author,\n\t\t\t'date'     => (string) $post->post_date,\n\t\t\t'password' => (string) $post->post_password,\n\t\t\t'format'   => (string) ( false === $post_format ? '' : $post_format ),\n\t\t];",
)

replace_once(
    "includes/Elementor/Documents.php",
    "\t\tif ( $post_id < 1 || $post_type !== get_post_type( $post_id ) ) {",
    "\t\tif ( 1 > $post_id || $post_type !== get_post_type( $post_id ) ) {",
)

replace_once(
    "includes/Content/TaxonomyTerm.php",
    "\t\t$objects = get_field_objects( 'term_' . $term_id, false, true, false );",
    "\t\t$objects = get_field_objects( $this->acf_object_id( $term_id ), false, true, false );",
)
replace_once(
    "includes/Content/TaxonomyTerm.php",
    "\t\t\tupdate_field( (string) $field['key'], $field['value'], 'term_' . $term_id );",
    "\t\t\tupdate_field( (string) $field['key'], $field['value'], $this->acf_object_id( $term_id ) );",
)
replace_once(
    "includes/Content/TaxonomyTerm.php",
    "\n\tprivate function yoast( \\WP_Term $term, string $taxonomy ): array {",
    "\n\tprivate function acf_object_id( int $term_id ): string {\n\t\t$term = get_term( $term_id );\n\t\tif ( ! $term instanceof \\WP_Term ) {\n\t\t\tthrow new RuntimeException( 'The taxonomy term no longer exists for ACF.' );\n\t\t}\n\t\treturn $term->taxonomy . '_' . $term_id;\n\t}\n\n\tprivate function yoast( \\WP_Term $term, string $taxonomy ): array {",
)

Path("scripts/apply-ci-runtime-fix.py").unlink()
Path(".github/workflows/apply-ci-runtime-fix.yml").unlink()
print("Confirmed CI fixes applied; temporary repair helpers removed.")
