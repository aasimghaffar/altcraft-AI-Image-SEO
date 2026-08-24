/**
 * AltCraft AI – admin scripts.
 *
 * Handles the settings screen, the Media SEO Table, the Media Library column,
 * the media modal button and the bulk scanner. No inline scripts are used.
 */
( function ( $ ) {
	'use strict';

	var data = window.altcraftData || {};
	var i18n = data.i18n || {};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	// Minimal sprintf: supports %s, %d, %1$s, %2$d …
	function format( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( str ).replace( /%(\d+\$)?[sd]/g, function ( match, position ) {
			var value;
			if ( position ) {
				value = args[ parseInt( position, 10 ) - 1 ];
			} else {
				value = args[ index++ ];
			}
			return typeof value === 'undefined' ? '' : value;
		} );
	}

	function ajax( action, payload ) {
		return $.ajax( {
			url: data.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: $.extend( { action: action, nonce: data.nonce }, payload || {} )
		} );
	}

	function errorMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return t( 'networkError' );
	}

	function setStatus( $el, message, type ) {
		if ( ! $el || ! $el.length ) {
			return;
		}
		$el.removeClass( 'is-error is-success' ).text( message || '' );
		if ( type ) {
			$el.addClass( 'is-' + type );
		}
	}

	function badge( status ) {
		if ( status === 'optimized' ) {
			return $( '<span class="altcraft-badge altcraft-badge-optimized"></span>' ).text( t( 'optimized' ) );
		}
		return $( '<span class="altcraft-badge altcraft-badge-missing"></span>' ).text( t( 'missing' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                      */
	/* ------------------------------------------------------------------ */

	function initSettings() {
		var $tabs = $( '.altcraft-tab' );
		if ( ! $tabs.length ) {
			return;
		}

		function activate( id ) {
			if ( ! $( '#' + id ).length ) {
				return;
			}
			$tabs.removeClass( 'active' ).filter( '[data-tab="' + id + '"]' ).addClass( 'active' );
			$( '.altcraft-tab-panel' ).removeClass( 'active' );
			$( '#' + id ).addClass( 'active' );
			try {
				window.sessionStorage.setItem( 'altcraftActiveTab', id );
			} catch ( e ) {
				// Storage may be unavailable; ignore.
			}
		}

		$tabs.on( 'click', function ( e ) {
			e.preventDefault();
			activate( $( this ).data( 'tab' ) );
		} );

		var initial = window.location.hash ? window.location.hash.substring( 1 ) : '';
		if ( ! initial ) {
			try {
				initial = window.sessionStorage.getItem( 'altcraftActiveTab' ) || '';
			} catch ( e ) {
				initial = '';
			}
		}
		if ( initial ) {
			activate( initial );
		}

		// Provider switch.
		$( '.altcraft-provider-select' ).on( 'change', function () {
			var provider = $( this ).val();
			$( '.altcraft-provider-block' ).each( function () {
				var $block = $( this );
				$block.prop( 'hidden', $block.data( 'provider' ) !== provider );
			} );
		} );

		// Custom model input.
		$( '.altcraft-model-select' ).on( 'change', function () {
			var $custom = $( this ).siblings( '.altcraft-model-custom' );
			$custom.prop( 'hidden', $( this ).val() !== 'custom' );
			if ( $( this ).val() === 'custom' ) {
				$custom.trigger( 'focus' );
			}
		} );

		// Show / hide API key.
		$( '.altcraft-toggle-key' ).on( 'click', function () {
			var $input = $( this ).siblings( '.altcraft-key-input' );
			var show = $input.attr( 'type' ) === 'password';
			$input.attr( 'type', show ? 'text' : 'password' );
			$( this ).find( '.dashicons' ).toggleClass( 'dashicons-visibility', ! show ).toggleClass( 'dashicons-hidden', show );
		} );

		// Test connection with the values currently in the form (unsaved is fine).
		$( '#altcraft-test-connection' ).on( 'click', function () {
			var $btn = $( this );
			var $status = $( '#altcraft-test-status' );
			var provider = $( '.altcraft-provider-select' ).val();
			var $block = $( '.altcraft-provider-block[data-provider="' + provider + '"]' );
			var model = $block.find( '.altcraft-model-select' ).val();

			if ( model === 'custom' ) {
				model = $block.find( '.altcraft-model-custom' ).val();
			}

			$btn.prop( 'disabled', true );
			setStatus( $status, t( 'testing' ) );

			ajax( 'altcraft_test_connection', {
				provider: provider,
				api_key: $block.find( '.altcraft-key-input' ).val(),
				model: model
			} ).done( function ( response ) {
				if ( response && response.success ) {
					setStatus( $status, response.data.message || t( 'testOk' ), 'success' );
				} else {
					setStatus( $status, errorMessage( response ), 'error' );
				}
			} ).fail( function () {
				setStatus( $status, t( 'networkError' ), 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Media SEO Table                                                      */
	/* ------------------------------------------------------------------ */

	function initTable() {
		// Instant narrowing of the rows already on this page (server-side search handles the rest).
		$( '#altcraft-quick-filter' ).on( 'input', function () {
			var query = $( this ).val().toLowerCase();
			$( '.altcraft-row' ).each( function () {
				var $row = $( this );
				var haystack = ( $row.text() + ' ' + $row.find( 'input, textarea' ).map( function () {
					return $( this ).val();
				} ).get().join( ' ' ) ).toLowerCase();
				$row.toggle( query === '' || haystack.indexOf( query ) !== -1 );
			} );
		} );

		// Generate for one row.
		$( document ).on( 'click', '.altcraft-row-ai-btn', function () {
			var $btn = $( this );
			var $row = $btn.closest( '.altcraft-row' );
			var $status = $row.find( '.altcraft-inline-status' );
			var label = $btn.text();

			$btn.prop( 'disabled', true ).text( t( 'generating' ) );
			$row.addClass( 'altcraft-row-busy' );
			setStatus( $status, '' );

			ajax( 'altcraft_generate_single_alt', { attachment_id: $btn.data( 'id' ) } ).done( function ( response ) {
				if ( response && response.success ) {
					var result = response.data;
					$row.find( '.altcraft-alt-input' ).val( result.alt_text );
					if ( data.settings && data.settings.syncTitle && result.title ) {
						$row.find( '.altcraft-title-input' ).val( result.title );
					}
					if ( data.settings && data.settings.syncCaption && result.caption ) {
						$row.find( '.altcraft-caption-input' ).val( result.caption );
					}
					$row.find( '.altcraft-col-status' ).empty().append( badge( 'optimized' ) );
					$row.attr( 'data-status', 'optimized' );
					$row.find( '.altcraft-row-error' ).remove();
					setStatus( $status, t( 'done' ), 'success' );
				} else {
					setStatus( $status, errorMessage( response ), 'error' );
				}
			} ).fail( function () {
				setStatus( $status, t( 'networkError' ), 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( label );
				$row.removeClass( 'altcraft-row-busy' );
			} );
		} );

		// Save inline edits for one row.
		$( document ).on( 'click', '.altcraft-row-save-btn', function () {
			var $btn = $( this );
			var $row = $btn.closest( '.altcraft-row' );
			var $status = $row.find( '.altcraft-inline-status' );
			var label = $btn.text();

			$btn.prop( 'disabled', true ).text( t( 'saving' ) );
			setStatus( $status, '' );

			ajax( 'altcraft_save_inline_seo', {
				attachment_id: $btn.data( 'id' ),
				alt_text: $row.find( '.altcraft-alt-input' ).val(),
				title: $row.find( '.altcraft-title-input' ).val(),
				caption: $row.find( '.altcraft-caption-input' ).val()
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$row.find( '.altcraft-col-status' ).empty().append( badge( response.data.status ) );
					$row.attr( 'data-status', response.data.status );
					setStatus( $status, t( 'saved' ), 'success' );
				} else {
					setStatus( $status, errorMessage( response ), 'error' );
				}
			} ).fail( function () {
				setStatus( $status, t( 'networkError' ), 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( label );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Media Library list column                                            */
	/* ------------------------------------------------------------------ */

	function initMediaColumn() {
		$( document ).on( 'click', '.altcraft-quick-gen-btn', function () {
			var $btn = $( this );
			var $wrap = $btn.closest( '.altcraft-column' );
			var $status = $wrap.find( '.altcraft-inline-status' );

			$btn.prop( 'disabled', true ).text( t( 'generating' ) );
			setStatus( $status, '' );

			ajax( 'altcraft_generate_single_alt', { attachment_id: $btn.data( 'id' ) } ).done( function ( response ) {
				if ( response && response.success ) {
					$wrap.find( '.altcraft-badge, .altcraft-column-alt' ).remove();
					$wrap.prepend( $( '<span class="altcraft-column-alt"></span>' ).text( response.data.alt_text ) );
					$btn.prop( 'disabled', false ).text( t( 'regenerate' ) );
				} else {
					$btn.prop( 'disabled', false ).text( t( 'retry' ) );
					setStatus( $status, errorMessage( response ), 'error' );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( t( 'retry' ) );
				setStatus( $status, t( 'networkError' ), 'error' );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Media modal + attachment edit screen                                 */
	/* ------------------------------------------------------------------ */

	function fillField( $scope, selectors, value ) {
		if ( ! value ) {
			return;
		}
		var $field = $();
		$.each( selectors, function ( _, selector ) {
			if ( ! $field.length ) {
				$field = $scope.find( selector ).first();
			}
		} );
		if ( $field.length ) {
			$field.val( value ).trigger( 'change' );
		}
	}

	function initModalButton() {
		$( document ).on( 'click', '.altcraft-modal-gen-btn', function () {
			var $btn = $( this );
			var $status = $btn.siblings( '.altcraft-inline-status' );
			var label = $btn.text();
			var $scope = $btn.closest( '.attachment-details' );

			if ( ! $scope.length ) {
				$scope = $( 'body' ); // Attachment edit screen (post.php).
			}

			$btn.prop( 'disabled', true ).text( t( 'generating' ) );
			setStatus( $status, '' );

			ajax( 'altcraft_generate_single_alt', { attachment_id: $btn.data( 'id' ) } ).done( function ( response ) {
				if ( response && response.success ) {
					var result = response.data;
					fillField( $scope, [ '[data-setting="alt"] textarea', '[data-setting="alt"] input', '#attachment_alt' ], result.alt_text );
					if ( data.settings && data.settings.syncTitle ) {
						fillField( $scope, [ '[data-setting="title"] input', '#title' ], result.title );
					}
					if ( data.settings && data.settings.syncCaption ) {
						fillField( $scope, [ '[data-setting="caption"] textarea', '#attachment_caption' ], result.caption );
					}
					setStatus( $status, t( 'done' ), 'success' );
				} else {
					setStatus( $status, errorMessage( response ), 'error' );
				}
			} ).fail( function () {
				setStatus( $status, t( 'networkError' ), 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( label );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Bulk scanner                                                         */
	/* ------------------------------------------------------------------ */

	function initScanner() {
		var $start = $( '#altcraft-bulk-start-btn' );
		if ( ! $start.length ) {
			return;
		}

		var $stop = $( '#altcraft-bulk-stop-btn' );
		var $box = $( '#altcraft-progress-box' );
		var $logs = $( '#altcraft-progress-logs' );
		var $fill = $( '#altcraft-progress-bar-fill' );
		var $bar = $( '#altcraft-progress-bar' );
		var $percent = $( '#altcraft-progress-percent' );
		var $title = $( '#altcraft-progress-title' );
		var $missingStat = $( '#altcraft-stat-missing' );

		var state = null;

		function log( message, type ) {
			var $p = $( '<p></p>' ).text( message );
			if ( type ) {
				$p.addClass( 'is-' + type );
			}
			$logs.prepend( $p );
			if ( $logs.children().length > 300 ) {
				$logs.children().last().remove();
			}
		}

		function updateCounters() {
			var done = state.ok + state.skip + state.fail;
			var pct = state.total > 0 ? Math.min( 100, Math.round( ( done / state.total ) * 100 ) ) : 100;
			$( '#altcraft-count-done' ).text( done );
			$( '#altcraft-count-ok' ).text( state.ok );
			$( '#altcraft-count-skip' ).text( state.skip );
			$( '#altcraft-count-fail' ).text( state.fail );
			$percent.text( pct + '%' );
			$fill.css( 'width', pct + '%' );
			$bar.attr( 'aria-valuenow', pct );
			if ( $missingStat.length && state.mode === 'missing' ) {
				$missingStat.text( Math.max( 0, state.total - done ) );
			}
		}

		function finish( message ) {
			state.running = false;
			$title.text( t( 'done' ) );
			log( message || format( t( 'finished' ), state.ok + state.skip + state.fail, state.ok, state.fail ), 'success' );
			$start.prop( 'disabled', false ).text( t( 'start' ) );
			$stop.prop( 'hidden', true );
			$percent.text( '100%' );
			$fill.css( 'width', '100%' );
			$bar.attr( 'aria-valuenow', 100 );
		}

		function fetchQueue() {
			if ( ! state.running ) {
				return;
			}
			ajax( 'altcraft_fetch_unoptimized_ids', {
				mode: state.mode,
				exclude: state.failed.join( ',' ),
				after_id: state.lastId
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					log( errorMessage( response ), 'error' );
					finish( t( 'stopped' ) );
					return;
				}
				var ids = response.data.ids || [];
				if ( ! state.total ) {
					state.total = response.data.total || ids.length;
					if ( ! state.total ) {
						log( t( 'nothingToDo' ), 'success' );
						finish( t( 'nothingToDo' ) );
						return;
					}
					log( format( t( 'found' ), state.total ), 'muted' );
				}
				if ( ! ids.length ) {
					finish();
					return;
				}
				state.queue = ids;
				processNext();
			} ).fail( function () {
				log( t( 'networkError' ), 'error' );
				finish( t( 'stopped' ) );
			} );
		}

		function processNext() {
			if ( ! state.running ) {
				finish( t( 'stopped' ) );
				return;
			}
			if ( ! state.queue.length ) {
				fetchQueue();
				return;
			}

			var id = state.queue.shift();
			state.lastId = id;

			ajax( 'altcraft_process_batch_item', { attachment_id: id, mode: state.mode } ).done( function ( response ) {
				if ( response && response.success ) {
					if ( response.data.skipped ) {
						state.skip++;
						log( '[#' + id + '] ' + ( response.data.reason ? response.data.reason : t( 'skipped' ) ), 'muted' );
					} else {
						state.ok++;
						log( '[#' + id + '] ' + response.data.alt_text, 'success' );
					}
					state.retries = 0;
					updateCounters();
					processNext();
					return;
				}

				var code = response && response.data ? response.data.code : '';
				var message = errorMessage( response );

				if ( code === 'timeout' && state.retries < 1 ) {
					state.retries++;
					state.queue.unshift( id );
					log( '[#' + id + '] ' + message + ' ' + t( 'retrying' ), 'muted' );
					processNext();
					return;
				}

				if ( code === 'rate_limited' && state.retries < 3 ) {
					state.retries++;
					var wait = ( response.data.retry_after > 0 ? response.data.retry_after : 15 * state.retries );
					state.queue.unshift( id );
					log( format( t( 'rateLimited' ), wait ), 'muted' );
					state.timer = window.setTimeout( processNext, wait * 1000 );
					return;
				}

				state.fail++;
				state.failed.push( id );
				log( '[#' + id + '] ' + message, 'error' );
				updateCounters();

				if ( code === 'auth' || code === 'model_not_found' || code === 'no_api_key' ) {
					log( t( 'fatalStop' ), 'error' );
					finish( t( 'stopped' ) );
					return;
				}

				processNext();
			} ).fail( function () {
				state.fail++;
				state.failed.push( id );
				log( '[#' + id + '] ' + t( 'networkError' ), 'error' );
				updateCounters();
				processNext();
			} );
		}

		$start.on( 'click', function () {
			var mode = $( 'input[name="altcraft_scan_mode"]:checked' ).val() || 'missing';

			if ( mode === 'all' && ! window.confirm( t( 'confirmAll' ) ) ) {
				return;
			}

			state = {
				running: true,
				mode: mode,
				queue: [],
				failed: [],
				total: 0,
				ok: 0,
				skip: 0,
				fail: 0,
				retries: 0,
				lastId: 0,
				timer: null
			};

			$start.prop( 'disabled', true );
			$stop.prop( 'hidden', false ).prop( 'disabled', false );
			$box.prop( 'hidden', false );
			$logs.empty();
			$title.text( t( 'scanning' ) );
			$percent.text( '0%' );
			$fill.css( 'width', '0%' );
			$bar.attr( 'aria-valuenow', 0 );
			updateCounters();
			log( t( 'scanning' ), 'muted' );

			fetchQueue();
		} );

		$stop.on( 'click', function () {
			if ( state ) {
				state.running = false;
				if ( state.timer ) {
					window.clearTimeout( state.timer );
					state.timer = null;
					finish( t( 'stopped' ) );
				}
			}
			$stop.prop( 'disabled', true );
		} );

		$( window ).on( 'beforeunload', function () {
			if ( state && state.running ) {
				return t( 'stop' );
			}
		} );
	}

	/* ------------------------------------------------------------------ */

	$( function () {
		initSettings();
		initTable();
		initMediaColumn();
		initModalButton();
		initScanner();
	} );
}( jQuery ) );
