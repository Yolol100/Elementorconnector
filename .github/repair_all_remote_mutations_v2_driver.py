from pathlib import Path

script_path = Path('.github/repair_all_remote_mutations_v2.py')
script = script_path.read_text(encoding='utf-8')
old = """replace_once(
    path,
    '\\t\\t$this->verify_state( $id, $desired_content, $desired_woo, $core );',
    \"\\t\\t$this->verify_state( $id, [ 'content' => $desired_content, 'woocommerce' => $desired_woo ] );\",
)
"""
new = """product_text = Path(path).read_text(encoding='utf-8')
verify_old = '\\t\\t$this->verify_state( $id, $desired_content, $desired_woo, $core );'
verify_new = \"\\t\\t$this->verify_state( $id, [ 'content' => $desired_content, 'woocommerce' => $desired_woo ] );\"
if product_text.count(verify_old) != 2:
    raise RuntimeError(f'{path}: expected two pre-migration verify calls, found {product_text.count(verify_old)}')
Path(path).write_text(product_text.replace(verify_old, verify_new, 1), encoding='utf-8')
"""
if script.count(old) != 1:
    raise RuntimeError('The v2 repair verify-call anchor changed.')
script = script.replace(old, new, 1)
exec(compile(script, str(script_path), 'exec'))
