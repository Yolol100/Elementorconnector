#!/usr/bin/env python3
"""Replace value-dependent ACF term validation with registered-field validation."""
from pathlib import Path

path = Path("includes/Content/TaxonomyTerm.php")
text = path.read_text(encoding="utf-8")

replacements = {
    "\t\t\t\t$this->apply_acf( $term_id, $before['acf'] );": "\t\t\t\t$this->apply_acf( $term_id, $taxonomy, $before['acf'] );",
    "\t\t\t$this->validate_acf( $term_id, $data['acf'] );": "\t\t\t$this->validate_acf( $term_id, $taxonomy, $data['acf'] );",
    "\t\t\t$this->apply_acf( $term_id, $data['acf'] );": "\t\t\t$this->apply_acf( $term_id, $taxonomy, $data['acf'] );",
}
for old, new in replacements.items():
    if text.count(old) != 1:
        raise SystemExit(f"Expected exactly one occurrence: {old!r}")
    text = text.replace(old, new, 1)

start_marker = "\tprivate function validate_acf( int $term_id, mixed $acf ): void {"
end_marker = "\n\tprivate function acf_object_id( int $term_id ): string {"
start = text.find(start_marker)
end = text.find(end_marker, start)
if start < 0 or end < 0:
    raise SystemExit("Could not locate the existing ACF validation block.")

new_block = """\tprivate function validate_acf( int $term_id, string $taxonomy, mixed $acf ): void {
\t\tif ( ! is_array( $acf ) || ( [] !== $acf && array_is_list( $acf ) ) ) {
\t\t\tthrow new RuntimeException( 'Taxonomy ACF data must be an object.' );
\t\t}
\t\tif ( [] === $acf ) {
\t\t\treturn;
\t\t}
\t\tif ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) || ! function_exists( 'update_field' ) ) {
\t\t\tthrow new RuntimeException( 'ACF taxonomy data is present but Advanced Custom Fields is not active.' );
\t\t}
\n\t\t$this->acf_object_id( $term_id );
\t\t$definitions = $this->acf_field_definitions( $taxonomy );
\t\tforeach ( $acf as $name => $field ) {
\t\t\t$keys = is_array( $field ) ? array_keys( $field ) : [];
\t\t\tsort( $keys, SORT_STRING );
\t\t\t$key        = is_array( $field ) && is_string( $field['key'] ?? null ) ? $field['key'] : '';
\t\t\t$type       = is_array( $field ) && is_string( $field['type'] ?? null ) ? $field['type'] : '';
\t\t\t$definition = $definitions[ $key ] ?? null;
\t\t\tif ( ! is_string( $name ) || [ 'key', 'type', 'value' ] !== $keys || ! is_array( $definition ) || $name !== $definition['name'] || $type !== $definition['type'] ) {
\t\t\t\tthrow new RuntimeException( 'The ACF taxonomy field identity no longer matches the site.' );
\t\t\t}
\t\t}
\t}
\n\tprivate function apply_acf( int $term_id, string $taxonomy, mixed $acf ): void {
\t\t$this->validate_acf( $term_id, $taxonomy, $acf );
\t\tforeach ( $acf as $field ) {
\t\t\tupdate_field( (string) $field['key'], $field['value'], $this->acf_object_id( $term_id ) );
\t\t}
\t}
\n\tprivate function acf_field_definitions( string $taxonomy ): array {
\t\t$field_groups = acf_get_field_groups( [ 'taxonomy' => $taxonomy ] );
\t\tif ( ! is_array( $field_groups ) ) {
\t\t\treturn [];
\t\t}
\n\t\t$definitions = [];
\t\tforeach ( $field_groups as $field_group ) {
\t\t\t$fields = is_array( $field_group ) ? acf_get_fields( $field_group ) : [];
\t\t\tif ( ! is_array( $fields ) ) {
\t\t\t\tcontinue;
\t\t\t}
\t\t\tforeach ( $fields as $field ) {
\t\t\t\tif ( ! is_array( $field ) ) {
\t\t\t\t\tcontinue;
\t\t\t\t}
\t\t\t\t$key  = is_string( $field['key'] ?? null ) ? $field['key'] : '';
\t\t\t\t$name = is_string( $field['name'] ?? null ) ? $field['name'] : '';
\t\t\t\t$type = is_string( $field['type'] ?? null ) ? $field['type'] : '';
\t\t\t\tif ( '' === $key || '' === $name || '' === $type ) {
\t\t\t\t\tcontinue;
\t\t\t\t}
\t\t\t\t$definition = [ 'name' => $name, 'type' => $type ];
\t\t\t\tif ( isset( $definitions[ $key ] ) && $definition !== $definitions[ $key ] ) {
\t\t\t\t\tthrow new RuntimeException( 'The ACF taxonomy field registry contains a conflicting field identity.' );
\t\t\t\t}
\t\t\t\t$definitions[ $key ] = $definition;
\t\t\t}
\t\t}
\t\tksort( $definitions, SORT_STRING );
\t\treturn $definitions;
\t}
"""

text = text[:start] + new_block + text[end:]
path.write_text(text, encoding="utf-8")

Path("scripts/apply-taxonomy-acf-definition-fix.py").unlink()
Path(".github/workflows/apply-taxonomy-acf-definition-fix.yml").unlink()
print("ACF taxonomy validation now uses registered taxonomy field definitions.")
