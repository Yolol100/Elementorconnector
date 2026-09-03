from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{path}: expected one match, found {count}: {old[:120]!r}')
    file.write_text(text.replace(old, new, 1), encoding='utf-8')


def replace_between(path: str, start: str, end: str, replacement: str) -> None:
    file = Path(path)
    text = file.read_text(encoding='utf-8')
    if text.count(start) != 1 or text.count(end) < 1:
        raise RuntimeError(f'{path}: block anchors changed: {start!r} / {end!r}')
    before, rest = text.split(start, 1)
    old_body, after = rest.split(end, 1)
    file.write_text(before + replacement + end + after, encoding='utf-8')


# ---------------------------------------------------------------------------
# ProductRequest v2: read handshake, complete conflict token, durable snapshot.
# ---------------------------------------------------------------------------
path = 'includes/Content/ProductRequest.php'
replace_once(
    path,
    'use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Support\\CanonicalJson;',
    'use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Backup\\OperationSnapshots;\nuse Webactueel\\ElementorJsonBridge\\Support\\CanonicalJson;',
)
replace_once(path, "\tpublic const VERSION = 1;", "\tpublic const VERSION = 2;")

execute = r'''\tpublic function execute( array $request ): array {
\t\t$this->validate_request( $request );
\t\t$action = (string) $request['action'];
\t\t$core   = is_array( $request['post'] ?? null ) ? $request['post'] : [];
\t\t$woo    = is_array( $request['woocommerce'] ?? null ) ? $request['woocommerce'] : [];

\t\tif ( 'create' === $action ) {
\t\t\tif ( ! current_user_can( 'edit_products' ) ) {
\t\t\t\tthrow new RuntimeException( 'You are not allowed to create WooCommerce products.' );
\t\t\t}
\t\t\t$title = isset( $core['title'] ) && is_string( $core['title'] ) ? trim( $core['title'] ) : '';
\t\t\tif ( '' === $title ) {
\t\t\t\tthrow new RuntimeException( 'Creating a WooCommerce product requires post.title.' );
\t\t\t}
\t\t\t$type = isset( $woo['type'] ) && is_string( $woo['type'] ) ? $woo['type'] : 'simple';
\t\t\t$id   = $this->products->create( $type, $title );
\t\t\ttry {
\t\t\t\t$this->apply_create( $id, $core, $woo, $request );
\t\t\t} catch ( \\Throwable $throwable ) {
\t\t\t\t$product = wc_get_product( $id );
\t\t\t\tif ( $product instanceof \\WC_Product ) {
\t\t\t\t\t$product->delete( true );
\t\t\t\t}
\t\t\t\tthrow $throwable;
\t\t\t}
\t\t\treturn $this->result( 'created', $id );
\t\t}

\t\t$id = (int) ( $request['product_id'] ?? 0 );
\t\tif ( ! $this->products->supports( $id ) ) {
\t\t\tthrow new RuntimeException( 'The requested WooCommerce product does not exist.' );
\t\t}
\t\tif ( ! current_user_can( 'edit_post', $id ) ) {
\t\t\tthrow new RuntimeException( 'You are not allowed to edit this WooCommerce product.' );
\t\t}
\t\t$before = $this->state( $id );
\t\tif ( 'read' === $action ) {
\t\t\treturn $this->result( 'read', $id );
\t\t}
\t\t$this->assert_base_hash( $before, $request );
\t\t$snapshot_id = $this->operation_snapshots()->create( 'woocommerce_product', 'product:' . $id, $before, 'before_product_' . $action );
\t\tif ( 'delete' === $action ) {
\t\t\treturn $this->delete_product( $id, $request, $snapshot_id );
\t\t}

\t\t$this->apply_update( $id, $core, $woo, $request, $snapshot_id );
\t\t$result = $this->result( 'updated', $id );
\t\t$result['snapshot_id'] = $snapshot_id;
\t\treturn $result;
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tpublic function execute( array $request ): array {', '\tprivate function apply_create', execute)

replace_once(
    path,
    '\t\t$this->verify_state( $id, $desired_content, $desired_woo, $core );',
    "\t\t$this->verify_state( $id, [ 'content' => $desired_content, 'woocommerce' => $desired_woo ] );",
)

apply_update = r'''\tprivate function apply_update( int $id, array $core, array $woo, array $request, int $snapshot_id ): void {
\t\t$before = $this->operation_snapshots()->payload( $snapshot_id, 'woocommerce_product', 'product:' . $id );
\t\ttry {
\t\t\t$desired_content = $this->desired_content( $id, $before['content'], $core, $request, false );
\t\t\t$desired_woo     = $this->desired_woo( $id, $before['woocommerce'], $woo );
\t\t\t$desired_content = $this->align_product_brand_taxonomy( $id, $desired_content, $desired_woo );
\t\t\t$desired         = [ 'content' => $desired_content, 'woocommerce' => $desired_woo ];

\t\t\t$this->validate_core_extras( $core, false );
\t\t\t$this->apply_core( $id, $core, false );
\t\t\t$this->apply_woo( $id, $desired_woo );
\t\t\t$this->content->apply( $id, $desired_content );
\t\t\t$this->verify_state( $id, $desired );
\t\t} catch ( \\Throwable $apply_error ) {
\t\t\ttry {
\t\t\t\t$rollback = $this->operation_snapshots()->payload( $snapshot_id, 'woocommerce_product', 'product:' . $id );
\t\t\t\t$this->restore_state( $id, $rollback );
\t\t\t} catch ( \\Throwable $rollback_error ) {
\t\t\t\tthrow new RuntimeException( 'WooCommerce product update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
\t\t\t}
\t\t\tthrow new RuntimeException( 'WooCommerce product update failed. The durable pre-update state was restored.', 0, $apply_error );
\t\t}
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tprivate function apply_update(', '\tprivate function desired_content', apply_update)

state_block = r'''\tprivate function state( int $id ): array {
\t\treturn [
\t\t\t'content'     => $this->content->payload( $id ),
\t\t\t'woocommerce' => $this->woo_payload( $id ),
\t\t];
\t}

\tprivate function verify_state( int $id, array $expected ): void {
\t\tif ( ! hash_equals( CanonicalJson::hash( $expected ), CanonicalJson::hash( $this->state( $id ) ) ) ) {
\t\t\tthrow new RuntimeException( 'WooCommerce product state failed exact readback verification.' );
\t\t}
\t}

\tprivate function restore_state( int $id, array $state ): void {
\t\tif ( ! isset( $state['content'], $state['woocommerce'] ) || ! is_array( $state['content'] ) || ! is_array( $state['woocommerce'] ) ) {
\t\t\tthrow new RuntimeException( 'The durable WooCommerce product snapshot is invalid.' );
\t\t}
\t\t$this->content->apply( $id, $state['content'] );
\t\t$this->apply_woo( $id, $state['woocommerce'] );
\t\t$this->verify_state( $id, $state );
\t}

\tprivate function assert_base_hash( array $state, array $request ): void {
\t\t$base_hash = (string) ( $request['base_hash'] ?? '' );
\t\tif ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $base_hash ) ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting a product requires a valid base_hash.' );
\t\t}
\t\tif ( ! hash_equals( $base_hash, CanonicalJson::hash( $state ) ) ) {
\t\t\tthrow new RuntimeException( 'The WooCommerce product changed after this request was authored. Read the product again and create a new request.' );
\t\t}
\t}

\tprivate function operation_snapshots(): OperationSnapshots {
\t\treturn new OperationSnapshots();
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tprivate function verify_state(', '\tprivate function delete_product', state_block)

delete_product = r'''\tprivate function delete_product( int $id, array $request, int $snapshot_id ): array {
\t\tif ( true !== ( $request['confirm_destructive'] ?? false ) ) {
\t\t\tthrow new RuntimeException( 'Deleting a WooCommerce product requires confirm_destructive=true.' );
\t\t}
\t\tif ( ! current_user_can( 'delete_post', $id ) ) {
\t\t\tthrow new RuntimeException( 'You are not allowed to delete this WooCommerce product.' );
\t\t}
\t\t$force   = (bool) ( $request['force'] ?? false );
\t\t$product = wc_get_product( $id );
\t\tif ( ! $product instanceof \\WC_Product ) {
\t\t\tthrow new RuntimeException( 'The requested WooCommerce product no longer exists.' );
\t\t}
\t\tif ( ! $force ) {
\t\t\t$supports_trash = apply_filters( 'woocommerce_product_object_trashable', EMPTY_TRASH_DAYS > 0, $product );
\t\t\tif ( ! $supports_trash ) {
\t\t\t\tthrow new RuntimeException( 'WooCommerce trash is disabled for this product. Use force=true only when permanent deletion is intended.' );
\t\t\t}
\t\t}
\t\t$deleted = $product->delete( $force );
\t\tif ( ! $deleted ) {
\t\t\tthrow new RuntimeException( 'WooCommerce could not delete the requested product.' );
\t\t}
\t\tif ( $force ) {
\t\t\tif ( null !== get_post( $id ) ) {
\t\t\t\tthrow new RuntimeException( 'WooCommerce permanent product deletion failed readback verification.' );
\t\t\t}
\t\t} elseif ( 'trash' !== get_post_status( $id ) ) {
\t\t\tthrow new RuntimeException( 'WooCommerce product trash failed readback verification.' );
\t\t}
\t\treturn [ 'status' => 'deleted', 'product_id' => $id, 'force' => $force, 'snapshot_id' => $snapshot_id ];
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tprivate function delete_product(', '\tprivate function result', delete_product)

result = r'''\tprivate function result( string $status, int $id ): array {
\t\t$product = wc_get_product( $id );
\t\t$state   = $this->state( $id );
\t\treturn [
\t\t\t'status'      => $status,
\t\t\t'product_id'  => $id,
\t\t\t'base_hash'   => CanonicalJson::hash( $state ),
\t\t\t'post'        => [
\t\t\t\t'title'          => $product instanceof \\WC_Product ? (string) $product->get_name( 'edit' ) : '',
\t\t\t\t'status'         => $product instanceof \\WC_Product ? (string) $product->get_status( 'edit' ) : '',
\t\t\t\t'content'        => $product instanceof \\WC_Product ? (string) $product->get_description( 'edit' ) : '',
\t\t\t\t'excerpt'        => $product instanceof \\WC_Product ? (string) $product->get_short_description( 'edit' ) : '',
\t\t\t\t'featured_image' => $product instanceof \\WC_Product ? (int) $product->get_image_id( 'edit' ) : 0,
\t\t\t],
\t\t\t'woocommerce' => $state['woocommerce'],
\t\t];
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tprivate function result(', '\tprivate function validate_request', result)

validate = r'''\tprivate function validate_request( array $request ): void {
\t\t$allowed = [ 'format', 'version', 'request_id', 'action', 'product_id', 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'base_hash', 'confirm_destructive', 'force', 'result' ];
\t\tif ( array_diff( array_keys( $request ), $allowed ) ) {
\t\t\tthrow new RuntimeException( 'The product request contains unsupported fields.' );
\t\t}
\t\tif ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
\t\t\tthrow new RuntimeException( 'The product request format or version is invalid. Regenerate legacy version-1 manage-product requests as version 2.' );
\t\t}
\t\t$action = (string) ( $request['action'] ?? '' );
\t\tif ( ! in_array( $action, [ 'create', 'read', 'update', 'delete' ], true ) ) {
\t\t\tthrow new RuntimeException( 'The product request action is invalid.' );
\t\t}
\t\tif ( 'create' !== $action && (int) ( $request['product_id'] ?? 0 ) < 1 ) {
\t\t\tthrow new RuntimeException( 'Reading, updating or deleting a product requires an exact product_id.' );
\t\t}
\t\tif ( in_array( $action, [ 'update', 'delete' ], true ) && ( ! is_string( $request['base_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['base_hash'] ) ) ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting a product requires a valid base_hash from a version-2 read result.' );
\t\t}
\t\tif ( 'read' === $action && array_intersect( [ 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta', 'elementor', 'force', 'confirm_destructive' ], array_keys( $request ) ) ) {
\t\t\tthrow new RuntimeException( 'A product read request cannot contain mutation fields.' );
\t\t}
\t\tforeach ( [ 'post', 'woocommerce', 'taxonomies', 'acf', 'yoast', 'registered_meta' ] as $field ) {
\t\t\tif ( isset( $request[ $field ] ) && ( ! is_array( $request[ $field ] ) || ( [] !== $request[ $field ] && array_is_list( $request[ $field ] ) ) ) ) {
\t\t\t\tthrow new RuntimeException( 'Product request objects must use named fields.' );
\t\t\t}
\t\t}
\t\tif ( array_key_exists( 'force', $request ) && ! is_bool( $request['force'] ) ) {
\t\t\tthrow new RuntimeException( 'Product force must be a boolean.' );
\t\t}
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tprivate function validate_request(', '\tprivate function validate_core_extras', validate)

replace_once(
    path,
    "\t\t$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'featured_image', 'menu_order', 'comment_status', 'password' ];",
    "\t\t$allowed = [ 'title', 'slug', 'status', 'content', 'excerpt', 'featured_image', 'menu_order', 'comment_status' ];",
)
replace_once(
    path,
    "\t\tif ( array_key_exists( 'password', $data ) && ! is_string( $data['password'] ) ) {\n\t\t\tthrow new RuntimeException( 'A product password must be a string.' );\n\t\t}\n",
    '',
)
replace_once(
    path,
    "\t\tforeach ( [ 'title', 'slug', 'content', 'excerpt', 'comment_status', 'password' ] as $field ) {",
    "\t\tforeach ( [ 'title', 'slug', 'content', 'excerpt', 'comment_status' ] as $field ) {",
)
replace_once(
    path,
    "\t\tif ( array_key_exists( 'password', $data ) ) {\n\t\t\tif ( ! method_exists( $product, 'set_post_password' ) ) {\n\t\t\t\tthrow new RuntimeException( 'This WooCommerce version does not support product passwords.' );\n\t\t\t}\n\t\t\t$product->set_post_password( $data['password'] );\n\t\t}\n",
    '',
)

# ---------------------------------------------------------------------------
# TaxonomyTerm v2.
# ---------------------------------------------------------------------------
path = 'includes/Content/TaxonomyTerm.php'
replace_once(
    path,
    'use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Support\\CanonicalJson;',
    'use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Backup\\OperationSnapshots;\nuse Webactueel\\ElementorJsonBridge\\Support\\CanonicalJson;',
)
replace_once(path, "\tpublic const VERSION = 1;", "\tpublic const VERSION = 2;")

term_execute = r'''\tpublic function execute( array $request ): array {
\t\t$this->validate_request( $request );
\t\t$taxonomy = sanitize_key( (string) $request['taxonomy'] );
\t\t$object   = get_taxonomy( $taxonomy );
\t\tif ( ! $object || empty( $object->show_ui ) ) {
\t\t\tthrow new RuntimeException( 'The requested taxonomy is not managed by WordPress.' );
\t\t}

\t\t$action = (string) $request['action'];
\t\t$data   = is_array( $request['data'] ?? null ) ? $request['data'] : [];
\t\tif ( 'create' === $action ) {
\t\t\tif ( ! current_user_can( $object->cap->manage_terms ) ) {
\t\t\t\tthrow new RuntimeException( 'You are not allowed to create terms in this taxonomy.' );
\t\t\t}
\t\t\t$name = isset( $data['name'] ) && is_string( $data['name'] ) ? trim( $data['name'] ) : '';
\t\t\tif ( '' === $name ) {
\t\t\t\tthrow new RuntimeException( 'Creating a taxonomy term requires a name.' );
\t\t\t}
\t\t\t$args   = $this->core_args( $data, true );
\t\t\t$result = wp_insert_term( $name, $taxonomy, $args );
\t\t\tif ( is_wp_error( $result ) ) {
\t\t\t\tthrow new RuntimeException( 'WordPress rejected the taxonomy term creation.' );
\t\t\t}
\t\t\t$term_id = (int) $result['term_id'];
\t\t\ttry {
\t\t\t\t$this->apply_extensions( $term_id, $taxonomy, $data );
\t\t\t\t$readback = $this->payload( $term_id, $taxonomy );
\t\t\t\t$this->assert_requested_state( $readback, $data );
\t\t\t} catch ( \\Throwable $throwable ) {
\t\t\t\twp_delete_term( $term_id, $taxonomy );
\t\t\t\tthrow $throwable;
\t\t\t}
\t\t\treturn $this->result( 'created', $taxonomy, $term_id, $readback );
\t\t}

\t\t$term_id = (int) ( $request['term_id'] ?? 0 );
\t\t$term    = get_term( $term_id, $taxonomy );
\t\tif ( ! $term instanceof \\WP_Term ) {
\t\t\tthrow new RuntimeException( 'The requested taxonomy term does not exist.' );
\t\t}
\t\t$before = $this->payload( $term_id, $taxonomy );
\t\tif ( 'read' === $action ) {
\t\t\tif ( ! current_user_can( $object->cap->edit_terms ) ) {
\t\t\t\tthrow new RuntimeException( 'You are not allowed to read managed term state in this taxonomy.' );
\t\t\t}
\t\t\treturn $this->result( 'read', $taxonomy, $term_id, $before );
\t\t}
\t\t$this->assert_base_hash( $before, $request );
\t\tif ( 'delete' === $action ) {
\t\t\tif ( ! current_user_can( $object->cap->delete_terms ) ) {
\t\t\t\tthrow new RuntimeException( 'You are not allowed to delete terms in this taxonomy.' );
\t\t\t}
\t\t\tif ( true !== ( $request['confirm_destructive'] ?? false ) ) {
\t\t\t\tthrow new RuntimeException( 'Deleting a taxonomy term requires confirm_destructive=true.' );
\t\t\t}
\t\t\t$snapshot_id = $this->operation_snapshots()->create( 'taxonomy_term', $taxonomy . ':' . $term_id, $before, 'before_term_delete' );
\t\t\t$result = wp_delete_term( $term_id, $taxonomy );
\t\t\tif ( true !== $result || get_term( $term_id, $taxonomy ) instanceof \\WP_Term ) {
\t\t\t\tthrow new RuntimeException( 'WordPress could not verify deletion of the requested taxonomy term.' );
\t\t\t}
\t\t\treturn [ 'status' => 'deleted', 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'snapshot_id' => $snapshot_id ];
\t\t}

\t\tif ( ! current_user_can( $object->cap->edit_terms ) ) {
\t\t\tthrow new RuntimeException( 'You are not allowed to edit terms in this taxonomy.' );
\t\t}
\t\t$snapshot_id = $this->operation_snapshots()->create( 'taxonomy_term', $taxonomy . ':' . $term_id, $before, 'before_term_update' );
\t\ttry {
\t\t\t$this->validate_extensions( $term_id, $taxonomy, $data );
\t\t\t$core = $this->core_args( $data, false );
\t\t\tif ( isset( $data['name'] ) ) {
\t\t\t\tif ( ! is_string( $data['name'] ) || '' === trim( $data['name'] ) ) {
\t\t\t\t\tthrow new RuntimeException( 'A taxonomy term name cannot be empty.' );
\t\t\t\t}
\t\t\t\t$core['name'] = $data['name'];
\t\t\t}
\t\t\tif ( $core ) {
\t\t\t\t$result = wp_update_term( $term_id, $taxonomy, $core );
\t\t\t\tif ( is_wp_error( $result ) ) {
\t\t\t\t\tthrow new RuntimeException( 'WordPress rejected the taxonomy term update.' );
\t\t\t\t}
\t\t\t}
\t\t\t$this->apply_extensions( $term_id, $taxonomy, $data );
\t\t\t$readback = $this->payload( $term_id, $taxonomy );
\t\t\t$this->assert_requested_state( $readback, $data );
\t\t} catch ( \\Throwable $apply_error ) {
\t\t\ttry {
\t\t\t\t$rollback = $this->operation_snapshots()->payload( $snapshot_id, 'taxonomy_term', $taxonomy . ':' . $term_id );
\t\t\t\t$this->restore_state( $term_id, $taxonomy, $rollback );
\t\t\t} catch ( \\Throwable $rollback_error ) {
\t\t\t\tthrow new RuntimeException( 'Taxonomy update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
\t\t\t}
\t\t\tthrow new RuntimeException( 'Taxonomy update failed. The durable previous term state was restored.', 0, $apply_error );
\t\t}
\t\t$result = $this->result( 'updated', $taxonomy, $term_id, $readback );
\t\t$result['snapshot_id'] = $snapshot_id;
\t\treturn $result;
\t}

\tprivate function result( string $status, string $taxonomy, int $term_id, array $data ): array {
\t\treturn [ 'status' => $status, 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'base_hash' => CanonicalJson::hash( $data ), 'data' => $data ];
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tpublic function execute( array $request ): array {', '\tpublic function inventory', term_execute)

term_helpers = r'''\tprivate function assert_base_hash( array $state, array $request ): void {
\t\t$base_hash = (string) ( $request['base_hash'] ?? '' );
\t\tif ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $base_hash ) ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting a taxonomy term requires a valid base_hash.' );
\t\t}
\t\tif ( ! hash_equals( $base_hash, CanonicalJson::hash( $state ) ) ) {
\t\t\tthrow new RuntimeException( 'The taxonomy term changed after this request was authored. Read the term again and create a new request.' );
\t\t}
\t}

\tprivate function restore_state( int $term_id, string $taxonomy, array $state ): void {
\t\t$result = wp_update_term(
\t\t\t$term_id,
\t\t\t$taxonomy,
\t\t\t[
\t\t\t\t'name'        => $state['name'],
\t\t\t\t'slug'        => $state['slug'],
\t\t\t\t'description' => $state['description'],
\t\t\t\t'parent'      => $state['parent'],
\t\t\t]
\t\t);
\t\tif ( is_wp_error( $result ) ) {
\t\t\tthrow new RuntimeException( 'WordPress rejected taxonomy rollback.' );
\t\t}
\t\t$this->apply_acf( $term_id, $taxonomy, $state['acf'] );
\t\t$this->apply_yoast( $term_id, $taxonomy, $state['yoast'] );
\t\tif ( ! hash_equals( CanonicalJson::hash( $state ), CanonicalJson::hash( $this->payload( $term_id, $taxonomy ) ) ) ) {
\t\t\tthrow new RuntimeException( 'Taxonomy rollback failed exact readback verification.' );
\t\t}
\t}

\tprivate function operation_snapshots(): OperationSnapshots {
\t\treturn new OperationSnapshots();
\t}

'''.replace('\\t', '\t')
replace_once(path, '\tprivate function validate_request( array $request ): void {', term_helpers + '\tprivate function validate_request( array $request ): void {')

term_validate = r'''\tprivate function validate_request( array $request ): void {
\t\t$allowed = [ 'format', 'version', 'request_id', 'action', 'taxonomy', 'term_id', 'data', 'base_hash', 'confirm_destructive', 'result' ];
\t\tif ( array_diff( array_keys( $request ), $allowed ) ) {
\t\t\tthrow new RuntimeException( 'The taxonomy term request contains unsupported fields.' );
\t\t}
\t\tif ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
\t\t\tthrow new RuntimeException( 'The taxonomy term request format or version is invalid. Regenerate legacy version-1 manage-term requests as version 2.' );
\t\t}
\t\t$action = (string) ( $request['action'] ?? '' );
\t\tif ( ! in_array( $action, [ 'create', 'read', 'update', 'delete' ], true ) || '' === sanitize_key( (string) ( $request['taxonomy'] ?? '' ) ) ) {
\t\t\tthrow new RuntimeException( 'The taxonomy term request action or taxonomy is invalid.' );
\t\t}
\t\tif ( 'create' !== $action && (int) ( $request['term_id'] ?? 0 ) < 1 ) {
\t\t\tthrow new RuntimeException( 'Reading, updating or deleting a term requires an exact term_id.' );
\t\t}
\t\tif ( in_array( $action, [ 'update', 'delete' ], true ) && ( ! is_string( $request['base_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['base_hash'] ) ) ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting a term requires a valid base_hash from a version-2 read result.' );
\t\t}
\t\tif ( isset( $request['data'] ) && ( ! is_array( $request['data'] ) || ( [] !== $request['data'] && array_is_list( $request['data'] ) ) ) ) {
\t\t\tthrow new RuntimeException( 'Taxonomy term data must be an object.' );
\t\t}
\t\tif ( 'read' === $action && ( array_key_exists( 'data', $request ) || array_key_exists( 'confirm_destructive', $request ) ) ) {
\t\t\tthrow new RuntimeException( 'A taxonomy term read request cannot contain mutation fields.' );
\t\t}
\t\t$data = is_array( $request['data'] ?? null ) ? $request['data'] : [];
\t\tif ( array_diff( array_keys( $data ), [ 'name', 'slug', 'description', 'parent', 'acf', 'yoast' ] ) ) {
\t\t\tthrow new RuntimeException( 'The taxonomy term request contains unsupported data fields.' );
\t\t}
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tprivate function validate_request( array $request ): void {', '\tprivate function core_args', term_validate)

# ---------------------------------------------------------------------------
# ProductVariation v2.
# ---------------------------------------------------------------------------
path = 'includes/Content/ProductVariation.php'
replace_once(
    path,
    'use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Support\\CanonicalJson;',
    'use RuntimeException;\nuse Webactueel\\ElementorJsonBridge\\Backup\\OperationSnapshots;\nuse Webactueel\\ElementorJsonBridge\\Support\\CanonicalJson;',
)
replace_once(path, "\tpublic const VERSION = 1;", "\tpublic const VERSION = 2;")

variation_execute = r'''\tpublic function execute( array $request ): array {
\t\t$this->validate_request( $request );
\t\t$action     = (string) $request['action'];
\t\t$product_id = (int) $request['product_id'];
\t\t$this->parent( $product_id );

\t\tif ( ! current_user_can( 'edit_post', $product_id ) ) {
\t\t\tthrow new RuntimeException( 'You are not allowed to edit this variable product.' );
\t\t}

\t\tif ( 'create' === $action ) {
\t\t\t$variation = new \\WC_Product_Variation();
\t\t\t$variation->set_parent_id( $product_id );
\t\t\t$variation->set_status( 'publish' );
\t\t\ttry {
\t\t\t\t$this->apply_data( $variation, (array) ( $request['data'] ?? [] ) );
\t\t\t\t$id = (int) $variation->save();
\t\t\t\tif ( $id < 1 ) {
\t\t\t\t\tthrow new RuntimeException( 'WooCommerce did not return a variation ID.' );
\t\t\t\t}
\t\t\t\t$data = $this->payload( $variation );
\t\t\t\t$this->assert_requested_state( $data, (array) ( $request['data'] ?? [] ) );
\t\t\t} catch ( \\Throwable $throwable ) {
\t\t\t\tif ( $variation->get_id() > 0 ) {
\t\t\t\t\t$variation->delete( true );
\t\t\t\t}
\t\t\t\tthrow $throwable;
\t\t\t}
\t\t\treturn $this->result( 'created', $product_id, $id, $data );
\t\t}

\t\t$variation_id = (int) ( $request['variation_id'] ?? 0 );
\t\t$variation    = wc_get_product( $variation_id );
\t\tif ( ! $variation instanceof \\WC_Product_Variation || (int) $variation->get_parent_id() !== $product_id ) {
\t\t\tthrow new RuntimeException( 'The requested WooCommerce variation does not belong to this product.' );
\t\t}
\t\tif ( ! current_user_can( 'edit_post', $variation_id ) ) {
\t\t\tthrow new RuntimeException( 'You are not allowed to edit this WooCommerce variation.' );
\t\t}
\t\t$before = $this->payload( $variation );
\t\tif ( 'read' === $action ) {
\t\t\treturn $this->result( 'read', $product_id, $variation_id, $before );
\t\t}
\t\t$this->assert_base_hash( $before, $request );
\t\t$snapshot_id = $this->operation_snapshots()->create( 'product_variation', $product_id . ':' . $variation_id, $before, 'before_variation_' . $action );

\t\tif ( 'delete' === $action ) {
\t\t\tif ( true !== ( $request['confirm_destructive'] ?? false ) ) {
\t\t\t\tthrow new RuntimeException( 'Deleting a WooCommerce variation requires confirm_destructive=true.' );
\t\t\t}
\t\t\tif ( ! current_user_can( 'delete_post', $variation_id ) ) {
\t\t\t\tthrow new RuntimeException( 'You are not allowed to delete this WooCommerce variation.' );
\t\t\t}
\t\t\t$variation->delete( true );
\t\t\tif ( null !== get_post( $variation_id ) ) {
\t\t\t\tthrow new RuntimeException( 'WooCommerce variation deletion failed readback verification.' );
\t\t\t}
\t\t\treturn [ 'status' => 'deleted', 'product_id' => $product_id, 'variation_id' => $variation_id, 'snapshot_id' => $snapshot_id ];
\t\t}

\t\ttry {
\t\t\t$this->apply_data( $variation, (array) ( $request['data'] ?? [] ) );
\t\t\t$variation->save();
\t\t\t$data = $this->payload( $variation );
\t\t\t$this->assert_requested_state( $data, (array) ( $request['data'] ?? [] ) );
\t\t} catch ( \\Throwable $apply_error ) {
\t\t\ttry {
\t\t\t\t$rollback = $this->operation_snapshots()->payload( $snapshot_id, 'product_variation', $product_id . ':' . $variation_id );
\t\t\t\t$this->apply_data( $variation, $rollback );
\t\t\t\t$variation->save();
\t\t\t\tif ( ! hash_equals( CanonicalJson::hash( $rollback ), CanonicalJson::hash( $this->payload( $variation ) ) ) ) {
\t\t\t\t\tthrow new RuntimeException( 'WooCommerce variation rollback failed exact readback verification.' );
\t\t\t\t}
\t\t\t} catch ( \\Throwable $rollback_error ) {
\t\t\t\tthrow new RuntimeException( 'WooCommerce variation update failed and rollback could not be verified: ' . $rollback_error->getMessage(), 0, $apply_error );
\t\t\t}
\t\t\tthrow new RuntimeException( 'WooCommerce variation update failed. The durable previous variation state was restored.', 0, $apply_error );
\t\t}
\t\t$result = $this->result( 'updated', $product_id, $variation_id, $data );
\t\t$result['snapshot_id'] = $snapshot_id;
\t\treturn $result;
\t}

\tprivate function result( string $status, int $product_id, int $variation_id, array $data ): array {
\t\treturn [ 'status' => $status, 'product_id' => $product_id, 'variation_id' => $variation_id, 'base_hash' => CanonicalJson::hash( $data ), 'data' => $data ];
\t}

\tprivate function assert_base_hash( array $state, array $request ): void {
\t\t$base_hash = (string) ( $request['base_hash'] ?? '' );
\t\tif ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $base_hash ) ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting a variation requires a valid base_hash.' );
\t\t}
\t\tif ( ! hash_equals( $base_hash, CanonicalJson::hash( $state ) ) ) {
\t\t\tthrow new RuntimeException( 'The WooCommerce variation changed after this request was authored. Read it again and create a new request.' );
\t\t}
\t}

\tprivate function operation_snapshots(): OperationSnapshots {
\t\treturn new OperationSnapshots();
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tpublic function execute( array $request ): array {', '\tprivate function validate_request', variation_execute)

variation_validate = r'''\tprivate function validate_request( array $request ): void {
\t\t$allowed = [ 'format', 'version', 'request_id', 'action', 'product_id', 'variation_id', 'data', 'base_hash', 'confirm_destructive', 'result' ];
\t\tif ( array_diff( array_keys( $request ), $allowed ) ) {
\t\t\tthrow new RuntimeException( 'The variation request contains unsupported fields.' );
\t\t}
\t\tif ( self::FORMAT !== ( $request['format'] ?? null ) || self::VERSION !== (int) ( $request['version'] ?? 0 ) ) {
\t\t\tthrow new RuntimeException( 'The variation request format or version is invalid. Regenerate legacy version-1 manage-product-variation requests as version 2.' );
\t\t}
\t\t$action = (string) ( $request['action'] ?? '' );
\t\tif ( ! in_array( $action, [ 'create', 'read', 'update', 'delete' ], true ) || (int) ( $request['product_id'] ?? 0 ) < 1 ) {
\t\t\tthrow new RuntimeException( 'The variation request action or product ID is invalid.' );
\t\t}
\t\tif ( 'create' !== $action && (int) ( $request['variation_id'] ?? 0 ) < 1 ) {
\t\t\tthrow new RuntimeException( 'Reading, updating or deleting a variation requires an exact variation_id.' );
\t\t}
\t\tif ( in_array( $action, [ 'update', 'delete' ], true ) && ( ! is_string( $request['base_hash'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $request['base_hash'] ) ) ) {
\t\t\tthrow new RuntimeException( 'Updating or deleting a variation requires a valid base_hash from a version-2 read result.' );
\t\t}
\t\tif ( isset( $request['data'] ) && ( ! is_array( $request['data'] ) || ( [] !== $request['data'] && array_is_list( $request['data'] ) ) ) ) {
\t\t\tthrow new RuntimeException( 'The variation data must be an object.' );
\t\t}
\t\tif ( 'read' === $action && ( array_key_exists( 'data', $request ) || array_key_exists( 'confirm_destructive', $request ) ) ) {
\t\t\tthrow new RuntimeException( 'A variation read request cannot contain mutation fields.' );
\t\t}
\t}

'''.replace('\\t', '\t')
replace_between(path, '\tprivate function validate_request( array $request ): void {', '\tprivate function parent', variation_validate)

# ---------------------------------------------------------------------------
# AbilityBridge v2: GitHub ability route is discovery/read-only only.
# ---------------------------------------------------------------------------
path = 'includes/Content/AbilityBridge.php'
replace_once(path, "\tpublic const VERSION = 1;", "\tpublic const VERSION = 2;")
replace_once(
    path,
    "\t\tif ( ! $descriptor['executable'] ) {\n\t\t\tthrow new RuntimeException( 'This ability is catalogued for context but is not executable through the GitHub bridge.' );\n\t\t}\n\t\tif ( ! empty( $descriptor['annotations']['destructive'] ) && true !== ( $request['confirm_destructive'] ?? false ) ) {\n\t\t\tthrow new RuntimeException( 'This ability is marked destructive and requires confirm_destructive=true.' );\n\t\t}\n",
    "\t\tif ( ! $descriptor['executable'] ) {\n\t\t\tthrow new RuntimeException( 'Only abilities explicitly annotated readonly are executable through the GitHub bridge. Use guarded versioned CRUD requests for mutations.' );\n\t\t}\n",
)
replace_once(
    path,
    "\t\t\t'executable'    => ! str_starts_with( $name, 'core/' ) || true === ( $annotations['readonly'] ?? false ),",
    "\t\t\t'executable'    => true === ( $annotations['readonly'] ?? false ),",
)

# ---------------------------------------------------------------------------
# Repository dispatcher/manifest follows v2 read-handshake semantics.
# ---------------------------------------------------------------------------
path = 'includes/Sync/ContentRequests.php'
replace_once(path, "private const TERMINAL_STATUSES   = [ 'created', 'updated', 'deleted', 'executed', 'error' ];", "private const TERMINAL_STATUSES   = [ 'created', 'read', 'updated', 'deleted', 'executed', 'error' ];")
replace_once(path, "\t\t\t'version'               => 5,", "\t\t\t'version'               => 6,")
replace_once(
    path,
    "\t\t\t\t'Create, update or delete categories, tags and product categories through manage-term requests using exact term IDs for update/delete.',\n\t\t\t\t'Create, update or delete variable-product variations through manage-product-variation requests using exact product and variation IDs.',",
    "\t\t\t\t'Use version-2 read requests to obtain the current base_hash before updating/deleting products, taxonomy terms or product variations; stale hashes fail closed and the pre-state is snapshotted durably.',\n\t\t\t\t'Create, read, update or delete categories, tags and product categories through manage-term version 2 using exact term IDs outside create.',\n\t\t\t\t'Create, read, update or delete variable-product variations through manage-product-variation version 2 using exact product and variation IDs outside create.',",
)
replace_once(
    path,
    "\t\t\t\t'Only abilities listed in abilities.json can be executed through run-ability requests; supported namespaces are core/*, acf/*, yoast-seo/* and WooCommerce product abilities.',\n\t\t\t\t'Destructive term, product, variation or ability operations require confirm_destructive=true.',",
    "\t\t\t\t'Only live abilities explicitly annotated readonly can be executed through run-ability version 2. Mutable abilities remain catalogued for context but must use guarded versioned CRUD routes.',\n\t\t\t\t'Destructive term, product or variation operations require confirm_destructive=true.',",
)

# ---------------------------------------------------------------------------
# Runtime test helpers and v2 requests.
# ---------------------------------------------------------------------------
path = 'tests/runtime/content-operations.php'
replace_once(
    path,
    'use Webactueel\\ElementorJsonBridge\\Backup\\Snapshots;',
    'use Webactueel\\ElementorJsonBridge\\Backup\\OperationSnapshots;\nuse Webactueel\\ElementorJsonBridge\\Backup\\Snapshots;',
)
replace_once(
    path,
    "$abilities  = new AbilityBridge();\n\n$post_base_hash",
    "$abilities  = new AbilityBridge();\n$operation_snapshots = new OperationSnapshots();\n$read_sequence = 0;\n$term_base_hash = static function ( string $taxonomy, int $term_id ) use ( $terms, &$read_sequence ): string {\n\t++$read_sequence;\n\t$result = $terms->execute( [ 'format' => TaxonomyTerm::FORMAT, 'version' => TaxonomyTerm::VERSION, 'request_id' => 'runtime-term-read-' . $read_sequence, 'action' => 'read', 'taxonomy' => $taxonomy, 'term_id' => $term_id ] );\n\treturn (string) ( $result['base_hash'] ?? '' );\n};\n$product_base_hash = static function ( int $product_id ) use ( $products, &$read_sequence ): string {\n\t++$read_sequence;\n\t$result = $products->execute( [ 'format' => ProductRequest::FORMAT, 'version' => ProductRequest::VERSION, 'request_id' => 'runtime-product-read-' . $read_sequence, 'action' => 'read', 'product_id' => $product_id ] );\n\treturn (string) ( $result['base_hash'] ?? '' );\n};\n$variation_base_hash = static function ( int $product_id, int $variation_id ) use ( $variations, &$read_sequence ): string {\n\t++$read_sequence;\n\t$result = $variations->execute( [ 'format' => ProductVariation::FORMAT, 'version' => ProductVariation::VERSION, 'request_id' => 'runtime-variation-read-' . $read_sequence, 'action' => 'read', 'product_id' => $product_id, 'variation_id' => $variation_id ] );\n\treturn (string) ( $result['base_hash'] ?? '' );\n};\n$product_state = static function ( int $product_id ) use ( $content, $woo, $woo_extra ): array {\n\treturn [ 'content' => $content->payload( $product_id ), 'woocommerce' => array_merge( $woo->payload( $product_id ), $woo_extra->payload( $product_id ) ) ];\n};\n\n$post_base_hash",
)

# Term base hashes and stale conflict.
replace_once(path, "\t\t\t'term_id'    => $term_id,\n\t\t\t'data'       => [\n\t\t\t\t'name' => 'EJB Runtime Request Category Updated',", "\t\t\t'term_id'    => $term_id,\n\t\t\t'base_hash'  => $term_base_hash( 'category', $term_id ),\n\t\t\t'data'       => [\n\t\t\t\t'name' => 'EJB Runtime Request Category Updated',")
replace_once(path, "static function () use ( $terms, $term_id ): void {", "static function () use ( $terms, $term_id, $term_base_hash ): void {")
replace_once(path, "\t\t\t\t\t'term_id'    => $term_id,\n\t\t\t\t\t'data'       => [", "\t\t\t\t\t'term_id'    => $term_id,\n\t\t\t\t\t'base_hash'  => $term_base_hash( 'category', $term_id ),\n\t\t\t\t\t'data'       => [")

# Product v2: no password, base hashes.
replace_once(path, "static function () use ( $products, $product_id ): void {", "static function () use ( $products, $product_id, $product_base_hash ): void {")
replace_once(path, "\t\t\t\t\t'product_id' => $product_id,\n\t\t\t\t\t'post'       => [ 'title' => 'Must Not Persist' ],", "\t\t\t\t\t'product_id' => $product_id,\n\t\t\t\t\t'base_hash'  => $product_base_hash( $product_id ),\n\t\t\t\t\t'post'       => [ 'title' => 'Must Not Persist' ],")
replace_once(path, "\t$products->execute(\n\t\t[\n\t\t\t'format'     => ProductRequest::FORMAT,\n\t\t\t'version'    => ProductRequest::VERSION,\n\t\t\t'request_id' => 'runtime-product-update',", "\t$product_before_update = $product_state( $product_id );\n\t$product_update_result = $products->execute(\n\t\t[\n\t\t\t'format'     => ProductRequest::FORMAT,\n\t\t\t'version'    => ProductRequest::VERSION,\n\t\t\t'request_id' => 'runtime-product-update',")
replace_once(path, "\t\t\t'action'     => 'update',\n\t\t\t'product_id' => $product_id,\n\t\t\t'post'       => [ 'title' => 'EJB Runtime Product Updated', 'content' => '<p>Product after</p>', 'password' => 'product-pass' ],", "\t\t\t'action'     => 'update',\n\t\t\t'product_id' => $product_id,\n\t\t\t'base_hash'  => CanonicalJson::hash( $product_before_update ),\n\t\t\t'post'       => [ 'title' => 'EJB Runtime Product Updated', 'content' => '<p>Product after</p>' ],")
replace_once(path, "\tif ( ! $product instanceof WC_Product || 'EJB Runtime Product Updated' !== $product->get_name() || '24.50' !== $product->get_regular_price() || 5.0 !== (float) $product->get_stock_quantity() || 1 !== (int) $product->get_low_stock_amount() || 'product-pass' !== get_post_field( 'post_password', $product_id, 'raw' ) ) {", "\tif ( ! $product instanceof WC_Product || 'EJB Runtime Product Updated' !== $product->get_name() || '24.50' !== $product->get_regular_price() || 5.0 !== (float) $product->get_stock_quantity() || 1 !== (int) $product->get_low_stock_amount() ) {")
replace_once(path, "\tif ( ! has_term( $product_cat_id, 'product_cat', $product_id ) ) {", "\t$product_snapshot_id = (int) ( $product_update_result['snapshot_id'] ?? 0 );\n\tif ( $product_snapshot_id < 1 || ! hash_equals( CanonicalJson::hash( $product_before_update ), CanonicalJson::hash( $operation_snapshots->payload( $product_snapshot_id, 'woocommerce_product', 'product:' . $product_id ) ) ) ) {\n\t\tthrow new RuntimeException( 'ProductRequest did not preserve a valid durable pre-update snapshot.' );\n\t}\n\tif ( ! has_term( $product_cat_id, 'product_cat', $product_id ) ) {")
replace_once(path, "static function () use ( $products, $product_id ): void {\n\t\t\t$products->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => ProductRequest::FORMAT,\n\t\t\t\t\t'version'    => ProductRequest::VERSION,\n\t\t\t\t\t'request_id' => 'runtime-product-delete-no-confirm',", "static function () use ( $products, $product_id, $product_base_hash ): void {\n\t\t\t$products->execute(\n\t\t\t\t[\n\t\t\t\t\t'format'     => ProductRequest::FORMAT,\n\t\t\t\t\t'version'    => ProductRequest::VERSION,\n\t\t\t\t\t'request_id' => 'runtime-product-delete-no-confirm',")
replace_once(path, "\t\t\t\t\t'action'     => 'delete',\n\t\t\t\t\t'product_id' => $product_id,", "\t\t\t\t\t'action'     => 'delete',\n\t\t\t\t\t'product_id' => $product_id,\n\t\t\t\t\t'base_hash'  => $product_base_hash( $product_id ),")
replace_once(path, "\t\t\t'action'              => 'delete',\n\t\t\t'product_id'          => $product_id,\n\t\t\t'confirm_destructive' => true,", "\t\t\t'action'              => 'delete',\n\t\t\t'product_id'          => $product_id,\n\t\t\t'base_hash'           => $product_base_hash( $product_id ),\n\t\t\t'confirm_destructive' => true,")
replace_once(path, "\t\t\t'action'              => 'delete',\n\t\t\t'product_id'          => $force_product_id,\n\t\t\t'confirm_destructive' => true,", "\t\t\t'action'              => 'delete',\n\t\t\t'product_id'          => $force_product_id,\n\t\t\t'base_hash'           => $product_base_hash( $force_product_id ),\n\t\t\t'confirm_destructive' => true,")

# Variation v2 base hashes.
replace_once(path, "\t\t\t'action'       => 'update',\n\t\t\t'product_id'   => $variable_id,\n\t\t\t'variation_id' => $variation_id,", "\t\t\t'action'       => 'update',\n\t\t\t'product_id'   => $variable_id,\n\t\t\t'variation_id' => $variation_id,\n\t\t\t'base_hash'    => $variation_base_hash( $variable_id, $variation_id ),")
replace_once(path, "static function () use ( $variations, $variable_id, $variation_id ): void {", "static function () use ( $variations, $variable_id, $variation_id, $variation_base_hash ): void {")
replace_once(path, "\t\t\t\t\t'action'       => 'update',\n\t\t\t\t\t'product_id'   => $variable_id,\n\t\t\t\t\t'variation_id' => $variation_id,", "\t\t\t\t\t'action'       => 'update',\n\t\t\t\t\t'product_id'   => $variable_id,\n\t\t\t\t\t'variation_id' => $variation_id,\n\t\t\t\t\t'base_hash'    => $variation_base_hash( $variable_id, $variation_id ),")
replace_once(path, "\t\t\t'action'              => 'delete',\n\t\t\t'product_id'          => $variable_id,\n\t\t\t'variation_id'        => $variation_id,", "\t\t\t'action'              => 'delete',\n\t\t\t'product_id'          => $variable_id,\n\t\t\t'variation_id'        => $variation_id,\n\t\t\t'base_hash'           => $variation_base_hash( $variable_id, $variation_id ),")

# Ability discovery follows live registration; mutation execution is forbidden.
old_abilities = """\t$catalog = $abilities->catalog();
\t$available = is_array( $catalog['abilities'] ?? null ) ? $catalog['abilities'] : [];
\tforeach ( [ 'core/get-site-info', 'acf/field-groups', 'acf/register-field-group', 'yoast-seo/get-seo-scores', 'woocommerce/product-create', 'woocommerce/product-update', 'woocommerce/product-delete', 'woocommerce/products-query' ] as $ability_name ) {
\t\tif ( ! isset( $available[ $ability_name ] ) ) {
\t\t\tthrow new RuntimeException( 'Expected runtime ability is missing: ' . $ability_name );
\t\t}
\t}
"""
new_abilities = """\t$catalog = $abilities->catalog();
\t$available = is_array( $catalog['abilities'] ?? null ) ? $catalog['abilities'] : [];
\tforeach ( [ 'core/get-site-info', 'acf/field-groups' ] as $ability_name ) {
\t\tif ( ! isset( $available[ $ability_name ] ) || empty( $available[ $ability_name ]['executable'] ) ) {
\t\t\tthrow new RuntimeException( 'Expected read-only runtime ability is missing or not executable: ' . $ability_name );
\t\t}
\t}
\tforeach ( [ 'acf/register-field-group', 'woocommerce/product-create', 'woocommerce/product-update', 'woocommerce/product-delete' ] as $ability_name ) {
\t\tif ( isset( $available[ $ability_name ] ) && ! empty( $available[ $ability_name ]['executable'] ) ) {
\t\t\tthrow new RuntimeException( 'A mutable runtime ability was incorrectly exposed for GitHub execution: ' . $ability_name );
\t\t}
\t}
"""
replace_once(path, old_abilities, new_abilities)

ability_delete_start = "\t$ability_product = new WC_Product_Simple();"
ability_delete_end = "\n\techo wp_json_encode("
ability_delete_replacement = r'''\t$ability_product = new WC_Product_Simple();
\t$ability_product->set_name( 'EJB Ability Protected Product' );
\t$ability_product->set_status( 'draft' );
\t$ability_product_id = (int) $ability_product->save();
\t$cleanup_posts[] = $ability_product_id;
\t$expect_runtime_exception(
\t\tstatic function () use ( $abilities, $ability_product_id ): void {
\t\t\t$abilities->execute(
\t\t\t\t[
\t\t\t\t\t'format'              => AbilityBridge::FORMAT,
\t\t\t\t\t'version'             => AbilityBridge::VERSION,
\t\t\t\t\t'request_id'          => 'runtime-ability-mutation-rejected',
\t\t\t\t\t'ability'             => 'woocommerce/product-delete',
\t\t\t\t\t'input'               => [ 'id' => $ability_product_id, 'force' => false ],
\t\t\t\t\t'confirm_destructive' => true,
\t\t\t\t]
\t\t\t);
\t\t},
\t\t'AbilityBridge executed a mutable WooCommerce ability through the read-only GitHub route.'
\t);
\tif ( ! get_post( $ability_product_id ) ) {
\t\tthrow new RuntimeException( 'Rejected mutable ability unexpectedly removed the product.' );
\t}
'''.replace('\\t', '\t')
replace_between(path, ability_delete_start, ability_delete_end, ability_delete_replacement)

# ---------------------------------------------------------------------------
# Static regression evidence.
# ---------------------------------------------------------------------------
path = 'tests/content-request-safety.php'
replace_once(
    path,
    "$assert(str_contains($products, 'align_product_brand_taxonomy(') && str_contains($products, \"\\$content['taxonomies']['product_brand'] = \\$slugs\"), 'WooCommerce brand_ids are not aligned with the canonical product_brand taxonomy envelope.');",
    "$assert(str_contains($products, 'align_product_brand_taxonomy(') && str_contains($products, \"\\$content['taxonomies']['product_brand'] = \\$slugs\"), 'WooCommerce brand_ids are not aligned with the canonical product_brand taxonomy envelope.');\n$assert(str_contains($products, 'public const VERSION = 2') && str_contains($products, \"'base_hash'\") && str_contains($products, \"'action'     => 'read'\") && str_contains($products, 'OperationSnapshots'), 'Product request v2 conflict/snapshot handshake is incomplete.');\n$assert(str_contains($terms, 'public const VERSION = 2') && str_contains($terms, \"'base_hash'\") && str_contains($terms, 'OperationSnapshots'), 'Taxonomy request v2 conflict/snapshot handshake is incomplete.');\n$assert(str_contains($variations, 'public const VERSION = 2') && str_contains($variations, \"'base_hash'\") && str_contains($variations, 'OperationSnapshots'), 'Variation request v2 conflict/snapshot handshake is incomplete.');\n$assert(str_contains($abilities, 'public const VERSION = 2') && str_contains($abilities, \"true === ( $annotations['readonly'] ?? false )\"), 'Ability bridge is not fail-closed to explicitly read-only abilities.');",
)

# wordpress-content-sync loads AbilityBridge source under $abilityBridge.
path = 'tests/wordpress-content-sync.php'
replace_once(
    path,
    "$assert(str_contains($products, \"'elementor-json-bridge/manage-product'\") && str_contains($products, 'publish_products'), 'WooCommerce product CRUD or publish capability checks are missing.');",
    "$assert(str_contains($products, \"'elementor-json-bridge/manage-product'\") && str_contains($products, 'publish_products'), 'WooCommerce product CRUD or publish capability checks are missing.');\n$assert(str_contains($products, 'public const VERSION = 2') && str_contains($terms, 'public const VERSION = 2') && str_contains($variations, 'public const VERSION = 2'), 'Mutating product/term/variation requests are not on the v2 conflict/snapshot contract.');",
)

# ---------------------------------------------------------------------------
# Documentation.
# ---------------------------------------------------------------------------
for path in ('README.md', 'readme.txt'):
    text = Path(path).read_text(encoding='utf-8')
    text = text.replace('`elementor-json-bridge/manage-product`, version `1`', '`elementor-json-bridge/manage-product`, version `2`')
    text = text.replace('`elementor-json-bridge/manage-product-variation`, version `1`', '`elementor-json-bridge/manage-product-variation`, version `2`')
    text = text.replace('`elementor-json-bridge/manage-term`, version `1`', '`elementor-json-bridge/manage-term`, version `2`')
    text = text.replace('`elementor-json-bridge/run-ability`, version `1`', '`elementor-json-bridge/run-ability`, version `2`')
    text = text.replace('`elementor-json-bridge/manage-product` supports WooCommerce product create, update and delete.', '`elementor-json-bridge/manage-product` version 2 supports WooCommerce product create, read, update and delete. Read returns the current `base_hash`; update/delete require that exact hash and create a durable typed snapshot before validation. Pending version-1 requests must be regenerated.')
    text = text.replace('`elementor-json-bridge/manage-product-variation` supports variable-product variation create, update and confirmed permanent delete.', '`elementor-json-bridge/manage-product-variation` version 2 supports variable-product variation create, read, update and confirmed permanent delete. Update/delete require the `base_hash` from a fresh read result and create a durable typed snapshot before validation. Pending version-1 requests must be regenerated.')
    text = text.replace('`elementor-json-bridge/manage-term` supports create/update/delete for WordPress/WooCommerce taxonomies with exact term IDs for update/delete.', '`elementor-json-bridge/manage-term` version 2 supports create/read/update/delete for WordPress/WooCommerce taxonomies with exact term IDs outside create. Update/delete require the `base_hash` from a fresh read result and create a durable typed snapshot before validation. Pending version-1 requests must be regenerated.')
    text = text.replace('`elementor-json-bridge/run-ability` executes only a constrained live-catalog ability.', '`elementor-json-bridge/run-ability` version 2 executes only a constrained live-catalog ability explicitly annotated read-only. Mutable abilities remain discoverable context but cannot execute through this generic GitHub route; mutations use guarded versioned CRUD requests instead. Pending version-1 ability requests must be regenerated.')
    Path(path).write_text(text, encoding='utf-8')

# README markdown uses slightly different section prose.
replace_once(
    'README.md',
    'Actions: `create`, `update`, `delete`.\n\nProduct updates use `WC_Product` setters and `save()`.',
    'Actions: `create`, `read`, `update`, `delete`. Version 2 is a safety migration; pending version-1 product requests must be regenerated. `read` returns the current `base_hash`; update/delete require that hash and persist a durable typed pre-state snapshot before validation.\n\nProduct updates use `WC_Product` setters and `save()`.',
)
replace_once(
    'README.md',
    'Create, update and permanent delete are supported against an exact variable-product parent.',
    'Create, read, update and permanent delete are supported against an exact variable-product parent. Version 2 requires a fresh read-derived `base_hash` and durable pre-state snapshot for update/delete.',
)
replace_once(
    'README.md',
    'Create, update and delete terms using WordPress taxonomy APIs.',
    'Create, read, update and delete terms using WordPress taxonomy APIs. Version 2 requires a fresh read-derived `base_hash` and durable pre-state snapshot for update/delete.',
)
replace_once(
    'README.md',
    'Each ability remains subject to its own WordPress permission callback and input/output schema. Abilities marked destructive require `confirm_destructive=true`.',
    'Each ability remains subject to its own WordPress permission callback and input/output schema. The generic GitHub ability route executes only abilities explicitly annotated read-only; mutable abilities must use guarded versioned CRUD adapters that can provide conflict checks, snapshots, readback and rollback.',
)

# readme.txt changelog additions.
replace_once(
    'readme.txt',
    '* Version `manage-post` as contract version 2 for mandatory stale-request protection; version-1 pending request files must be regenerated.',
    '* Version `manage-post` as contract version 2 for mandatory stale-request protection; version-1 pending request files must be regenerated.\n* Version product, taxonomy-term and variation mutation contracts to version 2 with read-derived base hashes and durable typed snapshots before validation.\n* Version the generic ability route to version 2 and execute only abilities explicitly annotated read-only; mutable abilities use guarded CRUD adapters.',
)

print('all remote mutation safety contracts upgraded to v2')
