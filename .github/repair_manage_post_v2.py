from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{path}: expected one match, found {count}: {old[:120]!r}')
    file.write_text(text.replace(old, new, 1), encoding='utf-8')


path = 'includes/Content/PostRequest.php'
replace_once(path, 'use DateTimeImmutable;\n', '')
replace_once(
    path,
    "\tpublic const FORMAT  = 'elementor-json-bridge/manage-post';\n\tpublic const VERSION = 1;",
    "\tpublic const FORMAT  = 'elementor-json-bridge/manage-post';\n\tpublic const VERSION = 2;\n\n\tprivate const CANONICAL_POST_FIELDS = [ 'title', 'slug', 'status', 'content', 'excerpt', 'parent', 'menu_order', 'comment_status', 'ping_status', 'page_template', 'featured_image' ];",
)
replace_once(
    path,
    "\t\t$post            = is_array( $request['post'] ?? null ) ? $request['post'] : [];\n\t\t$before_content  = $current_content;\n\t\t$before_extended = $this->extended_state( $id );\n\t\t$desired         = $this->desired_content( $id, $before_content, $post, $request, false );\n\t\t$this->validate_extended_post_fields( $id, $post );\n\t\t$snapshot_id = $this->snapshots()->create( $id, $before_content, 'before_request_update' );\n\n\t\ttry {\n\t\t\t$this->content->apply( $id, $desired );\n\t\t\t$this->apply_extended_post_fields( $id, $post );\n\t\t\t$this->verify_state( $id, $desired, $post );\n\t\t} catch ( \\Throwable $apply_error ) {\n\t\t\ttry {\n\t\t\t\t$this->content->apply( $id, $before_content );\n\t\t\t\t$this->apply_extended_post_fields( $id, $before_extended );\n\t\t\t\t$this->verify_state( $id, $before_content, $before_extended );\n\t\t\t} catch ( \\Throwable $rollback_error ) {",
    "\t\t$post           = is_array( $request['post'] ?? null ) ? $request['post'] : [];\n\t\t$before_content = $current_content;\n\t\t$snapshot_id   = $this->snapshots()->create( $id, $before_content, 'before_request_update' );\n\t\t$desired       = $this->desired_content( $id, $before_content, $post, $request, false );\n\n\t\ttry {\n\t\t\t$this->content->apply( $id, $desired );\n\t\t\t$this->verify_state( $id, $desired );\n\t\t} catch ( \\Throwable $apply_error ) {\n\t\t\ttry {\n\t\t\t\t$this->content->apply( $id, $before_content );\n\t\t\t\t$this->verify_state( $id, $before_content );\n\t\t\t} catch ( \\Throwable $rollback_error ) {",
)
replace_once(
    path,
    "\t\t\t$current = $this->content->payload( $id );\n\t\t\t$desired = $this->desired_content( $id, $current, $request_post, $request, true );\n\t\t\t$this->validate_extended_post_fields( $id, $request_post );\n\t\t\t$this->content->apply( $id, $desired );\n\t\t\t$this->apply_extended_post_fields( $id, $request_post );\n\t\t\t$this->verify_state( $id, $desired, $request_post );",
    "\t\t\t$current = $this->content->payload( $id );\n\t\t\t$desired = $this->desired_content( $id, $current, $request_post, $request, true );\n\t\t\t$this->content->apply( $id, $desired );\n\t\t\t$this->verify_state( $id, $desired );",
)
replace_once(
    path,
    "\t\t\t$current = $this->content->payload( $id );\n\t\t\t$request['elementor'] = $elementor;\n\t\t\t$desired = $this->desired_content( $id, $current, $request_post, $request, true );\n\t\t\t$this->validate_extended_post_fields( $id, $request_post );\n\t\t\t$this->content->apply( $id, $desired );\n\t\t\t$this->apply_extended_post_fields( $id, $request_post );\n\t\t\t$this->verify_state( $id, $desired, $request_post );",
    "\t\t\t$current = $this->content->payload( $id );\n\t\t\t$request['elementor'] = $elementor;\n\t\t\t$desired = $this->desired_content( $id, $current, $request_post, $request, true );\n\t\t\t$this->content->apply( $id, $desired );\n\t\t\t$this->verify_state( $id, $desired );",
)
replace_once(
    path,
    "\t\tforeach ( [ 'title', 'slug', 'status', 'content', 'excerpt', 'parent', 'menu_order', 'comment_status', 'ping_status', 'page_template', 'featured_image' ] as $field ) {",
    "\t\tforeach ( self::CANONICAL_POST_FIELDS as $field ) {",
)
start = "\tprivate function verify_state( int $id, array $expected_content, array $expected_extended ): void {"
end = "\n\tprivate function assert_base_hash( array $current, array $request ): void {"
text = Path(path).read_text(encoding='utf-8')
if text.count(start) != 1 or text.count(end) != 1:
    raise RuntimeError('PostRequest verify/extended block anchors changed')
left, rest = text.split(start, 1)
_, right = rest.split(end, 1)
replacement = "\tprivate function verify_state( int $id, array $expected_content ): void {\n\t\t$readback = $this->content->payload( $id );\n\t\tif ( ! hash_equals( CanonicalJson::hash( $expected_content ), CanonicalJson::hash( $readback ) ) ) {\n\t\t\tthrow new RuntimeException( 'WordPress content failed exact readback verification.' );\n\t\t}\n\t}\n"
Path(path).write_text(left + replacement + end + right, encoding='utf-8')

text = Path(path).read_text(encoding='utf-8')
start = "\tprivate function validate_request( array $request ): void {"
if text.count(start) != 1:
    raise RuntimeError('PostRequest validate_request anchor changed')
head, _ = text.split(start, 1)
validate = r'''\tprivate function validate_request( array $request ): void {
\t\t$allowed = [ 'format', 'version', 'request_id', 'action', 'post_id', 'post_type', 'post', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'base_hash', 'confirm_destructive', 'result' ];
\t\tif ( array_diff( array_keys( $request ), $allowed ) ) {
\t\t\tthrow new RuntimeException( 'The post request contains unsupported fields.' );
\t\t}
\t\tif ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
\t\t\tthrow new RuntimeException( 'The post request format or version is invalid. Regenerate legacy version-1 manage-post requests as version 2.' );
\t\t}
\t\tif ( ! in_array( (string) ( $request['action'] ?? '' ), [ 'create', 'update', 'delete' ], true ) ) {
\t\t\tthrow new RuntimeException( 'The post request action is invalid.' );
\t\t}
\t\tif ( 'create' === (string) $request['action'] ) {
\t\t\tif ( ! is_string( $request['post_type'] ?? null ) || ! is_array( $request['post'] ?? null ) ) {
\t\t\t\tthrow new RuntimeException( 'Creating content requires post_type and post.' );
\t\t\t}
\t\t} elseif ( (int) ( $request['post_id'] ?? 0 ) < 1 ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting content requires an exact post_id.' );
\t\t} elseif ( ! is_string( $request['base_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['base_hash'] ) ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting content requires a valid base_hash.' );
\t\t}
\t\tif ( isset( $request['post'] ) && ( ! is_array( $request['post'] ) || ( [] !== $request['post'] && array_is_list( $request['post'] ) ) ) ) {
\t\t\tthrow new RuntimeException( 'The post request post field must be an object.' );
\t\t}
\t\t$post = is_array( $request['post'] ?? null ) ? $request['post'] : [];
\t\tif ( array_diff( array_keys( $post ), self::CANONICAL_POST_FIELDS ) ) {
\t\t\tthrow new RuntimeException( 'manage-post version 2 accepts only canonical post fields. Author, date, password, format and sticky are outside the conflict/snapshot envelope and are not request-mutable.' );
\t\t}
\t}
}
'''.replace('\\t', '\t')
Path(path).write_text(head + validate, encoding='utf-8')

# Update manifest wording to make the protocol version explicit.
replace_once(
    'includes/Sync/ContentRequests.php',
    "'For manage-post update/delete requests, copy base_hash from the exact canonical content JSON you read. WordPress rejects stale hashes before any mutation.',",
    "'Use manage-post version 2. For update/delete, copy base_hash from the exact canonical content JSON you read; stale hashes fail closed. Version-1 manage-post requests must be regenerated.',",
)

# Runtime: legacy version rejection, canonical-only update, and raw stale-state verification.
runtime = 'tests/runtime/content-operations.php'
replace_once(
    runtime,
    "\t$before_title = get_the_title( $page_id );",
    "\t$before_title = (string) get_post_field( 'post_title', $page_id, 'raw' );\n\t$expect_runtime_exception(\n\t\tstatic function () use ( $posts, $page_id, $post_base_hash ): void {\n\t\t\t$posts->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => PostRequest::FORMAT,\n\t\t\t\t\t'version'    => 1,\n\t\t\t\t\t'request_id' => 'runtime-post-legacy-v1',\n\t\t\t\t\t'action'     => 'update',\n\t\t\t\t\t'post_id'    => $page_id,\n\t\t\t\t\t'base_hash'  => $post_base_hash( $page_id ),\n\t\t\t\t\t'post'       => [ 'title' => 'Must Not Apply Legacy V1' ],\n\t\t\t\t]\n\t\t\t);\n\t\t},\n\t\t'PostRequest accepted legacy version 1 after the version-2 safety migration.'\n\t);",
)
replace_once(
    runtime,
    "\tif ( get_the_title( $page_id ) !== $before_title ) {",
    "\tif ( (string) get_post_field( 'post_title', $page_id, 'raw' ) !== $before_title ) {",
)
replace_once(
    runtime,
    "\t\t\t\t\t'post'       => [ 'title' => 'Must Not Persist', 'author' => 999999999 ],",
    "\t\t\t\t\t'post'       => [ 'title' => 'Must Not Persist', 'password' => 'not-request-mutable' ],",
)
replace_once(
    runtime,
    "\t\t'PostRequest accepted an invalid author.'",
    "\t\t'PostRequest accepted a non-canonical extended field.'",
)
replace_once(
    runtime,
    "\t\t\t\t'title'    => 'EJB Request Page Updated',\n\t\t\t\t'content'  => '<p>Request page after.</p>',\n\t\t\t\t'password' => 'runtime-pass',",
    "\t\t\t\t'title'   => 'EJB Request Page Updated',\n\t\t\t\t'content' => '<p>Request page after.</p>',",
)
replace_once(
    runtime,
    "\tif ( ! $page instanceof WP_Post || 'EJB Request Page Updated' !== $page->post_title || 'runtime-pass' !== $page->post_password ) {",
    "\tif ( ! $page instanceof WP_Post || 'EJB Request Page Updated' !== $page->post_title ) {",
)
replace_once(
    runtime,
    "\tif ( 'EJB Local Newer' !== get_the_title( $page_id ) ) {",
    "\tif ( 'EJB Local Newer' !== (string) get_post_field( 'post_title', $page_id, 'raw' ) ) {",
)

# Static regression coverage for the new contract and ordering.
replace_once(
    'tests/content-request-safety.php',
    "$assert(str_contains($posts, \"'base_hash'\") && str_contains($posts, 'assert_base_hash(') && str_contains($posts, 'CanonicalJson::hash( $current )'), 'manage-post stale-request protection is missing.');",
    "$assert(str_contains($posts, 'public const VERSION = 2') && str_contains($posts, \"'base_hash'\") && str_contains($posts, 'assert_base_hash(') && str_contains($posts, 'CanonicalJson::hash( $current )'), 'manage-post version-2 stale-request protection is missing.');\n$assert(str_contains($posts, 'CANONICAL_POST_FIELDS') && str_contains($posts, 'Author, date, password, format and sticky are outside the conflict/snapshot envelope'), 'manage-post v2 does not fail closed on non-canonical extended fields.');\n$assert(strpos($posts, \"before_request_update\") < strpos($posts, 'desired_content( $id, $before_content'), 'manage-post update snapshot is not created before validation.');",
)
replace_once(
    'tests/wordpress-content-sync.php',
    "$assert(str_contains($posts, \"'base_hash'\") && str_contains($posts, 'before_request_update') && str_contains($posts, 'before_request_delete'), 'Post request conflict/snapshot protection is incomplete.');",
    "$assert(str_contains($posts, 'public const VERSION = 2') && str_contains($posts, \"'base_hash'\") && str_contains($posts, 'before_request_update') && str_contains($posts, 'before_request_delete'), 'Post request v2 conflict/snapshot protection is incomplete.');\n$assert(str_contains($posts, 'CANONICAL_POST_FIELDS') && ! str_contains($posts, 'apply_extended_post_fields('), 'Post request v2 still mutates state outside the canonical conflict/snapshot envelope.');",
)

# README follows the latest portfolio wording but documents the v2 migration.
readme = 'README.md'
replace_once(readme, '`elementor-json-bridge/manage-post`, version `1`', '`elementor-json-bridge/manage-post`, version `2`')
replace_once(
    readme,
    'Actions: `create`, `update`, `delete`. New items are drafts. Update/delete require `base_hash`, calculated from the exact canonical content JSON used to author the request; stale hashes fail closed. Delete additionally requires `confirm_destructive=true`.',
    'Actions: `create`, `update`, `delete`. New items are drafts. Version 2 is a safety migration: pending version-1 `manage-post` files must be regenerated. Update/delete require `base_hash`, calculated from the exact canonical content JSON used to author the request; stale hashes fail closed. Delete additionally requires `confirm_destructive=true`. The `post` object accepts only fields present in the canonical content envelope; `author`, `date`, `password`, `format` and `sticky` are deliberately not request-mutable because they are outside that conflict/snapshot envelope.',
)

print('manage-post v2 safety repair applied')
