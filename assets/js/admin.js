(() => {
	'use strict';

	const config = window.EJB_ADMIN;
	if (!config) return;

	const message = document.getElementById('ejb-message');
	let pollTimer = null;

	const showMessage = (text, type = 'success') => {
		if (!message) return;
		message.hidden = false;
		message.className = `notice notice-${type} inline`;
		const p = message.querySelector('p');
		if (p) p.textContent = text;
	};

	const request = async (path, body = {}) => {
		const response = await fetch(`${config.restUrl}${path}`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify(body),
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) {
			throw new Error(data?.message || 'Request failed.');
		}
		return data;
	};

	const pollDevice = async (delaySeconds) => {
		window.clearTimeout(pollTimer);
		pollTimer = window.setTimeout(async () => {
			try {
				const data = await request('/auth/device/poll');
				if (data.status === 'connected') {
					showMessage('GitHub connected. Reloading…');
					window.setTimeout(() => window.location.reload(), 700);
					return;
				}
				pollDevice(Number(data.retry_after || 5));
			} catch (error) {
				showMessage(error.message, 'error');
			}
		}, Math.max(1, Number(delaySeconds || 5)) * 1000);
	};

	document.getElementById('ejb-connect')?.addEventListener('click', async () => {
		try {
			const data = await request('/auth/device');
			const box = document.getElementById('ejb-device');
			const code = document.getElementById('ejb-device-code');
			const link = document.getElementById('ejb-device-link');
			if (!String(data.verification_uri || '').startsWith('https://github.com/')) {
				throw new Error('Unexpected GitHub verification URL.');
			}
			if (box) box.hidden = false;
			if (code) code.textContent = data.user_code;
			if (link) link.href = data.verification_uri;
			showMessage('Authorize the app on GitHub. This page will detect the connection automatically.', 'info');
			pollDevice(Number(data.interval || 5));
		} catch (error) {
			showMessage(error.message, 'error');
		}
	});

	document.getElementById('ejb-disconnect')?.addEventListener('click', async () => {
		if (!window.confirm('Disconnect GitHub from this WordPress site?')) return;
		try {
			await request('/auth/disconnect');
			window.location.reload();
		} catch (error) {
			showMessage(error.message, 'error');
		}
	});

	document.getElementById('ejb-test-repo')?.addEventListener('click', async () => {
		try {
			const data = await request('/repository/test');
			showMessage(`Repository access works: ${data.full_name} (default branch: ${data.default_branch}).`);
		} catch (error) {
			showMessage(error.message, 'error');
		}
	});

	document.querySelectorAll('.ejb-doc-action').forEach((button) => {
		button.addEventListener('click', async () => {
			const id = button.dataset.id;
			const action = button.dataset.action;
			if (action === 'apply' && !window.confirm('Apply the GitHub JSON to this Elementor document? A local snapshot will be created first.')) return;
			if (action === 'reset' && !window.confirm('Reset the remembered synchronization base? This does not delete or overwrite any GitHub file.')) return;
			button.disabled = true;
			try {
				await request(`/documents/${id}/${action}`);
				window.location.reload();
			} catch (error) {
				button.disabled = false;
				showMessage(error.message, 'error');
			}
		});
	});

	document.querySelectorAll('.ejb-restore').forEach((button) => {
		button.addEventListener('click', async () => {
			if (!window.confirm('Restore the latest local snapshot? The current version will be snapshotted first.')) return;
			button.disabled = true;
			try {
				await request(`/documents/${button.dataset.id}/restore/${button.dataset.snapshot}`);
				window.location.reload();
			} catch (error) {
				button.disabled = false;
				showMessage(error.message, 'error');
			}
		});
	});
})();
