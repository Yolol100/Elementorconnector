from pathlib import Path

script_path = Path('.github/repair_all_remote_mutations_v2.py')
script = script_path.read_text(encoding='utf-8')

old_verify_call = r'''replace_once(
    path,
    '\t\t$this->verify_state( $id, $desired_content, $desired_woo, $core );',
    "\t\t$this->verify_state( $id, [ 'content' => $desired_content, 'woocommerce' => $desired_woo ] );",
)
'''
new_verify_code = r'''product_text = Path(path).read_text(encoding='utf-8')
verify_old = '\t\t$this->verify_state( $id, $desired_content, $desired_woo, $core );'
verify_new = "\t\t$this->verify_state( $id, [ 'content' => $desired_content, 'woocommerce' => $desired_woo ] );"
if product_text.count(verify_old) != 2:
    raise RuntimeError(f'{path}: expected two pre-migration verify calls, found {product_text.count(verify_old)}')
Path(path).write_text(product_text.replace(verify_old, verify_new, 1), encoding='utf-8')
'''
if script.count(old_verify_call) != 1:
    raise RuntimeError('The v2 repair verify-call anchor changed.')
script = script.replace(old_verify_call, new_verify_code, 1)

old_product_closure_call = '''replace_once(path, "static function () use ( $products, $product_id ): void {", "static function () use ( $products, $product_id, $product_base_hash ): void {")
'''
new_product_closure_code = '''runtime_text = Path(path).read_text(encoding='utf-8')
product_closure_old = "static function () use ( $products, $product_id ): void {"
product_closure_new = "static function () use ( $products, $product_id, $product_base_hash ): void {"
if runtime_text.count(product_closure_old) != 2:
    raise RuntimeError(f'{path}: expected two pre-migration product test closures, found {runtime_text.count(product_closure_old)}')
Path(path).write_text(runtime_text.replace(product_closure_old, product_closure_new, 1), encoding='utf-8')
'''
if script.count(old_product_closure_call) != 1:
    raise RuntimeError('The v2 repair product-closure anchor changed.')
script = script.replace(old_product_closure_call, new_product_closure_code, 1)

exec(compile(script, str(script_path), 'exec'))
