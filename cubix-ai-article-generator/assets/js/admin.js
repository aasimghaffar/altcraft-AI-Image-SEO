/**
 * Cubix AI Article Generator — admin scripts.
 *
 * Vanilla JS, no dependencies. All server communication goes through
 * admin-ajax.php with the nonce provided by wp_localize_script()
 * (window.cxaiData). Any HTML that reaches innerHTML was sanitized
 * server-side with wp_kses_post().
 *
 * @package Cubix_AI_Article_Generator
 */

( function () {
	'use strict';

	if ( typeof window.cxaiData === 'undefined' ) {
		return;
	}

	var cfg = window.cxaiData;

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * POST to admin-ajax.php and resolve with the parsed JSON envelope.
	 *
	 * @param {string} action AJAX action name.
	 * @param {Object} data   Extra body fields.
	 * @return {Promise<Object>}
	 */
	function ajaxPost( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Read the error message out of a wp_send_json_error envelope.
	 *
	 * @param {Object} json Response JSON.
	 * @return {string}
	 */
	function errorMessage( json ) {
		if ( json && json.data && json.data.message ) {
			return json.data.message;
		}
		return cfg.i18n.genericError;
	}

	/**
	 * Copy text with the Clipboard API and a legacy fallback.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise<void>}
	 */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.setAttribute( 'readonly', '' );
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			document.body.appendChild( textarea );
			textarea.select();

			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( err ) {
				reject( err );
			} finally {
				document.body.removeChild( textarea );
			}
		} );
	}

	/**
	 * Show a transient bottom-center toast.
	 *
	 * @param {string} message Message text.
	 */
	function toast( message ) {
		var el = document.createElement( 'div' );
		el.className = 'cx-toast';
		el.setAttribute( 'role', 'status' );
		el.textContent = message;
		document.body.appendChild( el );

		window.setTimeout( function () {
			el.classList.add( 'is-out' );
			window.setTimeout( function () {
				el.remove();
			}, 350 );
		}, 2200 );
	}

	/**
	 * Escape a string for use inside an HTML attribute/text node.
	 *
	 * @param {string} value Raw string.
	 * @return {string}
	 */
	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value;
		return div.innerHTML;
	}

	/* ------------------------------------------------------------------ *
	 * Settings: tab rail
	 * ------------------------------------------------------------------ */

	function initTabs() {
		var tabs = document.querySelectorAll( '.cx-rail-tab' );

		if ( ! tabs.length ) {
			return;
		}

		function activate( id, focusTab ) {
			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-panel' ) === id;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );

				if ( active && focusTab ) {
					tab.focus();
				}
			} );

			document.querySelectorAll( '.cx-panel' ).forEach( function ( panel ) {
				var active = panel.id === 'cx-panel-' + id;
				panel.classList.toggle( 'is-active', active );

				if ( active ) {
					panel.removeAttribute( 'hidden' );
				} else {
					panel.setAttribute( 'hidden', '' );
				}
			} );

			// Remember the open tab across the settings save round-trip.
			try {
				window.sessionStorage.setItem( 'cxaiTab', id );
			} catch ( err ) {
				// Session storage unavailable — fine, we just won't restore.
			}
		}

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-panel' ), false );
			} );

			// Roving arrow-key support on the tablist.
			tab.addEventListener( 'keydown', function ( event ) {
				var delta = 0;

				if ( 'ArrowDown' === event.key || 'ArrowRight' === event.key ) {
					delta = 1;
				} else if ( 'ArrowUp' === event.key || 'ArrowLeft' === event.key ) {
					delta = -1;
				} else {
					return;
				}

				event.preventDefault();
				var next = ( index + delta + tabs.length ) % tabs.length;
				activate( tabs[ next ].getAttribute( 'data-panel' ), true );
			} );
		} );

		// A #hash wins over the remembered tab, so links like
		// admin.php?page=cxai-settings#writing land on the right panel.
		var hash = window.location.hash.replace( '#', '' );

		if ( hash && document.getElementById( 'cx-panel-' + hash ) ) {
			activate( hash, false );
			return;
		}

		// Restore the last open tab (useful right after saving).
		var saved = null;

		try {
			saved = window.sessionStorage.getItem( 'cxaiTab' );
		} catch ( err ) {
			saved = null;
		}

		if ( saved && document.getElementById( 'cx-panel-' + saved ) ) {
			activate( saved, false );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Settings: engines
	 * ------------------------------------------------------------------ */

	function initEngines() {
		var engines = document.querySelectorAll( '.cx-engine' );

		if ( ! engines.length ) {
			return;
		}

		// Show/hide key. Saved keys are never sent back to the browser for
		// security, so the eye only appears while there is typed text to
		// reveal — and the icon reflects the current state.
		document.querySelectorAll( '.cx-key-toggle' ).forEach( function ( button ) {
			var input = button.parentElement.querySelector( '.cx-api-key' );

			if ( ! input ) {
				return;
			}

			function sync() {
				button.style.display = input.value.length ? '' : 'none';
			}

			sync();
			input.addEventListener( 'input', sync );

			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				var showing = 'text' === input.type;
				input.type = showing ? 'password' : 'text';

				var icon = button.querySelector( '.dashicons' );

				if ( icon ) {
					icon.classList.toggle( 'dashicons-visibility', showing );
					icon.classList.toggle( 'dashicons-hidden', ! showing );
				}

				input.focus();
			} );
		} );

		// Test connection.
		document.querySelectorAll( '.cx-test-key' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				var engine = button.closest( '.cx-engine' );
				var input = engine ? engine.querySelector( '.cx-api-key' ) : null;
				var modelSel = engine ? engine.querySelector( 'select' ) : null;
				var result = engine ? engine.querySelector( '.cx-engine-result' ) : null;

				if ( ! result ) {
					return;
				}

				button.disabled = true;
				button.textContent = cfg.i18n.testing;
				result.textContent = '';
				result.className = 'cx-engine-result';

				ajaxPost( 'cxai_test_key', {
					provider: button.getAttribute( 'data-provider' ) || '',
					api_key: input ? input.value.trim() : '',
					model: modelSel ? modelSel.value : ''
				} )
					.then( function ( json ) {
						result.textContent = json.success ? json.data.message : errorMessage( json );
						result.classList.add( json.success ? 'is-ok' : 'is-fail' );
					} )
					.catch( function () {
						result.textContent = cfg.i18n.genericError;
						result.classList.add( 'is-fail' );
					} )
					.finally( function () {
						button.disabled = false;
						button.textContent = cfg.i18n.test;
					} );
			} );
		} );

		// Typing a key enables its Test button immediately.
		document.querySelectorAll( '.cx-api-key' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				var engine = input.closest( '.cx-engine' );
				var button = engine ? engine.querySelector( '.cx-test-key' ) : null;

				if ( button && '' !== input.value.trim() ) {
					button.disabled = false;
				}
			} );
		} );

	}

	/* ------------------------------------------------------------------ *
	 * Settings: creativity slider, prompt library, advanced tools
	 * ------------------------------------------------------------------ */

	function initSettingsMisc() {
		// Live slider value.
		var range = document.getElementById( 'cx-temperature' );
		var out = document.getElementById( 'cx-temperature-out' );

		if ( range && out ) {
			range.addEventListener( 'input', function () {
				out.textContent = range.value;
			} );
		}

		// Prompt library rows.
		var library = document.getElementById( 'cx-library' );
		var addBtn = document.getElementById( 'cx-library-add' );

		if ( library && addBtn ) {
			function renumber() {
				library.querySelectorAll( '.cx-library-row' ).forEach( function ( row, index ) {
					var handle = row.querySelector( '.cx-library-handle' );

					if ( handle ) {
						handle.textContent = String( index + 1 ).padStart( 2, '0' );
					}

					row.querySelectorAll( 'input' ).forEach( function ( input ) {
						input.name = input.name.replace( /\[templates\]\[\d+\]/, '[templates][' + index + ']' );
					} );
				} );
			}

			library.addEventListener( 'click', function ( event ) {
				var remove = event.target.closest( '.cx-library-remove' );

				if ( remove ) {
					remove.closest( '.cx-library-row' ).remove();
					renumber();
				}
			} );

			addBtn.addEventListener( 'click', function () {
				var index = library.querySelectorAll( '.cx-library-row' ).length;
				var row = document.createElement( 'div' );
				row.className = 'cx-library-row';
				row.innerHTML =
					'<span class="cx-library-handle" aria-hidden="true">' + String( index + 1 ).padStart( 2, '0' ) + '</span>' +
					'<input type="text" class="cx-input cx-library-label" name="' + cfg.optionKey + '[templates][' + index + '][label]" value="" placeholder="' + escapeHtml( cfg.i18n.labelPh ) + '" />' +
					'<input type="text" class="cx-input cx-library-prompt" name="' + cfg.optionKey + '[templates][' + index + '][prompt]" value="" placeholder="' + escapeHtml( cfg.i18n.promptPh ) + '" />' +
					'<button type="button" class="cx-library-remove" aria-label="' + escapeHtml( cfg.i18n.removeTpl ) + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>';
				library.appendChild( row );
				row.querySelector( '.cx-library-label' ).focus();
			} );
		}

		// Export saved settings (minus keys) as JSON — built server-side.
		var exportBtn = document.getElementById( 'cx-export' );
		var exportOut = document.getElementById( 'cx-export-output' );

		if ( exportBtn && exportOut ) {
			exportBtn.addEventListener( 'click', function () {
				exportBtn.disabled = true;

				ajaxPost( 'cxai_export_settings', {} )
					.then( function ( json ) {
						if ( json.success && json.data && json.data.json ) {
							exportOut.value = json.data.json;
							copyText( json.data.json ).then( function () {
								toast( cfg.i18n.exported );
							} );
						}
					} )
					.finally( function () {
						exportBtn.disabled = false;
					} );
			} );
		}

		// Import settings JSON from another site.
		var importBtn = document.getElementById( 'cx-import' );
		var importIn = document.getElementById( 'cx-import-input' );
		var importResult = document.getElementById( 'cx-import-result' );

		if ( importBtn && importIn ) {
			importBtn.addEventListener( 'click', function () {
				var payload = importIn.value.trim();

				if ( '' === payload ) {
					importIn.focus();
					return;
				}

				if ( ! window.confirm( cfg.i18n.importAsk ) ) {
					return;
				}

				importBtn.disabled = true;

				if ( importResult ) {
					importResult.textContent = '';
					importResult.className = 'cx-engine-result';
				}

				ajaxPost( 'cxai_import_settings', { payload: payload } )
					.then( function ( json ) {
						if ( json.success ) {
							if ( importResult ) {
								importResult.textContent = json.data.message;
								importResult.classList.add( 'is-ok' );
							}
							window.setTimeout( function () {
								window.location.reload();
							}, 900 );
						} else {
							importBtn.disabled = false;

							if ( importResult ) {
								importResult.textContent = errorMessage( json );
								importResult.classList.add( 'is-fail' );
							}
						}
					} )
					.catch( function () {
						importBtn.disabled = false;

						if ( importResult ) {
							importResult.textContent = cfg.i18n.genericError;
							importResult.classList.add( 'is-fail' );
						}
					} );
			} );
		}

		// Reset stats.
		var resetBtn = document.getElementById( 'cx-reset-stats' );
		var resetOut = document.getElementById( 'cx-reset-stats-result' );

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( cfg.i18n.resetStatsAsk ) ) {
					return;
				}

				resetBtn.disabled = true;

				ajaxPost( 'cxai_reset_stats', {} )
					.then( function ( json ) {
						if ( json.success && resetOut ) {
							resetOut.textContent = cfg.i18n.statsReset;
						}
					} )
					.finally( function () {
						resetBtn.disabled = false;
					} );
			} );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Editor meta box
	 * ------------------------------------------------------------------ */

	function initMetabox() {
		var box = document.querySelector( '.cx-box' );

		if ( ! box ) {
			return;
		}

		var promptEl = box.querySelector( '#cx-prompt' );
		var countEl = box.querySelector( '#cx-prompt-count' );
		var modeEl = box.querySelector( '#cx-mode' );
		var toneEl = box.querySelector( '#cx-tone' );
		var lengthEl = box.querySelector( '#cx-length' );
		var engineEl = box.querySelector( '#cx-engine' );
		var contextEl = box.querySelector( '#cx-use-context' );
		var generateBtn = box.querySelector( '#cx-generate' );
		var generateTxt = generateBtn.querySelector( '.cx-btn-text' );
		var errorBox = box.querySelector( '#cx-error' );
		var output = box.querySelector( '#cx-output' );
		var resultEl = box.querySelector( '#cx-result' );
		var outModeEl = box.querySelector( '#cx-output-mode' );
		var outCountEl = box.querySelector( '#cx-output-count' );
		var modal = box.querySelector( '#cx-modal' );
		var modalBody = box.querySelector( '#cx-modal-body' );

		var lastRaw = '';
		var lastHtml = '';
		var lastMode = 'content';

		function showError( message ) {
			errorBox.textContent = message;
			errorBox.classList.remove( 'cx-hidden' );
		}

		function hideError() {
			errorBox.classList.add( 'cx-hidden' );
		}

		// Prompt character counter.
		promptEl.addEventListener( 'input', function () {
			countEl.textContent = String( promptEl.value.length );
		} );

		// Template chips fill the prompt.
		box.querySelectorAll( '.cx-chip' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				promptEl.value = chip.getAttribute( 'data-prompt' ) || '';
				countEl.textContent = String( promptEl.value.length );
				promptEl.focus();
				promptEl.setSelectionRange( promptEl.value.length, promptEl.value.length );
			} );
		} );

		// History drawer.
		var historyToggle = box.querySelector( '#cx-history-toggle' );
		var history = box.querySelector( '#cx-history' );

		if ( historyToggle && history ) {
			historyToggle.addEventListener( 'click', function () {
				history.classList.toggle( 'cx-hidden' );
			} );

			history.addEventListener( 'click', function ( event ) {
				var item = event.target.closest( '.cx-history-item' );

				if ( ! item ) {
					return;
				}

				promptEl.value = item.getAttribute( 'data-prompt' ) || '';
				countEl.textContent = String( promptEl.value.length );

				var mode = item.getAttribute( 'data-mode' ) || 'content';

				if ( modeEl.querySelector( 'option[value="' + mode + '"]' ) ) {
					modeEl.value = mode;
				}

				history.classList.add( 'cx-hidden' );
				promptEl.focus();
			} );
		}

		/**
		 * Read the current editor content (Gutenberg, TinyMCE, or textarea).
		 *
		 * @return {string}
		 */
		function readEditorContent() {
			if ( isClassicEditor() ) {
				if ( window.tinymce && window.tinymce.get( 'content' ) && ! window.tinymce.get( 'content' ).isHidden() ) {
					return window.tinymce.get( 'content' ).getContent();
				}

				var textarea = document.getElementById( 'content' );

				return textarea ? textarea.value : '';
			}

			if ( window.wp && window.wp.data && window.wp.data.select( 'core/editor' ) ) {
				var content = window.wp.data.select( 'core/editor' ).getEditedPostContent();

				if ( 'string' === typeof content ) {
					return content;
				}
			}

			return '';
		}

		// --- Generate -----------------------------------------------------
		function runGenerate() {
			var prompt = promptEl.value.trim();
			var mode = modeEl.value;
			var selected = modeEl.options[ modeEl.selectedIndex ];
			var needsContext = selected && '1' === selected.getAttribute( 'data-context' );
			var sendContext = needsContext || ( contextEl && contextEl.checked );
			var context = sendContext ? readEditorContent() : '';

			hideError();

			if ( '' === prompt && ! needsContext ) {
				showError( cfg.i18n.emptyPrompt );
				promptEl.focus();
				return;
			}

			if ( needsContext && '' === context.trim() ) {
				showError( cfg.i18n.noContext );
				return;
			}

			generateBtn.disabled = true;
			generateBtn.classList.add( 'is-busy' );
			generateTxt.textContent = cfg.i18n.generating;

			ajaxPost( 'cxai_generate', {
				prompt: prompt,
				provider: engineEl.value,
				mode: mode,
				tone: toneEl.value,
				length: lengthEl.value,
				context: context,
				post_id: box.getAttribute( 'data-post-id' ) || '0'
			} )
				.then( function ( json ) {
					if ( json.success && json.data ) {
						lastRaw = json.data.raw || '';
						lastHtml = json.data.html || '';
						lastMode = json.data.mode || 'content';

						outModeEl.textContent = cfg.modeLabels[ lastMode ] || lastMode;
						outCountEl.textContent = ( json.data.words || 0 ) + ' ' + cfg.i18n.words;
						var lengthNote = box.querySelector( '#cx-length-note' );

						if ( lengthNote ) {
							lengthNote.classList.toggle( 'cx-hidden', ! json.data.truncated );
						}

						resultEl.classList.remove( 'is-clamped' );
						resultEl.innerHTML = lastHtml;
						output.classList.remove( 'cx-hidden' );

						// Long responses preview at ~5 lines with a fade;
						// the full text is one click away in the modal.
						if ( resultEl.scrollHeight > 190 ) {
							resultEl.classList.add( 'is-clamped' );
						}

						resultEl.focus();
					} else {
						showError( errorMessage( json ) );
					}
				} )
				.catch( function () {
					showError( cfg.i18n.genericError );
				} )
				.finally( function () {
					generateBtn.disabled = false;
					generateBtn.classList.remove( 'is-busy' );
					generateTxt.textContent = cfg.i18n.generate;
				} );
		}

		generateBtn.addEventListener( 'click', runGenerate );
		box.querySelector( '#cx-regenerate' ).addEventListener( 'click', runGenerate );

		// Ctrl/Cmd+Enter in the prompt triggers generation.
		promptEl.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
				event.preventDefault();
				runGenerate();
			}
		} );

		// --- Expand modal ---------------------------------------------------
		function openExpand() {
			// Re-parent to <body> so ancestors with transforms or clipping
			// (the Block Editor meta-box area) cannot trap the overlay.
			if ( modal.parentNode !== document.body ) {
				document.body.appendChild( modal );
			}

			modalBody.innerHTML = lastHtml;
			modal.classList.remove( 'cx-hidden' );
			document.body.classList.add( 'cx-locked' );
		}

		box.querySelector( '#cx-expand' ).addEventListener( 'click', openExpand );

		var moreBtn = box.querySelector( '#cx-result-more' );

		if ( moreBtn ) {
			moreBtn.addEventListener( 'click', openExpand );
		}

		function closeModal() {
			modal.classList.add( 'cx-hidden' );
			document.body.classList.remove( 'cx-locked' );
		}

		modal.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-cx-close]' ) ) {
				closeModal();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.classList.contains( 'cx-hidden' ) ) {
				closeModal();
			}
		} );

		// --- Use it -----------------------------------------------------------
		box.querySelector( '#cx-use' ).addEventListener( 'click', function () {
			if ( ! lastRaw ) {
				return;
			}

			hideError();

			if ( 'title' === lastMode ) {
				setTitle( lastRaw.trim() );
				toast( cfg.i18n.titleSet );
				return;
			}

			if ( 'excerpt' === lastMode ) {
				setExcerpt( lastRaw.trim() );
				toast( cfg.i18n.excerptSet );
				return;
			}

			var replace = window.confirm( cfg.i18n.replaceAsk );
			insertIntoEditor( lastHtml, lastRaw, replace );
			toast( cfg.i18n.contentSet );
		} );

		// --- Copy -------------------------------------------------------------
		var copyBtn = box.querySelector( '#cx-copy' );

		copyBtn.addEventListener( 'click', function () {
			copyText( lastRaw ).then(
				function () {
					toast( cfg.i18n.copied );
				},
				function () {
					showError( cfg.i18n.copyFailed );
				}
			);
		} );
	}

	/**
	 * True when the page is running the Classic Editor.
	 *
	 * Checked via the DOM rather than script presence, because other
	 * plugins can load Gutenberg's data scripts on classic pages —
	 * dispatching to that phantom store silently does nothing visible.
	 *
	 * @return {boolean}
	 */
	function isClassicEditor() {
		return !! document.getElementById( 'content' ) && ! document.querySelector( '.block-editor-writing-flow, .editor-styles-wrapper' );
	}

	/**
	 * Set the post title in whichever editor is active.
	 *
	 * @param {string} title New title.
	 */
	function setTitle( title ) {
		var input = document.getElementById( 'title' );

		if ( isClassicEditor() && input ) {
			input.value = title;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

			// Classic screens mirror the title into the slug preview.
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			return;
		}

		if ( window.wp && window.wp.data && window.wp.data.dispatch( 'core/editor' ) ) {
			window.wp.data.dispatch( 'core/editor' ).editPost( { title: title } );
			return;
		}

		if ( input ) {
			input.value = title;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	}

	/**
	 * Set the post excerpt in whichever editor is active.
	 *
	 * @param {string} excerpt New excerpt.
	 */
	function setExcerpt( excerpt ) {
		var field = document.getElementById( 'excerpt' );

		if ( isClassicEditor() && field ) {
			field.value = excerpt;
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			return;
		}

		if ( window.wp && window.wp.data && window.wp.data.dispatch( 'core/editor' ) ) {
			window.wp.data.dispatch( 'core/editor' ).editPost( { excerpt: excerpt } );
			return;
		}

		if ( field ) {
			field.value = excerpt;
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	/**
	 * Insert generated content into whichever editor is active.
	 *
	 * @param {string}  html    Server-sanitized HTML.
	 * @param {string}  raw     Raw text fallback for the plain textarea.
	 * @param {boolean} replace Replace instead of appending.
	 */
	function insertIntoEditor( html, raw, replace ) {
		// Classic Editor first — its presence is definitive, while the
		// block-editor stores can exist (loaded by other plugins) without
		// any visible block editor on the page.
		if ( isClassicEditor() ) {
			// Visual (TinyMCE) mode.
			if ( window.tinymce && window.tinymce.get( 'content' ) && ! window.tinymce.get( 'content' ).isHidden() ) {
				var classicEditor = window.tinymce.get( 'content' );

				if ( replace ) {
					classicEditor.setContent( html );
				} else {
					classicEditor.setContent( classicEditor.getContent() + html );
				}
				classicEditor.undoManager.add();
				classicEditor.save(); // Sync back to the textarea.
				return;
			}

			// Text mode.
			var classicTextarea = document.getElementById( 'content' );

			if ( classicTextarea ) {
				classicTextarea.value = replace ? raw : ( classicTextarea.value ? classicTextarea.value + '\n\n' : '' ) + raw;
				classicTextarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			return;
		}

		// Block Editor.
		if ( window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' ) && window.wp.blocks ) {
			var blocks = window.wp.blocks.rawHandler( { HTML: html } );
			var dispatch = window.wp.data.dispatch( 'core/block-editor' );

			if ( replace ) {
				var existing = window.wp.data
					.select( 'core/block-editor' )
					.getBlocks()
					.map( function ( block ) {
						return block.clientId;
					} );

				if ( existing.length ) {
					dispatch.replaceBlocks( existing, blocks );
				} else {
					dispatch.insertBlocks( blocks );
				}
			} else {
				dispatch.insertBlocks( blocks );
			}
			return;
		}

		// Classic Editor — TinyMCE visual mode.
		if ( window.tinymce && window.tinymce.get( 'content' ) && ! window.tinymce.get( 'content' ).isHidden() ) {
			var editor = window.tinymce.get( 'content' );

			if ( replace ) {
				editor.setContent( html );
			} else {
				editor.setContent( editor.getContent() + html );
			}
			editor.undoManager.add();
			return;
		}

		// Classic Editor — Text mode.
		var textarea = document.getElementById( 'content' );

		if ( textarea ) {
			textarea.value = replace ? raw : ( textarea.value ? textarea.value + '\n\n' : '' ) + raw;
			textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * AI Studio
	 * ------------------------------------------------------------------ */

	function initStudio() {
		var chat = document.getElementById( 'cx-chat' );

		if ( ! chat ) {
			return;
		}

		var windowEl = document.getElementById( 'cx-chat-window' );
		var emptyEl = document.getElementById( 'cx-chat-empty' );
		var inputEl = document.getElementById( 'cx-chat-input' );
		var sendBtn = document.getElementById( 'cx-chat-send' );
		var engineEl = document.getElementById( 'cx-chat-engine' );
		var listEl = document.getElementById( 'cx-chatlist' );
		var newBtn = document.getElementById( 'cx-chat-new' );
		var delAllBtn = document.getElementById( 'cx-chat-delall' );
		var studioEl = chat.querySelector( '.cx-studio' );

		var activeChat = studioEl.getAttribute( 'data-active' ) || '';
		var busy = false;

		function scrollDown() {
			windowEl.scrollTop = windowEl.scrollHeight;
		}

		scrollDown();

		function clearMessages() {
			windowEl.querySelectorAll( '.cx-msg' ).forEach( function ( el ) {
				el.remove();
			} );
		}

		function setActiveItem( id ) {
			activeChat = id;
			listEl.querySelectorAll( '.cx-chatlist-item' ).forEach( function ( item ) {
				item.classList.toggle( 'is-active', item.getAttribute( 'data-chat' ) === id );
			} );
		}

		/**
		 * Append a chat message.
		 *
		 * @param {string}  role     'ai' or 'me'.
		 * @param {string}  html     Server-sanitized HTML (AI only).
		 * @param {string}  raw      Raw text.
		 * @param {boolean} isTyping Render a typing indicator instead.
		 * @return {HTMLElement}
		 */
		function appendMessage( role, html, raw, isTyping ) {
			emptyEl.classList.add( 'cx-hidden' );

			var msg = document.createElement( 'article' );
			msg.className = 'cx-msg cx-msg-' + role;

			var avatar = document.createElement( 'div' );
			avatar.className = 'cx-msg-avatar';
			avatar.setAttribute( 'aria-hidden', 'true' );

			// Reuse the inline logo already on the page for AI messages.
			if ( 'ai' === role ) {
				var mark = emptyEl.querySelector( 'svg' );

				if ( mark ) {
					var clone = mark.cloneNode( true );
					clone.setAttribute( 'width', '22' );
					clone.setAttribute( 'height', '22' );
					avatar.appendChild( clone );
				}
			} else {
				avatar.innerHTML = '<span class="dashicons dashicons-admin-users"></span>';
			}

			var body = document.createElement( 'div' );
			body.className = 'cx-msg-body';

			var bubble = document.createElement( 'div' );
			bubble.className = 'cx-msg-bubble';

			if ( isTyping ) {
				bubble.innerHTML = '<span class="cx-typing"><span></span><span></span><span></span></span>';
			} else if ( 'ai' === role ) {
				bubble.innerHTML = html; // Sanitized server-side with wp_kses_post().
			} else {
				bubble.textContent = raw; // User input is never HTML.
			}

			body.appendChild( bubble );

			if ( ! isTyping ) {
				var copy = document.createElement( 'button' );
				copy.type = 'button';
				copy.className = 'cx-msg-copy';
				copy.setAttribute( 'data-raw', raw );
				copy.innerHTML = '<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>Copy';
				body.appendChild( copy );
			}

			msg.appendChild( avatar );
			msg.appendChild( body );
			windowEl.appendChild( msg );
			scrollDown();

			return msg;
		}

		/**
		 * Add a conversation row to the top of the sidebar.
		 *
		 * @param {string} id    Chat id.
		 * @param {string} title Chat title.
		 */
		function addListItem( id, title ) {
			var item = document.createElement( 'div' );
			item.className = 'cx-chatlist-item';
			item.setAttribute( 'data-chat', id );

			var open = document.createElement( 'button' );
			open.type = 'button';
			open.className = 'cx-chatlist-open';
			open.title = title;

			var titleEl = document.createElement( 'span' );
			titleEl.className = 'cx-chatlist-title';
			titleEl.textContent = title;

			var timeEl = document.createElement( 'span' );
			timeEl.className = 'cx-chatlist-time';
			timeEl.textContent = cfg.i18n.justNow;

			open.appendChild( titleEl );
			open.appendChild( timeEl );

			var del = document.createElement( 'button' );
			del.type = 'button';
			del.className = 'cx-chatlist-del';
			del.setAttribute( 'aria-label', cfg.i18n.deleteChat );
			del.innerHTML = '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>';

			item.appendChild( open );
			item.appendChild( del );

			var hint = document.getElementById( 'cx-chatlist-hint' );

			if ( hint ) {
				hint.remove();
			}

			listEl.insertBefore( item, listEl.firstChild );

			delAllBtn.disabled = false;
		}

		/**
		 * Flag a reply that stopped because the output budget ran out.
		 *
		 * @param {HTMLElement} msg The message element to annotate.
		 */
		function appendLengthNote( msg ) {
			if ( ! msg ) {
				return;
			}

			var body = msg.querySelector( '.cx-msg-body' );

			if ( ! body || body.querySelector( '.cx-length-note' ) ) {
				return;
			}

			var note = document.createElement( 'p' );
			note.className = 'cx-length-note';
			note.innerHTML =
				'<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>' +
				'<span>' + escapeHtml( cfg.i18n.truncated ) + ' ' +
				'<a href="' + escapeHtml( cfg.i18n.settingsUrl || '' ) + '" target="_blank" rel="noopener">' +
				escapeHtml( cfg.i18n.truncatedLink ) + '</a></span>';

			var more = document.createElement( 'button' );
			more.type = 'button';
			more.className = 'cx-length-continue';
			more.innerHTML = '<span class="dashicons dashicons-editor-justify" aria-hidden="true"></span>' + escapeHtml( cfg.i18n.continueReply );
			more.addEventListener( 'click', function () {
				if ( busy ) {
					return;
				}

				more.remove();
				send( cfg.i18n.continuePrompt );
			} );

			body.appendChild( note );
			body.appendChild( more );
			scrollDown();
		}

		/**
		 * Render a failed request as an error card with a retry action.
		 *
		 * @param {string} message Human-readable error text.
		 * @param {string} prompt  The message that failed, for retrying.
		 */
		function appendError( message, prompt ) {
			emptyEl.classList.add( 'cx-hidden' );

			var msg = document.createElement( 'article' );
			msg.className = 'cx-msg cx-msg-ai cx-msg-error';

			var avatar = document.createElement( 'div' );
			avatar.className = 'cx-msg-avatar cx-msg-avatar-error';
			avatar.setAttribute( 'aria-hidden', 'true' );
			avatar.innerHTML = '<span class="dashicons dashicons-warning"></span>';

			var body = document.createElement( 'div' );
			body.className = 'cx-msg-body';

			var bubble = document.createElement( 'div' );
			bubble.className = 'cx-msg-bubble cx-bubble-error';
			bubble.setAttribute( 'role', 'alert' );

			var head = document.createElement( 'p' );
			head.className = 'cx-error-head';
			head.innerHTML = '<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>' + escapeHtml( cfg.i18n.requestFailed );

			var detail = document.createElement( 'p' );
			detail.className = 'cx-error-detail';
			detail.textContent = message;

			bubble.appendChild( head );
			bubble.appendChild( detail );

			msg.setAttribute( 'data-prompt', prompt );

			var retry = document.createElement( 'button' );
			retry.type = 'button';
			retry.className = 'cx-error-retry';
			retry.innerHTML = '<span class="dashicons dashicons-update" aria-hidden="true"></span>' + escapeHtml( cfg.i18n.retry );

			body.appendChild( bubble );
			body.appendChild( retry );
			msg.appendChild( avatar );
			msg.appendChild( body );
			windowEl.appendChild( msg );
			scrollDown();
		}

		function send( retryText ) {
			var text = ( 'string' === typeof retryText && retryText ) ? retryText : inputEl.value.trim();

			if ( '' === text || busy ) {
				return;
			}

			var isRetry = ( 'string' === typeof retryText && retryText );

			busy = true;
			sendBtn.disabled = true;

			// A new command supersedes older failures: keep the record,
			// drop the retry action so only the latest one is actionable.
			if ( ! isRetry ) {
				windowEl.querySelectorAll( '.cx-error-retry' ).forEach( function ( el ) {
					el.remove();
				} );
			}

			if ( 'string' !== typeof retryText || ! retryText ) {
				inputEl.value = '';
				inputEl.style.height = '';
				appendMessage( 'me', '', text, false );
			}
			var typing = appendMessage( 'ai', '', '', true );

			ajaxPost( 'cxai_chat', {
				message: text,
				provider: engineEl.value,
				chat_id: activeChat,
				retry: isRetry ? '1' : ''
			} )
				.then( function ( json ) {
					typing.remove();

					if ( json.success && json.data ) {
						var bubbleEl = appendMessage( 'ai', json.data.html || '', json.data.raw || '', false );

						if ( json.data.truncated ) {
							appendLengthNote( bubbleEl );
						}

						if ( json.data.is_new && json.data.chat_id ) {
							addListItem( json.data.chat_id, json.data.title || text );
							setActiveItem( json.data.chat_id );
						}
					} else {
						appendError( errorMessage( json ), text );
					}
				} )
				.catch( function () {
					typing.remove();
					appendError( cfg.i18n.genericError, text );
				} )
				.finally( function () {
					busy = false;
					sendBtn.disabled = false;
					inputEl.focus();
				} );
		}

		sendBtn.addEventListener( 'click', function () {
			send();
		} );

		inputEl.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ! event.shiftKey ) {
				event.preventDefault();
				send();
			}
		} );

		// Auto-grow composer.
		inputEl.addEventListener( 'input', function () {
			inputEl.style.height = 'auto';
			inputEl.style.height = Math.min( inputEl.scrollHeight, 190 ) + 'px';
		} );

		// Conversation starters fill the composer.
		document.querySelectorAll( '.cx-starter' ).forEach( function ( starter ) {
			starter.addEventListener( 'click', function () {
				inputEl.value = starter.textContent.trim();
				inputEl.focus();
			} );
		} );

		// Retry — delegated so cards rendered on page load work too.
		windowEl.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.cx-error-retry' );

			if ( ! button || busy ) {
				return;
			}

			var card = button.closest( '.cx-msg-error' );
			var prompt = card ? card.getAttribute( 'data-prompt' ) || '' : '';

			if ( '' === prompt ) {
				return;
			}

			card.remove();
			send( prompt );
		} );

		// Copy buttons — delegated, covers server-rendered and new messages.
		windowEl.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.cx-msg-copy' );

			if ( ! button ) {
				return;
			}

			copyText( button.getAttribute( 'data-raw' ) || '' ).then( function () {
				button.classList.add( 'is-ok' );
				window.setTimeout( function () {
					button.classList.remove( 'is-ok' );
				}, 1400 );
			} );
		} );

		// New chat — clear the window, next message creates the record.
		newBtn.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}

			ajaxPost( 'cxai_open_chat', { chat_id: '' } );
			clearMessages();
			emptyEl.classList.remove( 'cx-hidden' );
			setActiveItem( '' );
			inputEl.focus();
		} );

		// Sidebar interactions: open or delete a conversation.
		listEl.addEventListener( 'click', function ( event ) {
			var item = event.target.closest( '.cx-chatlist-item' );

			if ( ! item || busy ) {
				return;
			}

			var id = item.getAttribute( 'data-chat' );

			// Delete one.
			if ( event.target.closest( '.cx-chatlist-del' ) ) {
				if ( ! window.confirm( cfg.i18n.deleteChatAsk ) ) {
					return;
				}

				ajaxPost( 'cxai_delete_chat', { chat_id: id } ).then( function ( json ) {
					if ( ! json.success ) {
						return;
					}

					item.remove();

					if ( id === activeChat ) {
						clearMessages();
						emptyEl.classList.remove( 'cx-hidden' );
						activeChat = '';
					}

					delAllBtn.disabled = ! listEl.querySelector( '.cx-chatlist-item' );
				} );
				return;
			}

			// Open.
			if ( id === activeChat ) {
				return;
			}

			ajaxPost( 'cxai_open_chat', { chat_id: id } ).then( function ( json ) {
				if ( ! json.success || ! json.data ) {
					return;
				}

				clearMessages();
				setActiveItem( id );

				var messages = json.data.messages || [];

				if ( ! messages.length ) {
					emptyEl.classList.remove( 'cx-hidden' );
					return;
				}

				emptyEl.classList.add( 'cx-hidden' );
				messages.forEach( function ( message, index ) {
					if ( 'error' === message.role ) {
						appendError( message.raw, message.prompt || '' );

						// Only the newest failure keeps its retry action.
						if ( index !== messages.length - 1 ) {
							var last = windowEl.querySelector( '.cx-msg-error:last-child .cx-error-retry' );

							if ( last ) {
								last.remove();
							}
						}
						return;
					}

					appendMessage( message.role, message.html, message.raw, false );
				} );
			} );
		} );

		// Delete all.
		if ( delAllBtn ) {
			delAllBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( cfg.i18n.clearAsk ) ) {
					return;
				}

				ajaxPost( 'cxai_clear_chat', {} ).then( function ( json ) {
					if ( ! json.success ) {
						return;
					}

					listEl.querySelectorAll( '.cx-chatlist-item' ).forEach( function ( el ) {
						el.remove();
					} );
					clearMessages();
					emptyEl.classList.remove( 'cx-hidden' );
					activeChat = '';
					delAllBtn.disabled = true;
				} );
			} );
		}
	}

	/* ------------------------------------------------------------------ */

	function boot() {
		initTabs();
		initEngines();
		initSettingsMisc();
		initMetabox();
		initStudio();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
