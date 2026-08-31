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
		Snackbar,
		Spinner,
	} = wpComponents;

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
		const [replaceExisting, setReplaceExisting] = useState(false);
		const [busy, setBusy] = useState(false);
		const [error, setError] = useState(null);
		const [toast, setToast] = useState(null);

		const reset = () => {
			setFile(null);
			setAnalysis(null);
			setAnalyzing(false);
			setReplaceExisting(false);
			setBusy(false);
			setError(null);
		};

		useEffect(() => {
			const handleImportButton = (event) => {
				const target = event.target;
				if (!(target instanceof Element)) return;
				const trigger = target.closest('.ejb-template-import-trigger');
				if (!trigger) return;
				event.preventDefault();
				reset();
				setToast(null);
				setOpen(true);
			};

			document.addEventListener('click', handleImportButton);
			return () => document.removeEventListener('click', handleImportButton);
		}, []);

		const close = () => {
			if (busy || analyzing) return;
			setOpen(false);
			reset();
		};

		const analyzeFile = async (selectedFile) => {
			if (!fileIsValid(selectedFile)) {
				setError(config.strings.invalidFile);
				setFile(null);
				setAnalysis(null);
				setReplaceExisting(false);
				return;
			}

			setFile(selectedFile);
			setAnalysis(null);
			setReplaceExisting(false);
			setError(null);
			setAnalyzing(true);

			const form = new FormData();
			form.append('file', selectedFile);
			form.append('destination', config.destination);
			try {
				const data = await request('/template-import/analyze', {
					method: 'POST',
					body: form,
				});
				setAnalysis(data);
			} catch (analyzeError) {
				setError(analyzeError?.message || config.strings.failed);
			} finally {
				setAnalyzing(false);
			}
		};

		const importJson = async () => {
			if (!file || !analysis) return;
			const recognized = analysis.recognized_target;
			if (replaceExisting && !recognized) {
				setError(config.strings.failed);
				return;
			}

			setBusy(true);
			setError(null);
			const form = new FormData();
			form.append('file', file);
			form.append('destination', config.destination);
			form.append('replace_existing', replaceExisting ? '1' : '0');
			form.append('expected_target_id', replaceExisting ? String(recognized.id || 0) : '0');

			try {
				const data = await request('/template-import/execute', {
					method: 'POST',
					body: form,
				});
				const wasReplaced = data?.result?.action === 'replaced';
				setOpen(false);
				reset();
				setToast(wasReplaced ? config.strings.replaced : config.strings.created);
				window.setTimeout(() => window.location.reload(), 1200);
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
						{ className: `ejb-template-import-replace${replaceExisting ? ' is-selected' : ''}` },
						h(
							'div',
							{ className: 'ejb-template-import-replace__match' },
							h('span', { className: 'ejb-template-import-summary__eyebrow' }, `${config.strings.match} · ${confidenceLabel}`),
							h('strong', null, descriptorLabel(recognized))
						),
						h(CheckboxControl, {
							label: config.strings.replaceExisting,
							help: config.strings.replaceHelp,
							checked: replaceExisting,
							disabled: busy,
							onChange: (value) => {
								setReplaceExisting(value);
								setError(null);
							},
						})
					)
					: source
						? h('p', { className: 'ejb-template-import-empty' }, config.strings.noMatch)
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
