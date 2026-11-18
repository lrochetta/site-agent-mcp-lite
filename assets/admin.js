/**
 * Site Agent MCP admin screens: copy buttons, AI app tabs, confirmation
 * dialogs, focus on the notice of the last action, and backup progress.
 * No dependency.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.samcp' );
	if ( ! root ) {
		return;
	}
	var config = window.samcpAdmin || {};
	var strings = config.strings || {};
	var live = document.getElementById( 'samcp-live' );

	function each( selector, callback ) {
		Array.prototype.forEach.call( root.querySelectorAll( selector ), callback );
	}

	/* Polite announcement; cleared first so the same text is read again. */
	function announce( text ) {
		if ( ! live ) {
			return;
		}
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = text;
		}, 60 );
	}

	/* ------------------------------------------------------------ notice focus */

	var focusTarget = root.querySelector( '[data-samcp-focus]' );
	if ( focusTarget ) {
		focusTarget.focus();
	}

	/* ------------------------------------------------------------ copy */

	function fallbackCopy( text ) {
		var area = document.createElement( 'textarea' );
		var done = false;
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.className = 'samcp-offscreen';
		document.body.appendChild( area );
		area.select();
		try {
			done = document.execCommand( 'copy' );
		} catch ( e ) {
			done = false;
		}
		document.body.removeChild( area );
		return done ? Promise.resolve() : Promise.reject( new Error( 'copy' ) );
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return fallbackCopy( text );
			} );
		}
		return fallbackCopy( text );
	}

	function selectSource( source ) {
		if ( 'INPUT' === source.tagName || 'TEXTAREA' === source.tagName ) {
			source.focus();
			source.select();
			return;
		}
		var range = document.createRange();
		range.selectNodeContents( source );
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	}

	each( '[data-samcp-copy]', function ( button ) {
		var source = document.getElementById( button.getAttribute( 'data-samcp-copy' ) );
		if ( ! source ) {
			return;
		}
		button.hidden = false;
		var label = button.querySelector( '.samcp-copy-text' );
		var timer = null;
		button.addEventListener( 'click', function () {
			var text = 'value' in source && ( 'INPUT' === source.tagName || 'TEXTAREA' === source.tagName ) ? source.value : source.textContent;
			copyText( text.trim() ).then(
				function () {
					if ( label ) {
						label.textContent = strings.copied || 'Copied';
						window.clearTimeout( timer );
						timer = window.setTimeout( function () {
							label.textContent = strings.copy || 'Copy';
						}, 2000 );
					}
					announce( strings.copyDone || 'Copied to the clipboard.' );
				},
				function () {
					selectSource( source );
					announce( strings.copyFailed || 'Copy failed. The text is selected: press Ctrl+C, or Cmd+C on a Mac.' );
				}
			);
		} );
	} );

	/* ------------------------------------------------------------ client tabs */

	/*
	 * WAI-ARIA tabs with automatic activation: one AI app's instructions at a
	 * time. Without this script the tab list stays hidden and every panel shows.
	 */
	each( '[data-samcp-tabs]', function ( container ) {
		var list = container.querySelector( '[role="tablist"]' );
		if ( ! list ) {
			return;
		}
		var tabs = Array.prototype.slice.call( list.querySelectorAll( '[role="tab"]' ) );
		var panels = tabs.map( function ( tab ) {
			return document.getElementById( tab.getAttribute( 'aria-controls' ) );
		} );
		if ( ! tabs.length || -1 !== panels.indexOf( null ) ) {
			return;
		}

		function select( index, moveFocus ) {
			tabs.forEach( function ( tab, position ) {
				var selected = position === index;
				tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				tab.tabIndex = selected ? 0 : -1;
				panels[ position ].hidden = ! selected;
			} );
			if ( moveFocus ) {
				tabs[ index ].focus();
			}
		}

		tabs.forEach( function ( tab, position ) {
			tab.addEventListener( 'click', function () {
				select( position, false );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				// Arrow keys follow the reading direction: reversed in Arabic and Hebrew.
				var step = 'rtl' === window.getComputedStyle( list ).direction ? -1 : 1;
				var next;
				switch ( event.key ) {
					case 'ArrowRight':
						next = position + step;
						break;
					case 'ArrowLeft':
						next = position - step;
						break;
					case 'Home':
						next = 0;
						break;
					case 'End':
						next = tabs.length - 1;
						break;
					default:
						return;
				}
				event.preventDefault();
				select( ( next + tabs.length ) % tabs.length, true );
			} );
		} );

		panels.forEach( function ( panel ) {
			panel.tabIndex = 0;
		} );
		list.hidden = false;
		container.classList.add( 'samcp-tabs-ready' );
		var initial = 0;
		tabs.forEach( function ( tab, position ) {
			if ( 'true' === tab.getAttribute( 'aria-selected' ) ) {
				initial = position;
			}
		} );
		select( initial, false );
	} );

	/* ------------------------------------------------------------ confirmation */

	var dialog = document.getElementById( 'samcp-confirm' );
	if ( dialog && 'function' === typeof dialog.showModal ) {
		root.classList.add( 'samcp-has-dialog' );
		// The dialog replaces the tick box that confirms without JavaScript.
		each( '.samcp-confirm-fallback input', function ( input ) {
			input.required = false;
		} );

		var titleBox = dialog.querySelector( '[data-samcp-dialog-title]' );
		var bodyBox = dialog.querySelector( '[data-samcp-dialog-body]' );
		var okButton = dialog.querySelector( '[data-samcp-ok]' );
		var cancelButton = dialog.querySelector( '[data-samcp-cancel]' );
		var pending = null;
		var trigger = null;

		root.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( ! form.hasAttribute || ! form.hasAttribute( 'data-samcp-confirm' ) ) {
				return;
			}
			event.preventDefault();
			pending = form;
			trigger = event.submitter || form.querySelector( 'button' ) || document.activeElement;
			titleBox.textContent = form.getAttribute( 'data-samcp-confirm' );
			bodyBox.textContent = form.getAttribute( 'data-samcp-confirm-body' ) || '';
			okButton.textContent = form.getAttribute( 'data-samcp-confirm-button' ) || strings.confirm || 'Confirm';
			dialog.showModal();
			// The safe choice has the focus, so Enter never destroys anything.
			cancelButton.focus();
		} );

		okButton.addEventListener( 'click', function () {
			var form = pending;
			pending = null;
			dialog.close();
			if ( form ) {
				okButton.disabled = true;
				// The prototype call works even when an input is named "submit" or "action".
				HTMLFormElement.prototype.submit.call( form );
			}
		} );

		cancelButton.addEventListener( 'click', function () {
			pending = null;
			dialog.close();
		} );

		dialog.addEventListener( 'close', function () {
			pending = null;
			if ( trigger && document.body.contains( trigger ) ) {
				trigger.focus();
			}
		} );
	}

	/* ------------------------------------------------------------ backup progress */

	var box = document.getElementById( 'samcp-backup-progress' );
	if ( ! box || ! config.ajaxUrl || ! config.backupNonce ) {
		return;
	}
	var bar = box.querySelector( 'progress' );
	var textBox = box.querySelector( '[data-samcp-progress-text]' );
	var statusBox = box.querySelector( '[data-samcp-progress-status]' );
	var errorBox = box.querySelector( '[data-samcp-progress-error]' );
	var retryButton = box.querySelector( '[data-samcp-retry]' );
	var phases = strings.phases || {};
	var ranges = { listing: [ 0, 5 ], database: [ 5, 35 ], files: [ 35, 95 ], manifest: [ 95, 100 ] };
	var failures = 0;
	var lastPhase = '';
	var lastAnnounce = 0;
	var stopped = false;

	/* Fill %1$s, %2$s (or %s) of a translated string. */
	function format( pattern, values ) {
		var next = 0;
		return pattern.replace( /%(?:(\d+)\$)?s/g, function ( match, position ) {
			var value = values[ position ? +position - 1 : next++ ];
			return undefined === value ? match : value;
		} );
	}

	function show( state ) {
		var label = phases[ state.state ] || strings.inProgress || 'Backup in progress';
		// detail and ratio come translated from the server, in the person's language.
		var detail = state.detail || '';
		var text = detail ? format( strings.withDetail || '%1$s (%2$s)', [ label, detail ] ) : label;
		textBox.textContent = text;
		var range = ranges[ state.state ];
		var ratio = 'number' === typeof state.ratio ? Math.min( 1, Math.max( 0, state.ratio ) ) : 0;
		if ( range && bar ) {
			bar.value = Math.round( range[ 0 ] + ( range[ 1 ] - range[ 0 ] ) * ratio );
		}
		// Announce a new phase at once, the count at most every 15 seconds.
		var now = Date.now();
		if ( state.state !== lastPhase ) {
			lastPhase = state.state;
			lastAnnounce = now;
			statusBox.textContent = label;
		} else if ( now - lastAnnounce > 15000 ) {
			lastAnnounce = now;
			statusBox.textContent = text;
		}
	}

	function finish( state ) {
		stopped = true;
		var separator = -1 === config.backupsUrl.indexOf( '?' ) ? '?' : '&';
		window.location.assign( config.backupsUrl + separator + 'samcp_notice=' + ( 'complete' === state ? 'backup_complete' : 'backup_job_failed' ) );
	}

	function failed() {
		failures += 1;
		if ( failures >= 3 ) {
			stopped = true;
			errorBox.hidden = false;
			statusBox.textContent = strings.lostContact || 'Lost contact with the site.';
			return;
		}
		window.setTimeout( step, 5000 );
	}

	function step() {
		if ( stopped ) {
			return;
		}
		var body = new FormData();
		body.append( 'action', 'samcp_backup_step' );
		body.append( '_ajax_nonce', config.backupNonce );
		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				return response.json();
			} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					throw new Error( 'refused' );
				}
				failures = 0;
				var state = response.data || {};
				if ( 'complete' === state.state || 'failed' === state.state ) {
					finish( state.state );
					return;
				}
				show( state );
				window.setTimeout( step, state.busy ? 3000 : 300 );
			} )
			.catch( failed );
	}

	if ( retryButton ) {
		retryButton.addEventListener( 'click', function () {
			failures = 0;
			stopped = false;
			errorBox.hidden = true;
			statusBox.textContent = strings.retrying || 'Trying again.';
			step();
		} );
	}

	step();
}() );
