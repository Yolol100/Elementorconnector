<?php

use Webactueel\ElementorJsonBridge\Content\AbilityBridge;
use Webactueel\ElementorJsonBridge\Content\PostRequest;
use Webactueel\ElementorJsonBridge\Content\ProductRequest;
use Webactueel\ElementorJsonBridge\Content\ProductVariation;
use Webactueel\ElementorJsonBridge\Content\TaxonomyTerm;
use Webactueel\ElementorJsonBridge\Content\WooCommerceProduct;
use Webactueel\ElementorJsonBridge\Content\WooCommerceProductExtras;
use Webactueel\ElementorJsonBridge\Content\WordPressDocument;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress was not bootstrapped.' );
}
if ( ! defined( 'EJB_VERSION' ) ) {
	throw new RuntimeException( 'Elementor JSON Bridge is not active.' );
}
if ( ! defined( 'WC_VERSION' ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'WooCommerce is not active.' );
}
if ( ! defined( 'ACF_VERSION' ) || ! function_exists( 'acf_get_setting' ) ) {
	throw new RuntimeException( 'ACF is not active.' );
}
if ( ! defined( 'WPSEO_VERSION' ) || ! class_exists( 'WPSEO_Meta' ) ) {
	throw new RuntimeException( 'Yoast SEO is not active.' );
}
if ( ! acf_get_setting( 'enable_acf_ai' ) ) {
	throw new RuntimeException( 'The runtime must enable ACF AI abilities before plugin initialization.' );
}

$admin = get_user_by( 'login', 'admin' );
if ( ! $admin instanceof WP_User ) {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
	$admin  = $admins[0] ?? null;
}
if ( ! $admin instanceof WP_User ) {
	throw new RuntimeException( 'No administrator user is available.' );
}

$previous_user = get_current_user_id();
wp_set_current_user( (int) $admin->ID );

$documents  = new Documents();
$validator  = new PayloadValidator();
$content    = new WordPressDocument( $documents, $validator );
$posts      = new PostRequest( $content, $documents, $validator );
$woo        = new WooCommerceProduct();
$woo_extra  = new WooCommerceProductExtras();
$products   = new ProductRequest( $woo, $woo_extra, $content );
$variations = new ProductVariation();
$terms      = new TaxonomyTerm();
$abilities  = new AbilityBridge();

$cleanup_posts = [];
$cleanup_terms = [];

$expect_runtime_exception = static function ( callable $callback, string $message ): void {
	$failed = false;
	try {
		$callback();
	} catch ( RuntimeException ) {
		$failed = true;
	}
	if ( ! $failed ) {
		throw new RuntimeException( $message );
	}
};

acf_add_local_field_group(
	[
		'key'   => 'group_ejb_runtime_term',
		'title' => 'EJB Runtime Term',
		'fields' => [
			[
				'key'   => 'field_ejb_runtime_term_text',
				'label' => 'Runtime term text',
				'name'  => 'ejb_runtime_term_text',
				'type'  => 'text',
			],
		],
		'location' => [
			[
				[
					'param'    => 'taxonomy',
					'operator' => '==',
					'value'    => 'category',
				],
			],
		],
	]
);

try {
	$page_result = $posts->execute(
		[
			'format'     => PostRequest::FORMAT,
			'version'    => PostRequest::VERSION,
			'request_id' => 'runtime-post-create',
			'action'     => 'create',
			'post_type'  => 'page',
			'post'       => [
				'title'   => 'EJB Request Page',
				'slug'    => 'ejb-request-page',
				'content' => '<p>Request page before.</p>',
				'excerpt' => 'Before',
			],
		]
	);
	$page_id = (int) ( $page_result['post_id'] ?? 0 );
	$cleanup_posts[] = $page_id;
	if ( $page_id < 1 || 'draft' !== get_post_status( $page_id ) ) {
		throw new RuntimeException( 'PostRequest did not create a draft page.' );
	}

	$before_title = get_the_title( $page_id );
	$expect_runtime_exception(
		static function () use ( $posts, $page_id ): void {
			$posts->execute(
				[
					'format'     => PostRequest::FORMAT,
					'version'    => PostRequest::VERSION,
					'request_id' => 'runtime-post-invalid-author',
					'action'     => 'update',
					'post_id'    => $page_id,
					'post'       => [ 'title' => 'Must Not Persist', 'author' => 999999999 ],
				]
			);
		},
		'PostRequest accepted an invalid author.'
	);
	if ( get_the_title( $page_id ) !== $before_title ) {
		throw new RuntimeException( 'PostRequest changed content before rejecting invalid extended data.' );
	}

	$posts->execute(
		[
			'format'     => PostRequest::FORMAT,
			'version'    => PostRequest::VERSION,
			'request_id' => 'runtime-post-update',
			'action'     => 'update',
			'post_id'    => $page_id,
			'post'       => [
				'title'    => 'EJB Request Page Updated',
				'content'  => '<p>Request page after.</p>',
				'password' => 'runtime-pass',
			],
		]
	);
	$page = get_post( $page_id );
	if ( ! $page instanceof WP_Post || 'EJB Request Page Updated' !== $page->post_title || 'runtime-pass' !== $page->post_password ) {
		throw new RuntimeException( 'PostRequest update failed readback.' );
	}

	$elementor_result = $posts->execute(
		[
			'format'     => PostRequest::FORMAT,
			'version'    => PostRequest::VERSION,
			'request_id' => 'runtime-elementor-create',
			'action'     => 'create',
			'post_type'  => 'page',
			'post'       => [ 'title' => 'EJB Elementor Created' ],
			'elementor'  => [
				'title'         => 'EJB Elementor Created',
				'type'          => 'wp-page',
				'version'       => PayloadValidator::FORMAT_VERSION,
				'page_settings' => [],
				'content'       => [],
			],
		]
	);
	$elementor_id = (int) ( $elementor_result['post_id'] ?? 0 );
	$cleanup_posts[] = $elementor_id;
	if ( $elementor_id < 1 || ! $documents->is_elementor_document( $elementor_id ) || 'draft' !== get_post_status( $elementor_id ) ) {
		throw new RuntimeException( 'Elementor document creation did not use a real draft Elementor document.' );
	}

	$expect_runtime_exception(
		static function () use ( $posts, $page_id ): void {
			$posts->execute(
				[
					'format'     => PostRequest::FORMAT,
					'version'    => PostRequest::VERSION,
					'request_id' => 'runtime-post-delete-no-confirm',
					'action'     => 'delete',
					'post_id'    => $page_id,
				]
			);
		},
		'PostRequest allowed delete without confirmation.'
	);
	if ( 'draft' !== get_post_status( $page_id ) ) {
		throw new RuntimeException( 'PostRequest changed the page after rejected delete.' );
	}
	$posts->execute(
		[
			'format'              => PostRequest::FORMAT,
			'version'             => PostRequest::VERSION,
			'request_id'          => 'runtime-post-delete',
			'action'              => 'delete',
			'post_id'             => $page_id,
			'confirm_destructive' => true,
		]
	);
	if ( 'trash' !== get_post_status( $page_id ) ) {
		throw new RuntimeException( 'PostRequest did not move content to trash.' );
	}

	$term_result = $terms->execute(
		[
			'format'     => TaxonomyTerm::FORMAT,
			'version'    => TaxonomyTerm::VERSION,
			'request_id' => 'runtime-term-create',
			'action'     => 'create',
			'taxonomy'   => 'category',
			'data'       => [ 'name' => 'EJB Runtime Request Category', 'slug' => 'ejb-runtime-request-category' ],
		]
	);
	$term_id = (int) ( $term_result['term_id'] ?? 0 );
	$cleanup_terms[] = [ $term_id, 'category' ];
	if ( $term_id < 1 ) {
		throw new RuntimeException( 'TaxonomyTerm did not create a category.' );
	}

	$terms->execute(
		[
			'format'     => TaxonomyTerm::FORMAT,
			'version'    => TaxonomyTerm::VERSION,
			'request_id' => 'runtime-term-update-acf',
			'action'     => 'update',
			'taxonomy'   => 'category',
			'term_id'    => $term_id,
			'data'       => [
				'name' => 'EJB Runtime Request Category Updated',
				'acf'  => [
					'ejb_runtime_term_text' => [
						'key'   => 'field_ejb_runtime_term_text',
						'type'  => 'text',
						'value' => 'term-value-after',
					],
				],
			],
		]
	);
	if ( 'EJB Runtime Request Category Updated' !== get_term( $term_id, 'category' )->name || 'term-value-after' !== get_field( 'field_ejb_runtime_term_text', 'term_' . $term_id ) ) {
		throw new RuntimeException( 'TaxonomyTerm update failed readback.' );
	}
	$term_name_before_invalid = get_term( $term_id, 'category' )->name;
	$expect_runtime_exception(
		static function () use ( $terms, $term_id ): void {
			$terms->execute(
				[
					'format'     => TaxonomyTerm::FORMAT,
					'version'    => TaxonomyTerm::VERSION,
					'request_id' => 'runtime-term-invalid-acf',
					'action'     => 'update',
					'taxonomy'   => 'category',
					'term_id'    => $term_id,
					'data'       => [
						'name' => 'Must Not Persist',
						'acf'  => [
							'ejb_runtime_term_text' => [ 'key' => 'wrong-key', 'type' => 'text', 'value' => 'bad' ],
						],
					],
				]
			);
		},
		'TaxonomyTerm accepted an invalid ACF identity.'
	);
	if ( get_term( $term_id, 'category' )->name !== $term_name_before_invalid ) {
		throw new RuntimeException( 'TaxonomyTerm changed core data before extension validation.' );
	}

	$product_result = $products->execute(
		[
			'format'     => ProductRequest::FORMAT,
			'version'    => ProductRequest::VERSION,
			'request_id' => 'runtime-product-create',
			'action'     => 'create',
			'post'       => [ 'title' => 'EJB Runtime Product', 'slug' => 'ejb-runtime-product', 'content' => '<p>Product before</p>' ],
			'woocommerce' => [ 'type' => 'simple', 'sku' => 'EJB-RUNTIME-001', 'global_unique_id' => '9780306406157', 'regular_price' => '19.90', 'manage_stock' => true, 'stock_quantity' => 8, 'low_stock_amount' => 2 ],
		]
	);
	$product_id = (int) ( $product_result['product_id'] ?? 0 );
	$cleanup_posts[] = $product_id;
	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product || 'draft' !== $product->get_status() || '19.90' !== $product->get_regular_price() || '9780306406157' !== $product->get_global_unique_id() || 2 !== (int) $product->get_low_stock_amount() ) {
		throw new RuntimeException( 'ProductRequest create failed readback.' );
	}

	$product_title_before_invalid = $product->get_name();
	$expect_runtime_exception(
		static function () use ( $products, $product_id ): void {
			$products->execute(
				[
					'format'     => ProductRequest::FORMAT,
					'version'    => ProductRequest::VERSION,
					'request_id' => 'runtime-product-invalid-woo',
					'action'     => 'update',
					'product_id' => $product_id,
					'post'       => [ 'title' => 'Must Not Persist' ],
					'woocommerce' => [ 'type' => 'simple', 'regular_price' => [ 'invalid' ] ],
				]
			);
		},
		'ProductRequest accepted invalid WooCommerce data.'
	);
	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product || $product->get_name() !== $product_title_before_invalid ) {
		throw new RuntimeException( 'ProductRequest changed core data before WooCommerce validation.' );
	}

	$product_cat = wp_insert_term( 'EJB Runtime Product Category', 'product_cat', [ 'slug' => 'ejb-runtime-product-category' ] );
	if ( is_wp_error( $product_cat ) ) {
		throw new RuntimeException( 'Could not create runtime product category.' );
	}
	$product_cat_id = (int) $product_cat['term_id'];
	$cleanup_terms[] = [ $product_cat_id, 'product_cat' ];

	$product_brand_id = 0;
	if ( taxonomy_exists( 'product_brand' ) ) {
		$product_brand = wp_insert_term( 'EJB Runtime Brand', 'product_brand', [ 'slug' => 'ejb-runtime-brand' ] );
		if ( is_wp_error( $product_brand ) ) {
			throw new RuntimeException( 'Could not create runtime product brand.' );
		}
		$product_brand_id = (int) $product_brand['term_id'];
		$cleanup_terms[] = [ $product_brand_id, 'product_brand' ];
	}

	$product_update_woo = [ 'type' => 'simple', 'regular_price' => '24.50', 'manage_stock' => true, 'stock_quantity' => 5, 'low_stock_amount' => 1 ];
	if ( $product_brand_id > 0 ) {
		$product_update_woo['brand_ids'] = [ $product_brand_id ];
	}
	$products->execute(
		[
			'format'     => ProductRequest::FORMAT,
			'version'    => ProductRequest::VERSION,
			'request_id' => 'runtime-product-update',
			'action'     => 'update',
			'product_id' => $product_id,
			'post'       => [ 'title' => 'EJB Runtime Product Updated', 'content' => '<p>Product after</p>', 'password' => 'product-pass' ],
			'woocommerce' => $product_update_woo,
			'taxonomies' => [ 'product_cat' => [ 'ejb-runtime-product-category' ] ],
		]
	);
	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product || 'EJB Runtime Product Updated' !== $product->get_name() || '24.50' !== $product->get_regular_price() || 5.0 !== (float) $product->get_stock_quantity() || 1 !== (int) $product->get_low_stock_amount() || 'product-pass' !== get_post_field( 'post_password', $product_id, 'raw' ) ) {
		throw new RuntimeException( 'ProductRequest update failed readback.' );
	}
	if ( ! has_term( $product_cat_id, 'product_cat', $product_id ) ) {
		throw new RuntimeException( 'ProductRequest taxonomy assignment failed readback.' );
	}
	if ( $product_brand_id > 0 && ! in_array( $product_brand_id, array_map( 'intval', $product->get_brand_ids() ), true ) ) {
		throw new RuntimeException( 'ProductRequest brand assignment failed readback.' );
	}

	$expect_runtime_exception(
		static function () use ( $products, $product_id ): void {
			$products->execute(
				[
					'format'     => ProductRequest::FORMAT,
					'version'    => ProductRequest::VERSION,
					'request_id' => 'runtime-product-delete-no-confirm',
					'action'     => 'delete',
					'product_id' => $product_id,
				]
			);
		},
		'ProductRequest allowed delete without confirmation.'
	);
	$products->execute(
		[
			'format'              => ProductRequest::FORMAT,
			'version'             => ProductRequest::VERSION,
			'request_id'          => 'runtime-product-trash',
			'action'              => 'delete',
			'product_id'          => $product_id,
			'confirm_destructive' => true,
		]
	);
	if ( 'trash' !== get_post_status( $product_id ) ) {
		throw new RuntimeException( 'ProductRequest did not use WooCommerce soft delete by default.' );
	}

	$force_product = new WC_Product_Simple();
	$force_product->set_name( 'EJB Runtime Force Delete' );
	$force_product->set_status( 'draft' );
	$force_product_id = (int) $force_product->save();
	$cleanup_posts[] = $force_product_id;
	$products->execute(
		[
			'format'              => ProductRequest::FORMAT,
			'version'             => ProductRequest::VERSION,
			'request_id'          => 'runtime-product-force-delete',
			'action'              => 'delete',
			'product_id'          => $force_product_id,
			'confirm_destructive' => true,
			'force'               => true,
		]
	);
	if ( null !== get_post( $force_product_id ) ) {
		throw new RuntimeException( 'ProductRequest force delete did not permanently remove the product.' );
	}

	$variable_result = $products->execute(
		[
			'format'     => ProductRequest::FORMAT,
			'version'    => ProductRequest::VERSION,
			'request_id' => 'runtime-variable-create',
			'action'     => 'create',
			'post'       => [ 'title' => 'EJB Runtime Variable' ],
			'woocommerce' => [ 'type' => 'variable' ],
		]
	);
	$variable_id = (int) ( $variable_result['product_id'] ?? 0 );
	$cleanup_posts[] = $variable_id;

	$variation_result = $variations->execute(
		[
			'format'     => ProductVariation::FORMAT,
			'version'    => ProductVariation::VERSION,
			'request_id' => 'runtime-variation-create',
			'action'     => 'create',
			'product_id' => $variable_id,
			'data'       => [ 'regular_price' => '10.00', 'manage_stock' => true, 'stock_quantity' => 3, 'global_unique_id' => '4006381333931', 'low_stock_amount' => 1 ],
		]
	);
	$variation_id = (int) ( $variation_result['variation_id'] ?? 0 );
	$cleanup_posts[] = $variation_id;
	$variations->execute(
		[
			'format'       => ProductVariation::FORMAT,
			'version'      => ProductVariation::VERSION,
			'request_id'   => 'runtime-variation-update',
			'action'       => 'update',
			'product_id'   => $variable_id,
			'variation_id' => $variation_id,
			'data'         => [ 'regular_price' => '12.00', 'stock_quantity' => 2 ],
		]
	);
	$variation = wc_get_product( $variation_id );
	if ( ! $variation instanceof WC_Product_Variation || '12.00' !== $variation->get_regular_price() || 2.0 !== (float) $variation->get_stock_quantity() || '4006381333931' !== $variation->get_global_unique_id() || 1 !== (int) $variation->get_low_stock_amount() ) {
		throw new RuntimeException( 'ProductVariation update failed readback.' );
	}
	$variation_price_before_invalid = $variation->get_regular_price();
	$expect_runtime_exception(
		static function () use ( $variations, $variable_id, $variation_id ): void {
			$variations->execute(
				[
					'format'       => ProductVariation::FORMAT,
					'version'      => ProductVariation::VERSION,
					'request_id'   => 'runtime-variation-invalid',
					'action'       => 'update',
					'product_id'   => $variable_id,
					'variation_id' => $variation_id,
					'data'         => [ 'regular_price' => '99.00', 'download_limit' => -1 ],
				]
			);
		},
		'ProductVariation accepted invalid data.'
	);
	$variation = wc_get_product( $variation_id );
	if ( ! $variation instanceof WC_Product_Variation || $variation->get_regular_price() !== $variation_price_before_invalid ) {
		throw new RuntimeException( 'ProductVariation persisted data before full validation.' );
	}
	$variations->execute(
		[
			'format'              => ProductVariation::FORMAT,
			'version'             => ProductVariation::VERSION,
			'request_id'          => 'runtime-variation-delete',
			'action'              => 'delete',
			'product_id'          => $variable_id,
			'variation_id'        => $variation_id,
			'confirm_destructive' => true,
		]
	);
	if ( null !== get_post( $variation_id ) ) {
		throw new RuntimeException( 'ProductVariation delete failed readback.' );
	}

	$catalog = $abilities->catalog();
	$available = is_array( $catalog['abilities'] ?? null ) ? $catalog['abilities'] : [];
	foreach ( [ 'core/get-site-info', 'acf/field-groups', 'acf/register-field-group', 'yoast-seo/get-seo-scores', 'woocommerce/product-create', 'woocommerce/product-update', 'woocommerce/product-delete', 'woocommerce/products-query' ] as $ability_name ) {
		if ( ! isset( $available[ $ability_name ] ) ) {
			throw new RuntimeException( 'Expected runtime ability is missing: ' . $ability_name );
		}
	}
	$core_result = $abilities->execute(
		[
			'format'     => AbilityBridge::FORMAT,
			'version'    => AbilityBridge::VERSION,
			'request_id' => 'runtime-core-ability',
			'ability'    => 'core/get-site-info',
			'input'      => [ 'fields' => [ 'name', 'version' ] ],
		]
	);
	if ( 'executed' !== ( $core_result['status'] ?? '' ) || empty( $core_result['output']['version'] ) ) {
		throw new RuntimeException( 'Read-only Core ability execution failed.' );
	}
	$acf_result = $abilities->execute(
		[
			'format'     => AbilityBridge::FORMAT,
			'version'    => AbilityBridge::VERSION,
			'request_id' => 'runtime-acf-ability',
			'ability'    => 'acf/field-groups',
			'input'      => [],
		]
	);
	if ( 'executed' !== ( $acf_result['status'] ?? '' ) ) {
		throw new RuntimeException( 'ACF read ability execution failed.' );
	}

	$ability_product = new WC_Product_Simple();
	$ability_product->set_name( 'EJB Ability Delete Product' );
	$ability_product->set_status( 'draft' );
	$ability_product_id = (int) $ability_product->save();
	$cleanup_posts[] = $ability_product_id;
	$expect_runtime_exception(
		static function () use ( $abilities, $ability_product_id ): void {
			$abilities->execute(
				[
					'format'     => AbilityBridge::FORMAT,
					'version'    => AbilityBridge::VERSION,
					'request_id' => 'runtime-ability-delete-no-confirm',
					'ability'    => 'woocommerce/product-delete',
					'input'      => [ 'id' => $ability_product_id, 'force' => false ],
				]
			);
		},
		'AbilityBridge allowed a destructive WooCommerce ability without confirmation.'
	);
	if ( ! get_post( $ability_product_id ) ) {
		throw new RuntimeException( 'Rejected destructive ability unexpectedly removed the product.' );
	}
	$abilities->execute(
		[
			'format'              => AbilityBridge::FORMAT,
			'version'             => AbilityBridge::VERSION,
			'request_id'          => 'runtime-ability-delete-confirmed',
			'ability'             => 'woocommerce/product-delete',
			'input'               => [ 'id' => $ability_product_id, 'force' => false ],
			'confirm_destructive' => true,
		]
	);
	if ( 'trash' !== get_post_status( $ability_product_id ) ) {
		throw new RuntimeException( 'Confirmed WooCommerce delete ability did not move the product to trash.' );
	}

	echo wp_json_encode(
		[
			'status'                    => 'PASS',
			'wordpress'                 => get_bloginfo( 'version' ),
			'php'                       => PHP_VERSION,
			'bridge'                    => EJB_VERSION,
			'elementor'                 => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'active',
			'acf'                       => ACF_VERSION,
			'yoast'                     => WPSEO_VERSION,
			'woocommerce'               => WC_VERSION,
			'post_request_crud'         => true,
			'elementor_create'          => true,
			'term_crud_acf'             => true,
			'product_crud_soft_delete'  => true,
			'product_force_delete'      => true,
			'variation_crud'            => true,
			'abilities_discovery'       => true,
			'abilities_execution'       => true,
			'negative_scenarios'        => true,
		],
		JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} finally {
	wp_set_current_user( (int) $admin->ID );
	foreach ( array_reverse( array_unique( array_filter( $cleanup_posts ) ) ) as $post_id ) {
		if ( get_post( $post_id ) ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	foreach ( array_reverse( $cleanup_terms ) as $term_info ) {
		[ $term_id, $taxonomy ] = $term_info;
		if ( $term_id > 0 && get_term( $term_id, $taxonomy ) instanceof WP_Term ) {
			wp_delete_term( $term_id, $taxonomy );
		}
	}
	wp_set_current_user( $previous_user );
}
