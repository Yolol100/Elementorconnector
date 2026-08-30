(() => {
	'use strict';

	const config = window.EJB_LOCAL_EXPORT;
	const wpElement = window.wp?.element;
	const wpComponents = window.wp?.components;
	if (!config || !wpElement || !wpComponents) return;

	const { createElement: h, Fragment, useEffect, useState } = wpElement;
	const { Button, Modal, Notice, Snackbar, ToggleControl } = wpComponents;

	const request = async (postId, includeSiteParts) => {
		const response = await fetch(`${config.restUrl}/local-export/${postId}`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify({ include_site_parts: includeSiteParts }),
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) {
			throw new Error(data?.message || config.strings.failed);
		}
		return data;
	};

	const downloadJson = (filename, payload) => {
		const blob = new Blob([`${JSON.stringify(payload, null, 2)}\n`], {
			type: 'application/json;charset=utf-8',
		});
		const url = window.URL.createObjectURL(blob);
		const link = document.createElement('a');
		link.href = url;
		link.download = filename || 'elementor-export.json';
		link.hidden = true;
		document.body.appendChild(link);
		link.click();
		link.remove();
		window.URL.revokeObjectURL(url);
	};

	const SuccessIcon = () =>
		h(
			'span',
			{
				className: 'ejb-export-toast__icon',
				'aria-hidden': 'true',
			},
			'✓'
		);

	const ExportModal = () => {
		const [postId, setPostId] = useState(null);
		const [includeSiteParts, setIncludeSiteParts] = useState(false);
		const [busy, setBusy] = useState(false);
		const [notice, setNotice] = useState(null);
		const [toast, setToast] = useState(null);

		useEffect(() => {
			const handleClick = (event) => {
				const link = event.target.closest('.ejb-local-export');
				if (!link) return;
				event.preventDefault();
				const selectedId = Number(link.dataset.postId || 0);
				if (selectedId < 1) return;
				setPostId(selectedId);
				setIncludeSiteParts(false);
				setNotice(null);
				setToast(null);
			};

			document.addEventListener('click', handleClick);
			return () => document.removeEventListener('click', handleClick);
		}, []);

		const dismissToast = () => setToast(null);
		const toastContent = toast
			? Snackbar
				? h(
					Snackbar,
					{
						className: 'ejb-export-toast',
						explicitDismiss: false,
						icon: h(SuccessIcon),
						onRemove: dismissToast,
						politeness: 'polite',
						spokenMessage: toast,
					},
					toast
				)
				: h(
					'div',
					{
						className: 'ejb-export-toast ejb-export-toast--fallback',
						role: 'status',
						'aria-live': 'polite',
					},
					h(SuccessIcon),
					h('span', { className: 'ejb-export-toast__message' }, toast)
				)
			: null;

		if (!postId) return toastContent;

		const close = () => {
			if (busy) return;
			setPostId(null);
			setNotice(null);
		};

		const exportJson = async () => {
			setBusy(true);
			setNotice(null);
			try {
				const data = await request(postId, includeSiteParts);
				downloadJson(data.filename, data.export);
				const warnings = Array.isArray(data.warnings) ? data.warnings.filter(Boolean) : [];
				if (warnings.length) {
					setNotice({
						status: 'warning',
						message: config.strings.downloadedWarning,
						warnings,
					});
				} else {
					setPostId(null);
					setIncludeSiteParts(false);
					setNotice(null);
					setToast(config.strings.downloaded);
				}
			} catch (error) {
				setNotice({
					status: 'error',
					message: error?.message || config.strings.failed,
					warnings: [],
				});
			} finally {
				setBusy(false);
			}
		};

		const noticeContent = notice
			? h(
				Notice,
				{
					status: notice.status,
					isDismissible: false,
					politeness: notice.status === 'error' ? 'assertive' : 'polite',
					className: 'ejb-export-modal__notice',
				},
				h('span', { className: 'ejb-export-modal__notice-message' }, notice.message),
				notice.warnings.length
					? h(
						'ul',
						null,
						...notice.warnings.map((warning, index) => h('li', { key: index }, warning))
					)
					: null
			)
			: null;

		const modal = h(
			Modal,
			{
				title: config.strings.title,
				onRequestClose: close,
				className: 'ejb-export-modal',
			},
			h(
				'div',
				{ className: 'ejb-export-modal__body' },
				h('p', { className: 'ejb-export-modal__intro' }, config.strings.intro),
				h(
					'div',
					{ className: 'ejb-export-modal__option' },
					h(ToggleControl, {
						label: config.strings.includeSiteParts,
						help: config.strings.includeSiteHelp,
						checked: includeSiteParts,
						disabled: busy,
						onChange: setIncludeSiteParts,
					})
				),
				noticeContent,
				h(
					'div',
					{ className: 'ejb-export-modal__actions' },
					h(
						Button,
						{
							variant: 'tertiary',
							disabled: busy,
							onClick: close,
						},
						config.strings.cancel
					),
					h(
						Button,
						{
							variant: 'primary',
							isBusy: busy,
							disabled: busy,
							onClick: exportJson,
						},
						busy ? config.strings.exporting : config.strings.export
					)
				)
			)
		);

		return h(Fragment, null, toastContent, modal);
	};

	const root = document.createElement('div');
	root.id = 'ejb-local-export-root';
	document.body.appendChild(root);

	if (typeof wpElement.createRoot === 'function') {
		wpElement.createRoot(root).render(h(ExportModal));
	} else if (typeof wpElement.render === 'function') {
		wpElement.render(h(ExportModal), root);
	}
})();
