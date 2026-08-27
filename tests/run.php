<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['ejb_test_options'] = [];
function wp_json_encode(mixed $v,int $f=0,int $d=512): string|false { return json_encode($v,$f,$d); }
function wp_salt(string $s='auth'): string { return 'ejb-test-'.$s; }
function get_option(string $k,mixed $d=false): mixed { return $GLOBALS['ejb_test_options'][$k] ?? $d; }
function wp_parse_args(mixed $a,array $d=[]): array { return array_merge($d,is_array($a)?$a:[]); }
require dirname(__DIR__).'/includes/Support/CanonicalJson.php';
require dirname(__DIR__).'/includes/Support/BridgeException.php';
require dirname(__DIR__).'/includes/Elementor/PayloadValidator.php';
require dirname(__DIR__).'/includes/Security/SecretBox.php';
require dirname(__DIR__).'/includes/Settings.php';
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Security\SecretBox;
use Webactueel\ElementorJsonBridge\Settings;
use Webactueel\ElementorJsonBridge\Support\CanonicalJson;
$tests=[];
$valid=static fn()=>['title'=>'Home','type'=>'wp-page','version'=>'0.4','page_settings'=>[],'content'=>[['id'=>'abc123','elType'=>'container','settings'=>[],'elements'=>[]]]];
$throws=static function(callable $fn): void { try{$fn();}catch(RuntimeException){return;} throw new RuntimeException('Expected exception'); };
$tests['canonical']=static function():void{if(CanonicalJson::hash(['b'=>2,'a'=>1])!==CanonicalJson::hash(['a'=>1,'b'=>2]))throw new RuntimeException('canonical');};
$tests['payload-valid']=static function()use($valid):void{(new PayloadValidator())->validate_array($valid(),'wp-page');};
$tests['payload-type']=static function()use($valid,$throws):void{$throws(static fn()=>(new PayloadValidator())->validate_array($valid(),'wp-post'));};
$tests['payload-duplicate']=static function()use($valid,$throws):void{$p=$valid();$p['content'][]=$p['content'][0];$throws(static fn()=>(new PayloadValidator())->validate_array($p,'wp-page'));};
$tests['path-traversal']=static function():void{if(Settings::sanitize_repo_path('../elementor/../../pages/../safe')!=='elementor/pages/safe')throw new RuntimeException('path');};
$tests['repo-identity']=static function():void{$GLOBALS['ejb_test_options'][Settings::OPTION]=['repo_owner'=>'A','repo_name'=>'B','repo_branch'=>'main','repo_root'=>'elementor'];$a=Settings::repository_identity();$GLOBALS['ejb_test_options'][Settings::OPTION]['repo_branch']='dev';if($a===Settings::repository_identity())throw new RuntimeException('identity');};
$tests['crypto']=static function()use($throws):void{$b=new SecretBox();$e=$b->encrypt(['access_token'=>'x']);if(($b->decrypt($e)['access_token']??'')!=='x')throw new RuntimeException('crypto');$raw=base64_decode($e,true);$p=json_decode((string)$raw,true);$c=base64_decode((string)$p['cipher'],true);$c[0]=chr(ord($c[0])^1);$p['cipher']=base64_encode($c);$throws(static fn()=>$b->decrypt(base64_encode(json_encode($p))));};
$tests['no-title-write']=static function():void{$s=file_get_contents(dirname(__DIR__).'/includes/Elementor/Documents.php');if(str_contains((string)$s,'wp_update_post('))throw new RuntimeException('title write');};
$tests['snapshot-hash']=static function():void{$s=file_get_contents(dirname(__DIR__).'/includes/Backup/Snapshots.php');if(!str_contains((string)$s,'hash_equals'))throw new RuntimeException('snapshot');};
$tests['repo-guard']=static function():void{$s=file_get_contents(dirname(__DIR__).'/includes/Sync/Manager.php');if(!str_contains((string)$s,'assert_repository_identity'))throw new RuntimeException('guard');};
$tests['atomic-lock']=static function():void{$s=file_get_contents(dirname(__DIR__).'/includes/Sync/Lock.php');if(!str_contains((string)$s,'add_option(')||!str_contains((string)$s,'option_value = %s'))throw new RuntimeException('lock');};
$tests['license']=static function():void{$s=file_get_contents(dirname(__DIR__).'/LICENSE');if(!str_contains((string)$s,'END OF TERMS AND CONDITIONS')||preg_match('//u',(string)$s)!==1)throw new RuntimeException('license');};
$f=0;foreach($tests as $n=>$t){try{$t();echo "PASS $n\n";}catch(Throwable $e){$f++;fwrite(STDERR,"FAIL $n: {$e->getMessage()}\n");}}if($f)exit(1);echo 'PASS total='.count($tests)."\n";
