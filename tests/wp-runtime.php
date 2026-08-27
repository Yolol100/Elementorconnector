<?php
declare(strict_types=1);
use Webactueel\ElementorJsonBridge\Backup\Snapshots;
use Webactueel\ElementorJsonBridge\Elementor\Documents;
use Webactueel\ElementorJsonBridge\Elementor\PayloadValidator;
use Webactueel\ElementorJsonBridge\Plugin as BridgePlugin;
use Webactueel\ElementorJsonBridge\Sync\Lock;
if(!defined('ABSPATH'))exit(1);
$ids=get_users(['role'=>'administrator','fields'=>'ID','number'=>1]); if(!$ids)throw new RuntimeException('No admin'); wp_set_current_user((int)$ids[0]);
if(!did_action('elementor/loaded')||!defined('ELEMENTOR_VERSION')||version_compare((string)ELEMENTOR_VERSION,BridgePlugin::MIN_ELEMENTOR_VERSION,'<'))throw new RuntimeException('Elementor baseline failed');
$id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Original'],true); if(is_wp_error($id))throw new RuntimeException('page'); $id=(int)$id; update_post_meta($id,'_elementor_edit_mode','builder'); $sn=[];
try{$d=new Documents();$v=new PayloadValidator();$type=$d->document_type($id);$p=['title'=>'Remote rename blocked','type'=>$type,'version'=>'0.4','page_settings'=>[],'content'=>[['id'=>'ejbtest01','elType'=>'container','settings'=>[],'elements'=>[]]]];$d->save_payload($id,$v->validate_array($p,$type));$r=$d->payload($id);if($r['title']!=='Original')throw new RuntimeException('title changed');$s=new Snapshots();$sid=$s->create($id,$r,'runtime_test');$sn[]=$sid;$s->payload($sid,$id);$post=get_post($sid);$x=json_decode((string)$post->post_content,true);$x['title']='tampered';wp_update_post(['ID'=>$sid,'post_content'=>wp_slash(wp_json_encode($x))]);try{$s->payload($sid,$id);throw new RuntimeException('tamper accepted');}catch(RuntimeException $e){if($e->getMessage()==='tamper accepted')throw $e;}$l=new Lock();$t=$l->acquire($id);try{$l->acquire($id);throw new RuntimeException('concurrent lock');}catch(RuntimeException $e){if($e->getMessage()==='concurrent lock')throw $e;}$l->release($id,$t);}finally{delete_option(Lock::option_name($id));foreach($sn as $sid)wp_delete_post($sid,true);wp_delete_post($id,true);}echo 'PASS wp-runtime Elementor='.(string)ELEMENTOR_VERSION.' WP='.get_bloginfo('version').' PHP='.PHP_VERSION."\n";
