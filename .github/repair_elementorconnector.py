from __future__ import annotations

from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{path}: expected one exact match, found {count}: {old[:100]!r}")
    file.write_text(text.replace(old, new, 1), encoding="utf-8")


# 1. WPCS blockers.
replace_once(
    "includes/Elementor/Documents.php",
    "$post_id < 1 || $post_type !== get_post_type( $post_id )",
    "$post_id < 1 || get_post_type( $post_id ) !== $post_type",
)

# 2. Request processor: honor auto-apply and replace stale locks atomically.
replace_once(
    "includes/Sync/ContentRequests.php",
    "\tpublic function process(): void {\n\t\tif ( ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {",
    "\tpublic function process(): void {\n\t\tif ( 1 !== (int) Settings::get( 'auto_apply', 0 ) ) {\n\t\t\treturn;\n\t\t}\n\t\tif ( ! Settings::repo_is_configured() || ! get_option( Settings::AUTH_OPTION, '' ) ) {",
)
replace_once(
    "includes/Sync/ContentRequests.php",
    "\t\t\t'version'               => 4,",
    "\t\t\t'version'               => 5,",
)
replace_once(
    "includes/Sync/ContentRequests.php",
    "\t\t\t\t'Use a globally unique request_id for each request. Reusing an ID with different input is rejected.',",
    "\t\t\t\t'Use a globally unique request_id for each request. Reusing an ID with different input is rejected.',\n\t\t\t\t'For manage-post update/delete requests, copy base_hash from the exact canonical content JSON you read. WordPress rejects stale hashes before any mutation.',",
)
replace_once(
    "includes/Sync/ContentRequests.php",
    "\t\t$existing = get_option( self::PROCESS_LOCK_OPTION, '' );\n\t\t$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;\n\t\tif ( is_array( $data ) && time() - (int) ( $data['created_at'] ?? time() ) > self::PROCESS_LOCK_TTL ) {\n\t\t\tdelete_option( self::PROCESS_LOCK_OPTION );\n\t\t\tif ( add_option( self::PROCESS_LOCK_OPTION, $value, '', false ) ) {\n\t\t\t\treturn $token;\n\t\t\t}\n\t\t}\n\t\treturn '';",
    "\t\t$existing = get_option( self::PROCESS_LOCK_OPTION, '' );\n\t\t$data     = is_string( $existing ) ? json_decode( $existing, true ) : null;\n\t\tif ( is_array( $data ) && time() - (int) ( $data['created_at'] ?? time() ) > self::PROCESS_LOCK_TTL ) {\n\t\t\tglobal $wpdb;\n\t\t\t// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap is required so a contender cannot delete a freshly acquired lock.\n\t\t\t$updated = $wpdb->update(\n\t\t\t\t$wpdb->options,\n\t\t\t\t[ 'option_value' => $value ],\n\t\t\t\t[ 'option_name' => self::PROCESS_LOCK_OPTION, 'option_value' => $existing ],\n\t\t\t\t[ '%s' ],\n\t\t\t\t[ '%s', '%s' ]\n\t\t\t);\n\t\t\tif ( 1 === $updated ) {\n\t\t\t\twp_cache_delete( self::PROCESS_LOCK_OPTION, 'options' );\n\t\t\t\treturn $token;\n\t\t\t}\n\t\t}\n\t\treturn '';",
)

# 3. manage-post: require a fresh base hash and create durable snapshots before update/delete.
replace_once(
    "includes/Content/PostRequest.php",
    "use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Elementor\\Documents;",
    "use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Backup\\Snapshots;\nuse Webactueel\\ElementorJsonBridge\\Elementor\\Documents;",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\t\tif ( ! current_user_can( 'edit_post', $id ) ) {\n\t\t\tthrow new RuntimeException( 'You are not allowed to edit this WordPress content item.' );\n\t\t}\n\t\tif ( 'delete' === $action ) {",
    "\t\tif ( ! current_user_can( 'edit_post', $id ) ) {\n\t\t\tthrow new RuntimeException( 'You are not allowed to edit this WordPress content item.' );\n\t\t}\n\t\t$current_content = $this->content->payload( $id );\n\t\t$this->assert_base_hash( $current_content, $request );\n\t\tif ( 'delete' === $action ) {",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\t\t\tif ( ! wp_trash_post( $id ) || 'trash' !== get_post_status( $id ) ) {\n\t\t\t\tthrow new RuntimeException( 'WordPress content trash failed readback verification.' );\n\t\t\t}\n\t\t\treturn [ 'status' => 'deleted', 'post_id' => $id ];",
    "\t\t\t$snapshot_id = $this->snapshots()->create( $id, $current_content, 'before_request_delete' );\n\t\t\tif ( ! wp_trash_post( $id ) || 'trash' !== get_post_status( $id ) ) {\n\t\t\t\tthrow new RuntimeException( 'WordPress content trash failed readback verification.' );\n\t\t\t}\n\t\t\treturn [ 'status' => 'deleted', 'post_id' => $id, 'snapshot_id' => $snapshot_id ];",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\t\t$before_content  = $this->content->payload( $id );\n\t\t$before_extended = $this->extended_state( $id );",
    "\t\t$before_content  = $current_content;\n\t\t$before_extended = $this->extended_state( $id );",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\t\t$desired         = $this->desired_content( $id, $before_content, $post, $request, false );\n\t\t$this->validate_extended_post_fields( $id, $post );\n\n\t\ttry {",
    "\t\t$desired         = $this->desired_content( $id, $before_content, $post, $request, false );\n\t\t$this->validate_extended_post_fields( $id, $post );\n\t\t$snapshot_id = $this->snapshots()->create( $id, $before_content, 'before_request_update' );\n\n\t\ttry {",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\t\treturn [ 'status' => 'updated', 'post_id' => $id ];",
    "\t\treturn [ 'status' => 'updated', 'post_id' => $id, 'snapshot_id' => $snapshot_id ];",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\t\t$state = [\n\t\t\t'author'   => (int) $post->post_author,\n\t\t\t'date'     => (string) $post->post_date,\n\t\t\t'password' => (string) $post->post_password,\n\t\t\t'format'   => (string) ( get_post_format( $id ) ?: '' ),\n\t\t];",
    "\t\t$format = get_post_format( $id );\n\t\t$state  = [\n\t\t\t'author'   => (int) $post->post_author,\n\t\t\t'date'     => (string) $post->post_date,\n\t\t\t'password' => (string) $post->post_password,\n\t\t\t'format'   => false === $format ? '' : (string) $format,\n\t\t];",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\tprivate function validate_request( array $request ): void {\n\t\t$allowed = [ 'format', 'version', 'request_id', 'action', 'post_id', 'post_type', 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'confirm_destructive', 'result' ];",
    "\tprivate function assert_base_hash( array $current, array $request ): void {\n\t\t$base_hash = (string) ( $request['base_hash'] ?? '' );\n\t\tif ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $base_hash ) ) {\n\t\t\tthrow new RuntimeException( 'Updating or deleting content requires a valid base_hash.' );\n\t\t}\n\t\tif ( ! hash_equals( $base_hash, CanonicalJson::hash( $current ) ) ) {\n\t\t\tthrow new RuntimeException( 'The WordPress content changed after this request was authored. Refresh the canonical content JSON and create a new request.' );\n\t\t}\n\t}\n\n\tprivate function snapshots(): Snapshots {\n\t\treturn new Snapshots();\n\t}\n\n\tprivate function validate_request( array $request ): void {\n\t\t$allowed = [ 'format', 'version', 'request_id', 'action', 'post_id', 'post_type', 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'base_hash', 'confirm_destructive', 'result' ];",
)
replace_once(
    "includes/Content/PostRequest.php",
    "\t\t} elseif ( (int) ( $request['post_id'] ?? 0 ) < 1 ) {\n\t\t\tthrow new RuntimeException( 'Updating or deleting content requires an exact post_id.' );\n\t\t}\n\t\tif ( isset( $request['post'] )",
    "\t\t} elseif ( (int) ( $request['post_id'] ?? 0 ) < 1 ) {\n\t\t\tthrow new RuntimeException( 'Updating or deleting content requires an exact post_id.' );\n\t\t} elseif ( ! is_string( $request['base_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['base_hash'] ) ) {\n\t\t\tthrow new RuntimeException( 'Updating or deleting content requires a valid base_hash.' );\n\t\t}\n\t\tif ( isset( $request['post'] )",
)

# 4. ACF first-write support for registered fields that match the target screen.
old_wp_acf = """\tprivate function validate_acf( array $acf, int $post_id ): void {
\t\tif ( [] === $acf ) {
\t\t\treturn;
\t\t}
\t\tif ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
\t\t\tthrow new RuntimeException( 'ACF content is present but Advanced Custom Fields is not active.' );
\t\t}
\t\t$current = $this->acf( $post_id );
\t\tforeach ( $acf as $name => $field ) {
\t\t\t$keys = is_array( $field ) ? array_keys( $field ) : [];
\t\t\tsort( $keys, SORT_STRING );
\t\t\tif ( ! isset( $current[ $name ] ) || [ 'key', 'type', 'value' ] !== $keys ) {
\t\t\t\tthrow new RuntimeException( 'The ACF field set no longer matches this WordPress item.' );
\t\t\t}
\t\t\tif ( $field['key'] !== $current[ $name ]['key'] || $field['type'] !== $current[ $name ]['type'] ) {
\t\t\t\tthrow new RuntimeException( 'An ACF field identity changed after export.' );
\t\t\t}
\t\t}
\t}
"""
new_wp_acf = """\tprivate function validate_acf( array $acf, int $post_id ): void {
\t\tif ( [] === $acf ) {
\t\t\treturn;
\t\t}
\t\tif ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
\t\t\tthrow new RuntimeException( 'ACF content is present but Advanced Custom Fields is not active.' );
\t\t}
\t\t$current            = $this->acf( $post_id );
\t\t$allowed_group_keys = [];
\t\tif ( function_exists( 'acf_get_field_groups' ) ) {
\t\t\t$groups = acf_get_field_groups( [ 'post_id' => $post_id, 'post_type' => (string) get_post_type( $post_id ) ] );
\t\t\tforeach ( is_array( $groups ) ? $groups : [] as $group ) {
\t\t\t\tif ( is_array( $group ) && is_string( $group['key'] ?? null ) ) {
\t\t\t\t\t$allowed_group_keys[] = $group['key'];
\t\t\t\t}
\t\t\t}
\t\t}
\t\tforeach ( $acf as $name => $field ) {
\t\t\t$keys = is_array( $field ) ? array_keys( $field ) : [];
\t\t\tsort( $keys, SORT_STRING );
\t\t\t$identity = $current[ $name ] ?? null;
\t\t\tif ( null === $identity && function_exists( 'get_field_object' ) && is_array( $field ) ) {
\t\t\t\t$candidate = get_field_object( (string) ( $field['key'] ?? '' ), $post_id, false, false );
\t\t\t\tif (\n\t\t\t\t\tis_array( $candidate )\n\t\t\t\t\t&& (string) ( $candidate['name'] ?? '' ) === (string) $name\n\t\t\t\t\t&& in_array( (string) ( $candidate['parent'] ?? '' ), $allowed_group_keys, true )\n\t\t\t\t) {\n\t\t\t\t\t$identity = [ 'key' => (string) $candidate['key'], 'type' => (string) ( $candidate['type'] ?? '' ), 'value' => null ];\n\t\t\t\t}\n\t\t\t}\n\t\t\tif ( ! is_array( $identity ) || [ 'key', 'type', 'value' ] !== $keys ) {\n\t\t\t\tthrow new RuntimeException( 'The ACF field set no longer matches this WordPress item.' );\n\t\t\t}\n\t\t\tif ( $field['key'] !== $identity['key'] || $field['type'] !== $identity['type'] ) {\n\t\t\t\tthrow new RuntimeException( 'An ACF field identity changed after export.' );\n\t\t\t}\n\t\t}\n\t}
"""
replace_once("includes/Content/WordPressDocument.php", old_wp_acf, new_wp_acf)

replace_once(
    "includes/Content/TaxonomyTerm.php",
    "$this->apply_acf( $term_id, $before['acf'] );",
    "$this->apply_acf( $term_id, $taxonomy, $before['acf'] );",
)
replace_once(
    "includes/Content/TaxonomyTerm.php",
    "$this->validate_acf( $term_id, $data['acf'] );",
    "$this->validate_acf( $term_id, $taxonomy, $data['acf'] );",
)
replace_once(
    "includes/Content/TaxonomyTerm.php",
    "$this->apply_acf( $term_id, $data['acf'] );",
    "$this->apply_acf( $term_id, $taxonomy, $data['acf'] );",
)
old_term_acf = """\tprivate function validate_acf( int $term_id, mixed $acf ): void {
\t\tif ( ! is_array( $acf ) || ( [] !== $acf && array_is_list( $acf ) ) ) {
\t\t\tthrow new RuntimeException( 'Taxonomy ACF data must be an object.' );
\t\t}
\t\tif ( [] === $acf ) {
\t\t\treturn;
\t\t}
\t\tif ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
\t\t\tthrow new RuntimeException( 'ACF taxonomy data is present but Advanced Custom Fields is not active.' );
\t\t}
\t\t$current = $this->acf( $term_id );
\t\tforeach ( $acf as $name => $field ) {
\t\t\t$keys = is_array( $field ) ? array_keys( $field ) : [];
\t\t\tsort( $keys, SORT_STRING );
\t\t\tif ( ! isset( $current[ $name ] ) || [ 'key', 'type', 'value' ] !== $keys || $field['key'] !== $current[ $name ]['key'] || $field['type'] !== $current[ $name ]['type'] ) {
\t\t\t\tthrow new RuntimeException( 'The ACF taxonomy field identity no longer matches the site.' );
\t\t\t}
\t\t}
\t}

\tprivate function apply_acf( int $term_id, mixed $acf ): void {
\t\t$this->validate_acf( $term_id, $acf );
\t\tforeach ( $acf as $field ) {
\t\t\tupdate_field( (string) $field['key'], $field['value'], 'term_' . $term_id );
\t\t}
\t}
"""
new_term_acf = """\tprivate function validate_acf( int $term_id, string $taxonomy, mixed $acf ): void {
\t\tif ( ! is_array( $acf ) || ( [] !== $acf && array_is_list( $acf ) ) ) {
\t\t\tthrow new RuntimeException( 'Taxonomy ACF data must be an object.' );
\t\t}
\t\tif ( [] === $acf ) {
\t\t\treturn;
\t\t}
\t\tif ( ! function_exists( 'get_field_objects' ) || ! function_exists( 'update_field' ) ) {
\t\t\tthrow new RuntimeException( 'ACF taxonomy data is present but Advanced Custom Fields is not active.' );
\t\t}
\t\t$current            = $this->acf( $term_id );
\t\t$allowed_group_keys = [];
\t\tif ( function_exists( 'acf_get_field_groups' ) ) {
\t\t\t$groups = acf_get_field_groups( [ 'taxonomy' => $taxonomy ] );
\t\t\tforeach ( is_array( $groups ) ? $groups : [] as $group ) {
\t\t\t\tif ( is_array( $group ) && is_string( $group['key'] ?? null ) ) {
\t\t\t\t\t$allowed_group_keys[] = $group['key'];
\t\t\t\t}
\t\t\t}
\t\t}
\t\tforeach ( $acf as $name => $field ) {
\t\t\t$keys = is_array( $field ) ? array_keys( $field ) : [];
\t\t\tsort( $keys, SORT_STRING );
\t\t\t$identity = $current[ $name ] ?? null;
\t\t\tif ( null === $identity && function_exists( 'get_field_object' ) && is_array( $field ) ) {
\t\t\t\t$candidate = get_field_object( (string) ( $field['key'] ?? '' ), 'term_' . $term_id, false, false );
\t\t\t\tif (\n\t\t\t\t\tis_array( $candidate )\n\t\t\t\t\t&& (string) ( $candidate['name'] ?? '' ) === (string) $name\n\t\t\t\t\t&& in_array( (string) ( $candidate['parent'] ?? '' ), $allowed_group_keys, true )\n\t\t\t\t) {\n\t\t\t\t\t$identity = [ 'key' => (string) $candidate['key'], 'type' => (string) ( $candidate['type'] ?? '' ), 'value' => null ];\n\t\t\t\t}\n\t\t\t}\n\t\t\tif ( ! is_array( $identity ) || [ 'key', 'type', 'value' ] !== $keys || $field['key'] !== $identity['key'] || $field['type'] !== $identity['type'] ) {\n\t\t\t\tthrow new RuntimeException( 'The ACF taxonomy field identity no longer matches the site.' );\n\t\t\t}\n\t\t}\n\t}

\tprivate function apply_acf( int $term_id, string $taxonomy, mixed $acf ): void {
\t\t$this->validate_acf( $term_id, $taxonomy, $acf );
\t\tforeach ( $acf as $field ) {
\t\t\tupdate_field( (string) $field['key'], $field['value'], 'term_' . $term_id );
\t\t}
\t}
"""
replace_once("includes/Content/TaxonomyTerm.php", old_term_acf, new_term_acf)

# 5. Zero-dependency regression assertions.
replace_once(
    "tests/content-request-safety.php",
    "$assert(str_contains($requests, 'ejb_content_requests_lock') && str_contains($requests, 'add_option( self::PROCESS_LOCK_OPTION') && str_contains($requests, 'PROCESS_LOCK_TTL'), 'Atomic request-processing lock is missing.');",
    "$assert(str_contains($requests, 'ejb_content_requests_lock') && str_contains($requests, 'add_option( self::PROCESS_LOCK_OPTION') && str_contains($requests, '$wpdb->update(') && str_contains($requests, \"'option_value' => $existing\"), 'Atomic request-processing lock is missing.');\n$assert(str_contains($requests, \"Settings::get( 'auto_apply', 0 )\") && str_contains($requests, \"1 !== (int) Settings::get( 'auto_apply', 0 )\"), 'GitHub request dispatch does not honor the auto-apply setting.');\n$assert(str_contains($posts, \"'base_hash'\") && str_contains($posts, 'assert_base_hash(') && str_contains($posts, 'CanonicalJson::hash( $current )'), 'manage-post stale-request protection is missing.');\n$assert(str_contains($posts, 'before_request_update') && str_contains($posts, 'before_request_delete') && str_contains($posts, 'new Snapshots()'), 'manage-post durable request snapshots are missing.');\n$assert(str_contains($terms, 'acf_get_field_groups(') && str_contains($terms, \"[ 'taxonomy' => $taxonomy ]\") && str_contains($terms, 'get_field_object('), 'Taxonomy ACF first-write identity validation is missing.');",
)
replace_once(
    "tests/wordpress-content-sync.php",
    "$assert(str_contains($posts, 'rollback could not be verified') && str_contains($posts, 'CanonicalJson::hash'), 'Post request rollback/readback protection is incomplete.');",
    "$assert(str_contains($posts, 'rollback could not be verified') && str_contains($posts, 'CanonicalJson::hash'), 'Post request rollback/readback protection is incomplete.');\n$assert(str_contains($posts, \"'base_hash'\") && str_contains($posts, 'before_request_update') && str_contains($posts, 'before_request_delete'), 'Post request conflict/snapshot protection is incomplete.');\n$assert(str_contains($requests, \"1 !== (int) Settings::get( 'auto_apply', 0 )\") && str_contains($requests, '$wpdb->update('), 'Request dispatch opt-in or atomic lock protection is incomplete.');\n$assert(str_contains($content, 'acf_get_field_groups(') && str_contains($content, 'get_field_object(') && str_contains($terms, \"[ 'taxonomy' => $taxonomy ]\"), 'ACF first-write validation is incomplete.');",
)

# 6. Runtime tests: supply/verify fresh base hashes, durable snapshots and stale-request rejection.
replace_once(
    "tests/runtime/content-operations.php",
    "use Webactueel\\ElementorJsonBridge\\Content\\WordPressDocument;\nuse Webactueel\\ElementorJsonBridge\\Elementor\\Documents;",
    "use Webactueel\\ElementorJsonBridge\\Backup\\Snapshots;\nuse Webactueel\\ElementorJsonBridge\\Content\\WordPressDocument;\nuse Webactueel\\ElementorJsonBridge\\Elementor\\Documents;",
)
replace_once(
    "tests/runtime/content-operations.php",
    "use Webactueel\\ElementorJsonBridge\\Elementor\\PayloadValidator;",
    "use Webactueel\\ElementorJsonBridge\\Elementor\\PayloadValidator;\nuse Webactueel\\ElementorJsonBridge\\Support\\CanonicalJson;",
)
replace_once(
    "tests/runtime/content-operations.php",
    "$abilities  = new AbilityBridge();\n\n$cleanup_posts",
    "$abilities  = new AbilityBridge();\n\n$post_base_hash = static function ( int $post_id ) use ( $content ): string {\n\treturn CanonicalJson::hash( $content->payload( $post_id ) );\n};\n\n$cleanup_posts",
)
replace_once(
    "tests/runtime/content-operations.php",
    "static function () use ( $posts, $page_id ): void {\n\t\t\t$posts->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t\t\t'version'    => PostRequest::VERSION,\n\t\t\t\t\t'request_id' => 'runtime-post-invalid-author',\n\t\t\t\t\t'action'     => 'update',\n\t\t\t\t\t'post_id'    => $page_id,",
    "static function () use ( $posts, $page_id, $post_base_hash ): void {\n\t\t\t$posts->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t\t\t'version'    => PostRequest::VERSION,\n\t\t\t\t\t'request_id' => 'runtime-post-invalid-author',\n\t\t\t\t\t'action'     => 'update',\n\t\t\t\t\t'post_id'    => $page_id,\n\t\t\t\t\t'base_hash'  => $post_base_hash( $page_id ),",
)
replace_once(
    "tests/runtime/content-operations.php",
    "\t$posts->execute(\n\t\t[\n\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t'version'    => PostRequest::VERSION,\n\t\t\t'request_id' => 'runtime-post-update',\n\t\t\t'action'     => 'update',\n\t\t\t'post_id'    => $page_id,",
    "\t$before_update_payload = $content->payload( $page_id );\n\t$post_update_result   = $posts->execute(\n\t\t[\n\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t'version'    => PostRequest::VERSION,\n\t\t\t'request_id' => 'runtime-post-update',\n\t\t\t'action'     => 'update',\n\t\t\t'post_id'    => $page_id,\n\t\t\t'base_hash'  => CanonicalJson::hash( $before_update_payload ),",
)
replace_once(
    "tests/runtime/content-operations.php",
    "\tif ( ! $page instanceof WP_Post || 'EJB Request Page Updated' !== $page->post_title || 'runtime-pass' !== $page->post_password ) {\n\t\tthrow new RuntimeException( 'PostRequest update failed readback.' );\n\t}\n\n\t$elementor_result",
    "\tif ( ! $page instanceof WP_Post || 'EJB Request Page Updated' !== $page->post_title || 'runtime-pass' !== $page->post_password ) {\n\t\tthrow new RuntimeException( 'PostRequest update failed readback.' );\n\t}\n\t$snapshot_id = (int) ( $post_update_result['snapshot_id'] ?? 0 );\n\tif ( $snapshot_id < 1 || ! hash_equals( CanonicalJson::hash( $before_update_payload ), CanonicalJson::hash( ( new Snapshots() )->payload( $snapshot_id, $page_id ) ) ) ) {\n\t\tthrow new RuntimeException( 'PostRequest did not persist a valid pre-update snapshot.' );\n\t}\n\n\t$stale_hash = $post_base_hash( $page_id );\n\twp_update_post( [ 'ID' => $page_id, 'post_title' => 'EJB Local Newer' ] );\n\t$expect_runtime_exception(\n\t\tstatic function () use ( $posts, $page_id, $stale_hash ): void {\n\t\t\t$posts->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t\t\t'version'    => PostRequest::VERSION,\n\t\t\t\t\t'request_id' => 'runtime-post-stale-base',\n\t\t\t\t\t'action'     => 'update',\n\t\t\t\t\t'post_id'    => $page_id,\n\t\t\t\t\t'base_hash'  => $stale_hash,\n\t\t\t\t\t'post'       => [ 'title' => 'Must Not Overwrite Newer Local Edit' ],\n\t\t\t\t]\n\t\t\t);\n\t\t},\n\t\t'PostRequest accepted a stale base hash.'\n\t);\n\tif ( 'EJB Local Newer' !== get_the_title( $page_id ) ) {\n\t\tthrow new RuntimeException( 'A stale PostRequest overwrote a newer local edit.' );\n\t}\n\n\t$elementor_result",
)
replace_once(
    "tests/runtime/content-operations.php",
    "static function () use ( $posts, $page_id ): void {\n\t\t\t$posts->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t\t\t'version'    => PostRequest::VERSION,\n\t\t\t\t\t'request_id' => 'runtime-post-delete-no-confirm',\n\t\t\t\t\t'action'     => 'delete',\n\t\t\t\t\t'post_id'    => $page_id,",
    "static function () use ( $posts, $page_id, $post_base_hash ): void {\n\t\t\t$posts->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t\t\t'version'    => PostRequest::VERSION,\n\t\t\t\t\t'request_id' => 'runtime-post-delete-no-confirm',\n\t\t\t\t\t'action'     => 'delete',\n\t\t\t\t\t'post_id'    => $page_id,\n\t\t\t\t\t'base_hash'  => $post_base_hash( $page_id ),",
)
replace_once(
    "tests/runtime/content-operations.php",
    "\t\t\t'action'              => 'delete',\n\t\t\t'post_id'             => $page_id,\n\t\t\t'confirm_destructive' => true,",
    "\t\t\t'action'              => 'delete',\n\t\t\t'post_id'             => $page_id,\n\t\t\t'base_hash'           => $post_base_hash( $page_id ),\n\t\t\t'confirm_destructive' => true,",
)

# 7. Documentation: make the request precondition explicit.
replace_once(
    "README.md",
    "Actions: `create`, `update`, `delete`. New items are drafts. Delete requires `confirm_destructive=true`.",
    "Actions: `create`, `update`, `delete`. New items are drafts. Update/delete require `base_hash`, calculated from the exact canonical content JSON used to author the request; stale hashes fail closed. Delete additionally requires `confirm_destructive=true`.",
)
replace_once(
    "README.md",
    "6. Existing content uses fresh conflict checks, local integrity-checked snapshots, validation, supported APIs, full readback and verified rollback.",
    "6. Canonical content applies and `manage-post` update/delete requests use fresh conflict checks; `manage-post` also persists a local integrity-checked pre-mutation snapshot, followed by validation, supported APIs, readback and verified rollback.",
)

print("release blocker repair applied")
