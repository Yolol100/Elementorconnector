<?php

namespace Webactueel\ElementorJsonBridge\Sync;

defined( 'ABSPATH' ) || exit;

final class State {
	public const CLEAN          = 'clean';
	public const LOCAL_DIRTY    = 'local_dirty';
	public const REMOTE_PENDING = 'remote_pending';
	public const CONFLICT       = 'conflict';
	public const APPLYING       = 'applying';
	public const VERIFIED       = 'verified';
	public const ERROR          = 'error';

	public const META_ENABLED       = '_ejb_enabled';
	public const META_STATUS        = '_ejb_status';
	public const META_BASE_HASH     = '_ejb_base_hash';
	public const META_REMOTE_SHA    = '_ejb_remote_sha';
	public const META_REMOTE_PATH   = '_ejb_remote_path';
	public const META_REPO_ID       = '_ejb_repo_identity';
	public const META_PENDING_SHA   = '_ejb_pending_sha';
	public const META_PENDING_HASH  = '_ejb_pending_hash';
	public const META_LAST_ERROR    = '_ejb_last_error';
	public const META_LAST_SYNC_AT  = '_ejb_last_sync_at';
}
