(() => {
	'use strict';

	const config = window.EJB_TEMPLATE_IMPORT;
	const wpElement = window.wp?.element;
	const wpComponents = window.wp?.components;
	if (!config || !wpElement || !wpComponents) return;

	const { createElement: h, Fragment, useEffect, useRef, useState } = wpElement;
	const {
		Button,
		CheckboxControl,
		Modal,
		Notice,
		RadioControl,
		Snackbar,
		Spinner,
		TextControl,
	} = wpComponents;

	let bypassNextNativeImport = false;

	const request = async (path, options = {}) => {
		const response = await fetch(`${config.restUrl}${path}`, {
			credentials: 'same-origin',
			...options,
			headers: {
				'X-WP-Nonce': config.nonce,
				...(options.headers || {}),
			},
		});
		const data = await response.json().catch(() => ({}));
		if (!response.ok) {
			throw new Error(data?.message || config.strings.failed);
		}
		return data;
	};

	const fileIsValid = (file) =>
		file instanceof File &&
		file.size > 0 &&
		file.size <= Number(config.maxBytes || 0) &&
		/\.json$/i.test(file.name);

	const descriptorLabel = (target) =>
		target ? `${target.kind}: ${target.title} (#${target.id})` : '';

	const TemplateImportApp = () => {
		const fileInput = useRef(null);
		const [open, setOpen] = useState(false);
		const [file, setFile] = useState(null);
		const [analysis, setAnalysis] = useState(null);
		const [analyzing, setAnalyzing] = useState(false);
		const [action, setAction] = useState('new_template');
		const [targetId, setTargetId] = useState(0);
		const [targetSearch, setTargetSearch] = useState('');
		const [targets, setTargets] = useState([]);
		const [searching, setSearching] = useState(false);
		const [confirmed, setConfirmed] = useState(false);
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState(null);
		const [toast, setToast] = useState(null);

		const reset = () => {
			setFile(null);
			setAnalysis(null);
			setAnalyzing(false);
			setAction('new_template');
			setTargetId(0);
			setTargetSearch('');
			setTargets([]);
			setSearching(false);
			setConfirmed(false);
			setBusy(false);
			setError(null);
		};

		useEffect(() => {
			const handleNativeImport = (event) => {
				const target = event.target;
				if (!(target instanceof Element)) return;
				const trigger = target.closest('#elementor-import-template-trigger');
				if (!trigger) return;
				if (bypassNextNativeImport) {
					bypassNextNativeImport = false;
					return;
				}
				event.preventDefault();
				event.stopPropagation();
				event.stopImmediatePropagation();
				reset();
				setToast(null);
				setOpen(true);
			};

			document.addEventListener('click', handleNativeImport, true);
			return () => document.removeEventListener('click', handleNativeImport, true);
		}, []);

		useEffect(() => {
			if (!open || action !== 'replace' || !analysis?.source?.type) return undefined;

			let active = true;
			const timer = window.setTimeout(async () => {
				setSearching(true);
				try {
					const params = new URLSearchParams({
						search: targetSearch,
						source_type: analysis.source.type,
					});
					const data = await request(`/template-import/targets?${params.toString()}`);
					if (active) setTargets(Array.isArray(data.targets) ? data.targets : []);
				} catch (searchError) {
					if (active) setError(searchError?.message || config.strings.failed);
				} finally {
					if (active) setSearching(false);
				}
			}, 250);

			return () => {
				active = false;
				window.clearTimeout(timer);
			};
		}, [open, action, analysis?.source?.type, targetSearch]);

		const close = () => {
			if (busy || analyzing) return;
			setOpen(false);
			reset();
		};

		const standardImport = () => {
			if (busy || analyzing) return;
			setOpen(false);
			reset();
			const trigger = document.getElementById('elementor-import-template-trigger');
			if (!trigger) return;
			bypassNextNativeImport = true;
			window.setTimeout(() => trigger.click(), 0);
		};

		const analyzeFile = async (selectedFile) => {
			if (!fileIsValid(selectedFile)) {
				setError(config.strings.invalidFile);
				setFile(null);
				setAnalysis(null);
				return;
			}

			setFile(selectedFile);
			setAnalysis(null);
			setAction('new_template');
			setTargetId(0);
			setTargetSearch('');
			setConfirmed(false);
			setError(null);
			setAnalyzing(true);

			const form = new FormData();
			form.append('file', selectedFile);
			try {
				const data = await request('/template-import/analyze', {
					method: 'POST',
					body: form,
				});
				setAnalysis(data);
				if (data.recognized_target) {
					setTargetId(Number(data.recognized_target.id || 0));
					setTargetSearch(data.recognized_target.title || '');
					setTargets([data.recognized_target]);
				}
			} catch (analyzeError) {
				setError(analyzeError?.message || config.strings.failed);
			} finally {
				setAnalyzing(false);
			}
		};

		const chooseRecognized = () => {
			const target = analysis?.recognized_target;
			if (!target) return;
			setAction('replace');
			setTargetId(Number(target.id || 0));
			setTargetSearch(target.title || '');
			setTargets([target]);
			setConfirmed(false);
		};

		const importJson = async () => {
			if (!file || !analysis) return;
			if (action === 'replace' && targetId < 1) {
				setError(config.strings.targetRequired);
				return;
			}
			if (action === 'replace' && !confirmed) {
				setError(config.strings.confirmationRequired);
				return;
			}
			if ((action === 'new_page' || action === 'new_post') && !analysis.available_actions?.[action]) {
				setError(config.strings.pageLikeRequired);
				return;
			}

			setBusy(true);
			setError(null);
			const form = new FormData();
			form.append('file', file);
			form.append('import_action', action);
			form.append('target_id', String(targetId || 0));

			try {
				const data = await request('/template-import/execute', {
					method: 'POST',
					body: form,
				});
				setOpen(false);
				reset();
				setToast(config.strings.imported);
				if (action === 'new_template' && data?.result?.post_type === 'elementor_library') {
					window.setTimeout(() => window.location.reload(), 1400);
				}
			} catch (importError) {
				setError(importError?.message || config.strings.failed);
			} finally {
				setBusy(false);
			}
		};

		const toastContent = toast
			? Snackbar
				? h(
					Snackbar,
					{
						className: 'ejb-template-import-toast',
						explicitDismiss: false,
						onRemove: () => setToast(null),
						politeness: 'polite',
						spokenMessage: toast,
					},
					toast
				)
				: h(
					'div',
					{
						className: 'ejb-template-import-toast ejb-template-import-toast--fallback',
						role: 'status',
						'aria-live': 'polite',
					},
					toast
				)
			: null;

		if (!open) return toastContent;

		const warnings = Array.isArray(analysis?.warnings) ? analysis.warnings.filter(Boolean) : [];
		const source = analysis?.source;
		const recognized = analysis?.recognized_target;
		const confidenceLabel = analysis?.recognition?.confidence === 'high'
			? config.strings.highConfidence
			: config.strings.mediumConfidence;
		const actionOptions = analysis
			? [
				{
					label: config.strings.replace,
					value: 'replace',
				},
				{
					label: config.strings.newPage,
					value: 'new_page',
					disabled: !analysis.available_actions?.new_page,
				},
				{
					label: config.strings.newPost,
					value: 'new_post',
					disabled: !analysis.available_actions?.new_post,
				},
				{
					label: config.strings.newTemplate,
					value: 'new_template',
				},
			]
			: [];
		const actionHelp = {
			replace: config.strings.replaceHelp,
			new_page: config.strings.newPageHelp,
			new_post: config.strings.newPostHelp,
			new_template: config.strings.newTemplateHelp,
		}[action];

		const modal = h(
			Modal,
			{
				title: config.strings.title,
				onRequestClose: close,
				className: 'ejb-template-import-modal',
			},
			h(
				'div',
				{ className: 'ejb-template-import-modal__body' },
				h('p', { className: 'ejb-template-import-modal__intro' }, config.strings.intro),
				h(
					'div',
					{ className: 'ejb-template-import-file' },
					h('input', {
						ref: fileInput,
						type: 'file',
						accept: '.json,application/json',
						className: 'ejb-template-import-file__input',
						onChange: (event) => analyzeFile(event.target.files?.[0] || null),
					}),
					h(
						Button,
						{
							variant: 'secondary',
							disabled: busy || analyzing,
							onClick: () => fileInput.current?.click(),
						},
						config.strings.chooseFile
					),
					h(
						'div',
						{ className: 'ejb-template-import-file__copy' },
						h('strong', null, file ? file.name : config.strings.fileHelp),
						file ? h('span', null, config.strings.fileHelp) : null
					)
				),
				analyzing
					? h(
						'div',
						{ className: 'ejb-template-import-loading', role: 'status' },
						h(Spinner),
						h('span', null, config.strings.analyzing)
					)
					: null,
				source
					? h(
						'div',
						{ className: 'ejb-template-import-summary' },
						h('span', { className: 'ejb-template-import-summary__eyebrow' }, config.strings.source),
						h('strong', null, source.title),
						h('span', null, `${source.type} · ${source.filename}`)
					)
					: null,
				warnings.length
					? h(
						Notice,
						{
							status: 'warning',
							isDismissible: false,
							politeness: 'polite',
							className: 'ejb-template-import-notice',
						},
						h('ul', null, ...warnings.map((warning, index) => h('li', { key: index }, warning)))
					)
					: null,
				recognized
					? h(
						'div',
						{ className: 'ejb-template-import-match' },
						h(
							'div',
							{ className: 'ejb-template-import-match__copy' },
							h('span', { className: 'ejb-template-import-summary__eyebrow' }, `${config.strings.recognized} · ${confidenceLabel}`),
							h('strong', null, descriptorLabel(recognized))
						),
						h(
							Button,
							{ variant: 'secondary', onClick: chooseRecognized, disabled: busy },
							config.strings.useRecognized
						)
					)
					: null,
				analysis
					? h(
						Fragment,
						null,
						h(RadioControl, {
							label: config.strings.chooseAction,
							selected: action,
							options: actionOptions,
							disabled: busy,
							onChange: (value) => {
								setAction(value);
								setConfirmed(false);
								setError(null);
							},
							className: 'ejb-template-import-actions-choice',
						}),
						h('p', { className: 'ejb-template-import-action-help' }, actionHelp),
						action === 'replace'
							? h(
								'div',
								{ className: 'ejb-template-import-target' },
								h(TextControl, {
									label: config.strings.target,
									help: config.strings.searchTarget,
									value: targetSearch,
									disabled: busy,
									onChange: (value) => {
										setTargetSearch(value);
										setTargetId(0);
										setConfirmed(false);
									},
								}),
								searching
									? h('div', { className: 'ejb-template-import-loading ejb-template-import-loading--small', role: 'status' }, h(Spinner), h('span', null, config.strings.searching))
									: null,
								!searching && targets.length
									? h(RadioControl, {
										selected: targetId ? String(targetId) : '',
										options: targets.map((target) => ({
											label: descriptorLabel(target),
											value: String(target.id),
										})),
										disabled: busy,
										onChange: (value) => {
											setTargetId(Number(value || 0));
											setConfirmed(false);
										},
										className: 'ejb-template-import-target__results',
									})
									: null,
								!searching && !targets.length
									? h('p', { className: 'ejb-template-import-empty' }, config.strings.noTargets)
									: null,
								targetId
									? h(
										'div',
										{ className: 'ejb-template-import-confirm' },
										h(CheckboxControl, {
											label: config.strings.confirmReplace,
											checked: confirmed,
											disabled: busy,
											onChange: setConfirmed,
										})
									)
									: null
							)
							: null
					)
					: null,
				error
					? h(
						Notice,
						{
							status: 'error',
							isDismissible: false,
							politeness: 'assertive',
							className: 'ejb-template-import-notice',
						},
						h('span', null, error)
					)
					: null,
				h(
					'div',
					{ className: 'ejb-template-import-modal__actions' },
					h(
						Button,
						{ variant: 'tertiary', disabled: busy || analyzing, onClick: standardImport },
						config.strings.standardImport
					),
					h('div', { className: 'ejb-template-import-modal__actions-spacer' }),
					h(
						Button,
						{ variant: 'tertiary', disabled: busy || analyzing, onClick: close },
						config.strings.cancel
					),
					h(
						Button,
						{
							variant: 'primary',
							isBusy: busy,
							disabled: busy || analyzing || !analysis,
							onClick: importJson,
						},
						busy ? config.strings.importing : config.strings.import
					)
				)
			)
		);

		return h(Fragment, null, toastContent, modal);
	};

	const root = document.createElement('div');
	root.id = 'ejb-template-import-root';
	document.body.appendChild(root);

	if (typeof wpElement.createRoot === 'function') {
		wpElement.createRoot(root).render(h(TemplateImportApp));
	} else if (typeof wpElement.render === 'function') {
		wpElement.render(h(TemplateImportApp), root);
	}
})();
