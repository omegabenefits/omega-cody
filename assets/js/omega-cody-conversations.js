( function( window, document ) {
	'use strict';

	var config = window.omegaCodyConversationsConfig || {};
	var text   = config.text || {};

	function getText( key, fallback ) {
		if ( Object.prototype.hasOwnProperty.call( text, key ) && 'string' === typeof text[ key ] && '' !== text[ key ] ) {
			return text[ key ];
		}

		return fallback;
	}

	var storageKey    = 'omega_cody_conversations_scroll_top';
	var syncForm      = document.getElementById( 'omega-cody-sync-form' );
	var syncButton    = document.getElementById( 'omega_cody_sync_submit' );
	var statusWrap    = document.getElementById( 'omega-cody-sync-progress' );
	var statusText    = document.getElementById( 'omega-cody-sync-live-status-text' );
	var progressBar   = document.getElementById( 'omega-cody-sync-progressbar' );
	var progressFill  = document.getElementById( 'omega-cody-sync-progressbar-fill' );
	var pageTitle     = document.getElementById( 'omega-cody-page-title' );
	var lastSyncLine  = document.getElementById( 'omega-cody-last-sync-line' );
	var container     = document.getElementById( 'omega-cody-conversations-scroll' );
	var syncAjaxNonce = 'string' === typeof config.syncAjaxNonce ? config.syncAjaxNonce : '';
	var ajaxUrl       = 'string' === typeof config.ajaxUrl ? config.ajaxUrl : ( window.ajaxurl || '' );
	var isConfigured  = Boolean( config.isConfigured );
	var pollTimer     = null;
	var isSyncing     = false;

	function setStatus( value ) {
		if ( ! statusWrap || ! statusText ) {
			return;
		}

		statusWrap.style.display = 'block';
		statusText.textContent   = value;
	}

	function setTitleVisible( isVisible ) {
		if ( ! pageTitle ) {
			return;
		}

		pageTitle.style.display = isVisible ? '' : 'none';
	}

	function setLastSyncVisible( isVisible ) {
		if ( ! lastSyncLine ) {
			return;
		}

		lastSyncLine.style.display = isVisible ? '' : 'none';
	}

	function setConversationListEnabled( enabled ) {
		if ( ! container ) {
			return;
		}

		container.style.pointerEvents = enabled ? '' : 'none';
		container.style.opacity       = enabled ? '' : '0.65';
	}

	function setProgress( percent ) {
		var safePercent = Number( percent );

		if ( ! progressBar || ! progressFill || Number.isNaN( safePercent ) ) {
			return;
		}

		if ( safePercent < 0 ) {
			safePercent = 0;
		}
		if ( safePercent > 100 ) {
			safePercent = 100;
		}

		progressFill.style.width = String( safePercent ) + '%';
		progressBar.setAttribute( 'aria-valuenow', String( Math.round( safePercent ) ) );
	}

	function buildProgressPercent( state ) {
		var total;
		var completed;

		if ( ! state || ! state.results ) {
			return 0;
		}

		total = Number( state.total_conversations_expected || 0 );
		if ( ! total || total <= 0 ) {
			return 0;
		}

		if ( 'conversations' === state.phase ) {
			completed = Number( state.results.conversations_processed || 0 );
		} else {
			completed = Number( state.results.conversations_skipped || 0 ) +
				Number( state.results.conversations_message_synced || 0 );
		}

		if ( completed < 0 ) {
			completed = 0;
		}

		return Math.round( ( completed / total ) * 100 );
	}

	function setSyncButtonEnabled( enabled, label ) {
		if ( ! syncButton ) {
			return;
		}

		syncButton.disabled = ! enabled;
		if ( label ) {
			syncButton.value = label;
		}
	}

	function buildProgressText( state ) {
		var pieces = [];

		if ( ! state || ! state.results ) {
			return getText( 'syncInProgress', 'Sync in progress...' );
		}

		if ( state.progress_message ) {
			pieces.push( state.progress_message );
		}

		if ( 'messages' === state.phase && 'running' === state.status ) {
			pieces.push(
				getText( 'totalMessagesAddedPrefix', 'Total Messages added' ) + ' ' + String( state.results.messages_added || 0 ) + '.'
			);
			return pieces.join( ' ' );
		}

		pieces.push(
			getText( 'processedPrefix', 'Processed' ) + ' ' + String( state.results.conversations_processed || 0 ) + ' ' + getText( 'conversationsWord', 'conversations' ) +
			', ' + getText( 'addedPrefix', 'added' ) + ' ' + String( state.results.conversations_added || 0 ) +
			', ' + getText( 'skippedPrefix', 'skipped' ) + ' ' + String( state.results.conversations_skipped || 0 ) +
			', ' + getText( 'messagesAddedPrefix', 'messages added' ) + ' ' + String( state.results.messages_added || 0 ) + '.'
		);

		if ( 'conversations' === state.phase && state.total_conversations_expected && Number( state.total_conversations_expected ) > 0 ) {
			pieces.push(
				getText( 'totalConversationsExpected', 'Total conversations expected:' ) + ' ' + String( state.total_conversations_expected ) + '.'
			);
		}

		return pieces.join( ' ' );
	}

	function postSyncAction( action ) {
		var formData = new window.FormData();

		formData.append( 'action', action );
		formData.append( '_ajax_nonce', syncAjaxNonce );

		return window.fetch(
			ajaxUrl,
			{
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			}
		).then(
			function( response ) {
				return response.json();
			}
		);
	}

	function startPollingSteps() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}

		postSyncAction( 'omega_cody_sync_step' )
			.then(
				function( payload ) {
					var state;
					var summaryUrl;

					if ( ! payload || true !== payload.success || ! payload.data || ! payload.data.state ) {
						if ( payload && payload.data && payload.data.message ) {
							throw new Error( payload.data.message );
						}

						throw new Error( getText( 'invalidSyncStepResponse', 'Invalid sync step response.' ) );
					}

					state = payload.data.state;
					if ( 'running' === state.status ) {
						setTitleVisible( false );
						setLastSyncVisible( false );
						setStatus( buildProgressText( state ) );
						setProgress( buildProgressPercent( state ) );
						pollTimer = window.setTimeout( startPollingSteps, 1200 );
						return;
					}

					if ( 'success' === state.status ) {
						setProgress( 100 );
						setStatus( buildProgressText( state ) );
						setSyncButtonEnabled( true, getText( 'syncButtonLabel', 'Sync with Cody API' ) );
						summaryUrl = new window.URL( window.location.href );
						summaryUrl.searchParams.set( 'omega_cody_sync_status', 'success' );
						summaryUrl.searchParams.set( 'processed', String( state.results.conversations_processed || 0 ) );
						summaryUrl.searchParams.set( 'added', String( state.results.conversations_added || 0 ) );
						summaryUrl.searchParams.set( 'skipped', String( state.results.conversations_skipped || 0 ) );
						summaryUrl.searchParams.set( 'messages_added', String( state.results.messages_added || 0 ) );
						window.setTimeout(
							function() {
								window.location.href = summaryUrl.toString();
							},
							500
						);
						return;
					}

					setStatus( state.progress_message || getText( 'syncFailed', 'Sync failed.' ) );
					setTitleVisible( true );
					setLastSyncVisible( true );
					setSyncButtonEnabled( true, getText( 'syncButtonLabel', 'Sync with Cody API' ) );
					isSyncing = false;
					setConversationListEnabled( true );
				}
			)
			.catch(
				function( error ) {
					setStatus( error && error.message ? error.message : getText( 'syncRequestFailed', 'Sync request failed.' ) );
					setTitleVisible( true );
					setLastSyncVisible( true );
					setSyncButtonEnabled( true, getText( 'syncButtonLabel', 'Sync with Cody API' ) );
					isSyncing = false;
					setConversationListEnabled( true );
				}
			);
	}

	if ( syncForm && window.fetch && isConfigured ) {
		syncForm.addEventListener(
			'submit',
			function( event ) {
				event.preventDefault();
				setTitleVisible( false );
				setLastSyncVisible( false );
				setSyncButtonEnabled( false, getText( 'syncingButtonLabel', 'Syncing...' ) );
				setProgress( 0 );
				setStatus( getText( 'startingSync', 'Starting sync...' ) );
				isSyncing = true;
				setConversationListEnabled( false );

				postSyncAction( 'omega_cody_sync_start' )
					.then(
						function( payload ) {
							if ( ! payload || true !== payload.success || ! payload.data || ! payload.data.state ) {
								if ( payload && payload.data && payload.data.message ) {
									throw new Error( payload.data.message );
								}

								throw new Error( getText( 'unableToStartSync', 'Unable to start sync.' ) );
							}

							setStatus( buildProgressText( payload.data.state ) );
							setProgress( buildProgressPercent( payload.data.state ) );
							startPollingSteps();
						}
					)
					.catch(
						function( error ) {
							setStatus( error && error.message ? error.message : getText( 'couldNotStartSync', 'Could not start sync.' ) );
							setTitleVisible( true );
							setLastSyncVisible( true );
							setSyncButtonEnabled( true, getText( 'syncButtonLabel', 'Sync with Cody API' ) );
							isSyncing = false;
							setConversationListEnabled( true );
						}
					);
			}
		);
	}

	if ( ! container ) {
		return;
	}

	var savedTop = window.sessionStorage.getItem( storageKey );
	if ( null !== savedTop ) {
		var parsedTop = parseInt( savedTop, 10 );
		if ( ! Number.isNaN( parsedTop ) ) {
			container.scrollTop = parsedTop;
		}
	}

	container.addEventListener(
		'scroll',
		function() {
			window.sessionStorage.setItem( storageKey, String( container.scrollTop ) );
		}
	);

	var rows = container.querySelectorAll( 'tr[data-omega-cody-thread-url]' );

	function goToRow( rowElement ) {
		var destination;

		if ( ! rowElement || isSyncing ) {
			return;
		}

		destination = rowElement.getAttribute( 'data-omega-cody-thread-url' );
		if ( ! destination ) {
			return;
		}

		window.sessionStorage.setItem( storageKey, String( container.scrollTop ) );
		window.location.href = destination;
	}

	for ( var i = 0; i < rows.length; i++ ) {
		rows[ i ].addEventListener(
			'click',
			function() {
				goToRow( this );
			}
		);

		rows[ i ].addEventListener(
			'keydown',
			function( event ) {
				if ( 'Enter' !== event.key && ' ' !== event.key ) {
					return;
				}

				event.preventDefault();
				goToRow( this );
			}
		);

		rows[ i ].addEventListener(
			'mousedown',
			function() {
				window.sessionStorage.setItem( storageKey, String( container.scrollTop ) );
			}
		);
	}
}( window, document ) );
