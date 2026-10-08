/**
 * Bonsai SEO/GEO Checker – admin screen.
 *
 * Flow: run_check (fast, ~5–15s) renders the report, then run_pagespeed and
 * run_ai fire in parallel and re-render when each returns.
 *
 * All dynamic content goes in via .text() / .attr() – never .html().
 */
/* global jQuery, BSGC */
( function ( $ ) {
	'use strict';

	var STATUS_LABELS = { pass: 'Pass', warn: 'Warning', fail: 'Fail', info: 'Info' };
	var STATUS_ORDER = { fail: 0, warn: 1, pass: 2, info: 3 };
	var EFFORT_LABELS = { quick: 'Quick fix', medium: 'Medium effort', involved: 'Involved' };
	var OWNER_LABELS = { client: 'Client', developer: 'Developer' };
	// PageSpeed strategies, in tab order. Mobile is scored; desktop is for reference.
	var PERF_TABS = { mobile: 'Mobile', desktop: 'Desktop' };

	var state = newState( null );
	var $form, $input, $button, $status, $report, $history;

	/* ------------------------------------------------------------------
	 * Helpers
	 * --------------------------------------------------------------- */

	function request( action, data ) {
		return $.ajax( {
			url: BSGC.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 180000,
			data: $.extend( { action: action, nonce: BSGC.nonce }, data || {} )
		} ).then(
			function ( res ) {
				if ( res && res.success ) {
					return res.data;
				}
				return $.Deferred().reject( ( res && res.data && res.data.message ) || 'Something went wrong. Try again.' ).promise();
			},
			function ( xhr, textStatus ) {
				var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				if ( ! msg ) {
					msg = 'timeout' === textStatus
						? 'The request timed out. The server may have a short PHP or proxy time limit.'
						: 'Request failed (' + ( xhr.status || textStatus ) + ').';
				}
				return $.Deferred().reject( msg ).promise();
			}
		);
	}

	function newState( report ) {
		return {
			report: report,
			perfPending: { mobile: false, desktop: false },
			aiPending: false,
			perfTab: 'mobile'
		};
	}

	function setStatus( message, isError ) {
		$status.text( message || '' ).toggleClass( 'is-error', !! isError );
	}

	function isCurrent( id ) {
		return state.report && state.report.id === id;
	}

	function settle() {
		if ( ! state.perfPending.mobile && ! state.perfPending.desktop && ! state.aiPending ) {
			setStatus( 'Report complete.' );
		}
	}

	function loading( text ) {
		return $( '<p class="bsgc-loading">' ).append(
			$( '<span class="spinner is-active" aria-hidden="true">' ),
			$( '<span>' ).text( text )
		);
	}

	function actionButton( text, handler ) {
		return $( '<button type="button" class="button">' ).text( text ).on( 'click', handler );
	}

	function isIssue( c ) {
		return 'warn' === c.status || 'fail' === c.status;
	}

	// URL without the protocol or trailing slash, for headings and file names.
	function displayUrl( url ) {
		return String( url ).replace( /^https?:\/\//i, '' ).replace( /\/$/, '' );
	}

	/* ------------------------------------------------------------------
	 * Running checks
	 * --------------------------------------------------------------- */

	function runCheck( url, postId ) {
		$button.prop( 'disabled', true );
		setStatus( 'Checking ' + url + '. This takes 5–15 seconds.' );

		request( 'bsgc_run_check', { url: url, post_id: postId || 0 } )
			.done( function ( data ) {
				state.report = data.report;
				upsertHistoryRow( data.history );
				setStatus( 'Checks done. PageSpeed and the fix list are still running.' );
				runPerf( 'mobile' );
				runPerf( 'desktop' );
				if ( BSGC.hasAi ) {
					runAi();
				} else {
					render();
				}
			} )
			.fail( function ( msg ) {
				setStatus( msg, true );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	}

	/**
	 * Run PageSpeed for one strategy. Mobile and desktop run in parallel and
	 * save to separate meta keys server-side.
	 */
	function runPerf( strategy ) {
		var desktop = 'desktop' === strategy;
		var key = desktop ? 'desktop' : 'mobile';
		var id = state.report.id;
		state.perfPending[ key ] = true;
		render();

		request( 'bsgc_run_pagespeed', { id: id, strategy: key } )
			.done( function ( data ) {
				if ( ! isCurrent( id ) ) {
					return;
				}
				// Desktop is unscored, so only its own block changes.
				if ( desktop ) {
					state.report.performance_desktop = data.report.performance_desktop;
					return;
				}
				var ai = state.report.ai;
				var desk = state.report.performance_desktop;
				state.report = data.report;
				// The fix list or desktop run may have landed locally after the server read them.
				if ( ai && ! state.report.ai ) {
					state.report.ai = ai;
				}
				if ( desk && 'none' !== desk.status ) {
					state.report.performance_desktop = desk;
				}
				$history.find( 'tr[data-id="' + id + '"] .bsgc-history__score' ).text( state.report.scores.overall );
			} )
			.fail( function ( msg ) {
				if ( isCurrent( id ) ) {
					state.report[ desktop ? 'performance_desktop' : 'performance' ] = { status: 'error', error: msg };
				}
			} )
			.always( function () {
				if ( isCurrent( id ) ) {
					state.perfPending[ key ] = false;
					render();
					settle();
				}
			} );
	}

	function runAi() {
		var id = state.report.id;
		state.aiPending = true;
		render();

		request( 'bsgc_run_ai', { id: id } )
			.done( function ( data ) {
				if ( isCurrent( id ) ) {
					state.report.ai = data.ai;
				}
			} )
			.fail( function ( msg ) {
				if ( isCurrent( id ) ) {
					state.report.ai = { status: 'error', error: msg };
				}
			} )
			.always( function () {
				if ( isCurrent( id ) ) {
					state.aiPending = false;
					render();
					settle();
				}
			} );
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * --------------------------------------------------------------- */

	function render() {
		var r = state.report;
		if ( ! r ) {
			$report.empty().prop( 'hidden', true );
			return;
		}

		$report.empty().prop( 'hidden', false ).append(
			renderCover( r ),
			renderHeader( r ),
			renderScores( r ),
			renderFixes( r ),
			renderCategories( r ),
			renderPasses( r )
		);
	}

	function renderHeader( r ) {
		var meta = 'Checked ' + r.checked_at_label + '.';
		if ( r.final_url !== r.url ) {
			meta += ' Redirected from ' + r.url + '.';
		}

		return $( '<header class="bsgc-report__header">' ).append(
			$( '<div class="bsgc-report__title">' ).append(
				$( '<h2>' ).append(
					$( '<a target="_blank" rel="noopener noreferrer">' ).attr( 'href', r.final_url ).text( r.final_url )
				),
				$( '<p class="bsgc-report__meta">' ).text( meta )
			),
			$( '<div class="bsgc-report__actions">' ).append(
				actionButton( 'Copy client summary', copySummary ),
				actionButton( 'Print or save as PDF', function () {
					window.print();
				} ),
				actionButton( 'Run again', function () {
					runCheck( r.url, r.post_id );
				} )
			)
		);
	}

	/**
	 * Print-only cover page: branding, URL, overall score, summary and who prepared it.
	 */
	function renderCover( r ) {
		var brand = BSGC.brand || {};
		var issues = $.grep( r.checks, isIssue ).length;
		var passed = $.grep( r.checks, function ( c ) {
			return 'pass' === c.status;
		} ).length;

		var $top = $( '<div class="bsgc-cover__top">' );
		if ( brand.logo ) {
			$top.append( $( '<img class="bsgc-cover__logo">' ).attr( { src: brand.logo, alt: brand.logoAlt || '' } ) );
		}

		var $main = $( '<div class="bsgc-cover__main">' ).append(
			$( '<p class="bsgc-cover__eyebrow">' ).text( 'SEO and AI visibility report' ),
			$( '<p class="bsgc-cover__url">' ).text( displayUrl( r.final_url ) ),
			$( '<div class="bsgc-cover__score">' ).append(
				scoreRing( r.scores.overall, 'Overall score' ),
				$( '<p class="bsgc-cover__stats">' ).append(
					$( '<strong>' ).text( 'Overall score' ),
					$( '<span>' ).text( issues + ( 1 === issues ? ' issue' : ' issues' ) + ' to fix' ),
					$( '<span>' ).text( passed + ' checks passed' )
				)
			)
		);

		if ( hasReadiness( r ) ) {
			$main.find( '.bsgc-cover__score' ).append(
				$( '<div class="bsgc-cover__ready">' ).append(
					scoreRing( r.scores.ai_readiness, 'AI readiness', true ),
					$( '<p class="bsgc-cover__stats">' ).append(
						$( '<strong>' ).text( 'AI readiness' ),
						$( '<span>' ).text( 'How easily AI search can reach, read and quote the page' )
					)
				)
			);
		}

		if ( r.ai && 'done' === r.ai.status && r.ai.summary ) {
			$main.append( $( '<p class="bsgc-cover__summary">' ).text( r.ai.summary ) );
		}

		var $meta = $( '<dl class="bsgc-cover__meta">' ).append(
			$( '<dt>' ).text( 'Checked' ),
			$( '<dd>' ).text( r.checked_at_label )
		);
		if ( brand.preparedBy ) {
			$meta.append( $( '<dt>' ).text( 'Prepared by' ), $( '<dd>' ).text( brand.preparedBy ) );
		}

		return $( '<section class="bsgc-cover bsgc-print-only">' ).append( $top, $main, $meta );
	}

	/**
	 * Circular score. Pass small = true for the secondary (AI readiness) ring.
	 */
	function scoreRing( value, label, small ) {
		return $( '<div class="bsgc-score" role="img">' )
			.toggleClass( 'bsgc-score--small', !! small )
			.attr( 'aria-label', label + ' ' + value + ' out of 100' )
			.css( '--bsgc-score', String( value ) )
			.append(
				$( '<span class="bsgc-score__inner" aria-hidden="true">' ).append(
					$( '<span class="bsgc-score__value">' ).text( value ),
					$( '<span class="bsgc-score__max">' ).text( 'out of 100' )
				)
			);
	}

	/**
	 * Mirrors BSGC_Analyser::counts_for_readiness(): every AI visibility check, plus the listed IDs.
	 */
	function countsForReadiness( c ) {
		return 'ai' === c.category || -1 !== $.inArray( c.id, BSGC.aiReadiness || [] );
	}

	function hasReadiness( r ) {
		return undefined !== r.scores.ai_readiness;
	}

	function renderScores( r ) {
		var $cats = $( '<ul class="bsgc-cats">' );

		$.each( BSGC.categories, function ( key, label ) {
			var value = r.scores.categories[ key ];
			if ( undefined === value ) {
				return;
			}
			$cats.append(
				$( '<li>' ).append(
					$( '<span class="bsgc-cats__label">' ).text( label ),
					$( '<span class="bsgc-cats__bar" aria-hidden="true">' ).append(
						$( '<span>' ).css( 'width', value + '%' )
					),
					$( '<span class="bsgc-cats__value">' ).text( value )
				)
			);
		} );

		var $rings = $( '<div class="bsgc-score-rings">' ).append(
			$( '<figure class="bsgc-score-item">' ).append(
				scoreRing( r.scores.overall, 'Overall score' ),
				$( '<figcaption>' ).text( 'Overall' )
			)
		);

		if ( hasReadiness( r ) ) {
			$rings.append(
				$( '<figure class="bsgc-score-item">' ).append(
					scoreRing( r.scores.ai_readiness, 'AI readiness', true ),
					$( '<figcaption>' ).append(
						document.createTextNode( 'AI readiness' ),
						$( '<span class="bsgc-score-item__hint">' ).text( 'AI visibility checks plus those tagged AI' )
					)
				)
			);
		}

		var $side = $( '<div class="bsgc-score-wrap">' ).append( $rings );
		if ( state.perfPending.mobile ) {
			$side.append( $( '<p class="bsgc-score-note">' ).text( 'Performance will be added when PageSpeed finishes.' ) );
		}

		return $( '<div class="bsgc-scores">' ).append( $side, $cats );
	}

	function renderFixes( r ) {
		var $panel = $( '<section class="bsgc-panel bsgc-fixes-panel">' ).append( $( '<h3>' ).text( 'Fix list' ) );
		var ai = r.ai;

		if ( ! BSGC.hasAi ) {
			var $p = $( '<p>' ).text( 'Add an Anthropic API key to generate a prioritised fix list. ' );
			if ( BSGC.settingsUrl ) {
				$p.append( $( '<a>' ).attr( 'href', BSGC.settingsUrl ).text( 'Open settings' ) );
			}
			return $panel.append( $p );
		}

		if ( state.aiPending ) {
			return $panel.append( loading( 'Writing the fix list…' ) );
		}

		if ( ! ai ) {
			return $panel.append( actionButton( 'Write fix list', runAi ) );
		}

		if ( 'error' === ai.status ) {
			return $panel.append(
				$( '<p class="bsgc-error">' ).text( ai.error ),
				actionButton( 'Try again', runAi )
			);
		}

		if ( ai.summary ) {
			$panel.append( $( '<p class="bsgc-fixes__summary">' ).text( ai.summary ) );
		}

		if ( ai.fixes && ai.fixes.length ) {
			var $list = $( '<ol class="bsgc-fixes">' );
			$.each( ai.fixes, function ( i, fix ) {
				$list.append(
					$( '<li class="bsgc-fix">' ).append(
						$( '<h4 class="bsgc-fix__title">' ).text( fix.title ),
						$( '<p>' ).text( fix.why ),
						$( '<p class="bsgc-fix__how">' ).append( $( '<strong>' ).text( 'How: ' ), document.createTextNode( fix.how ) ),
						$( '<p class="bsgc-tags">' ).append(
							$( '<span class="bsgc-tag">' ).text( EFFORT_LABELS[ fix.effort ] || fix.effort ),
							$( '<span class="bsgc-tag">' ).text( OWNER_LABELS[ fix.owner ] || fix.owner )
						)
					)
				);
			} );
			$panel.append( $list );
		}

		return $panel.append(
			$( '<p class="bsgc-regenerate">' ).append( actionButton( 'Rewrite fix list', runAi ) )
		);
	}

	function renderCategories( r ) {
		var $wrap = $( '<div class="bsgc-categories">' );

		$.each( BSGC.categories, function ( key, label ) {
			var checks = $.grep( r.checks, function ( c ) {
				return c.category === key;
			} ).sort( function ( a, b ) {
				return STATUS_ORDER[ a.status ] - STATUS_ORDER[ b.status ];
			} );

			var $heading = $( '<h3>' ).append( $( '<span>' ).text( label ) );
			if ( undefined !== r.scores.categories[ key ] ) {
				$heading.append( $( '<span class="bsgc-panel__score">' ).text( r.scores.categories[ key ] + '/100' ) );
			}

			var $section = $( '<section class="bsgc-panel">' ).append( $heading );

			// Categories with nothing to fix are left out of the PDF; their checks appear in "Already in good shape".
			if ( 'performance' !== key && ! $.grep( checks, isIssue ).length ) {
				$section.addClass( 'bsgc-panel--clean' );
			}

			if ( 'performance' === key ) {
				$wrap.append( renderPerformance( $section, r, checks ) );
				return;
			}

			if ( ! checks.length ) {
				return;
			}

			$wrap.append( $section.append( renderTable( checks ) ) );
		} );

		return $wrap;
	}

	/**
	 * Performance panel with Mobile | Desktop tabs. Print shows both panels,
	 * one after the other, and hides the tab bar.
	 */
	function renderPerformance( $section, r, mobileChecks ) {
		var $tabs = $( '<div class="bsgc-perf-tabs" role="tablist">' ).attr( 'aria-label', 'PageSpeed results by device' );
		var $panels = $();

		$.each( PERF_TABS, function ( strategy, label ) {
			var perf = ( 'desktop' === strategy ? r.performance_desktop : r.performance ) || {};
			var checks = 'desktop' === strategy ? ( perf.checks || [] ) : mobileChecks;
			var score = perf.metrics ? perf.metrics.score : null;
			var selected = state.perfTab === strategy;
			var tabId = 'bsgc-perf-tab-' + strategy;
			var panelId = 'bsgc-perf-panel-' + strategy;

			var $tab = $( '<button type="button" role="tab" class="bsgc-perf-tab">' )
				.attr( {
					id: tabId,
					'aria-controls': panelId,
					'aria-selected': selected ? 'true' : 'false',
					tabindex: selected ? 0 : -1,
					'data-strategy': strategy
				} )
				.append( $( '<span>' ).text( label ) );

			if ( 'number' === typeof score && ! state.perfPending[ strategy ] ) {
				$tab.append(
					$( '<span class="screen-reader-text">' ).text( ', score' ),
					$( '<span class="bsgc-perf-tab__score">' ).text( score )
				);
			}

			var $panel = $( '<div role="tabpanel" class="bsgc-perf-panel">' )
				.attr( { id: panelId, 'aria-labelledby': tabId, tabindex: 0 } )
				.prop( 'hidden', ! selected )
				.toggleClass( 'bsgc-perf-panel--empty', ! checks.length )
				.append(
					$( '<h4 class="bsgc-perf-panel__title bsgc-print-only">' ).text( label ),
					renderPerfBody( strategy, label, perf, checks )
				);

			$tabs.append( $tab );
			$panels = $panels.add( $panel );
		} );

		$tabs
			.on( 'click', '.bsgc-perf-tab', function () {
				selectPerfTab( $tabs, $( this ).data( 'strategy' ) );
			} )
			.on( 'keydown', '.bsgc-perf-tab', function ( e ) {
				var $all = $tabs.find( '.bsgc-perf-tab' );
				var i = $all.index( this );
				var next;

				switch ( e.key ) {
					case 'ArrowRight':
						next = ( i + 1 ) % $all.length;
						break;
					case 'ArrowLeft':
						next = ( i - 1 + $all.length ) % $all.length;
						break;
					case 'Home':
						next = 0;
						break;
					case 'End':
						next = $all.length - 1;
						break;
					default:
						return;
				}

				e.preventDefault();
				selectPerfTab( $tabs, $all.eq( next ).data( 'strategy' ) );
				$all.eq( next ).trigger( 'focus' );
			} );

		return $section.append( $tabs, $panels );
	}

	// Switch tabs in place (no re-render) so keyboard focus stays put.
	function selectPerfTab( $tabs, strategy ) {
		state.perfTab = strategy;

		$tabs.find( '.bsgc-perf-tab' ).each( function () {
			var on = $( this ).data( 'strategy' ) === strategy;
			$( this ).attr( { 'aria-selected': on ? 'true' : 'false', tabindex: on ? 0 : -1 } );
		} );

		$tabs.siblings( '.bsgc-perf-panel' ).each( function () {
			$( this ).prop( 'hidden', this.id !== 'bsgc-perf-panel-' + strategy );
		} );
	}

	function renderPerfBody( strategy, label, perf, checks ) {
		var run = function () {
			runPerf( strategy );
		};

		if ( state.perfPending[ strategy ] ) {
			return loading( 'Running PageSpeed Insights on ' + strategy + '. Usually 20–40 seconds.' );
		}

		if ( 'error' === perf.status ) {
			return $( '<p class="bsgc-error">' ).text( perf.error ).add( actionButton( 'Try again', run ) );
		}

		if ( ! checks.length ) {
			return actionButton( 'Run ' + label.toLowerCase() + ' test', run );
		}

		var $body = renderTable( checks );

		if ( 'desktop' === strategy ) {
			$body = $( '<p class="bsgc-perf-note">' )
				.text( 'For reference only. The Performance score uses mobile, because Google ranks the mobile version of a page.' )
				.add( $body );
		}

		return $body;
	}

	function renderTable( checks ) {
		var $tbody = $( '<tbody>' );

		$.each( checks, function ( i, c ) {
			var $finding = $( '<td>' ).text( c.message );
			if ( c.value && -1 === c.message.indexOf( c.value ) ) {
				$finding.append( $( '<span class="bsgc-value">' ).text( c.value ) );
			}
			// Older saved reports have no fix; pass checks don't need one.
			if ( c.fix && ( 'warn' === c.status || 'fail' === c.status ) ) {
				$finding.append(
					$( '<span class="bsgc-check-fix">' ).append(
						$( '<strong>' ).text( 'Fix: ' ),
						$( '<span>' ).text( c.fix )
					)
				);
			}

			var $label = $( '<th scope="row">' ).text( c.label );
			// Every AI visibility check counts, so only tag the ones from other sections.
			if ( 'ai' !== c.category && countsForReadiness( c ) ) {
				$label.append(
					' ',
					$( '<span class="bsgc-tag bsgc-tag--ai" aria-hidden="true">' ).text( 'AI' ),
					$( '<span class="screen-reader-text">' ).text( '(counts towards AI readiness)' )
				);
			}

			$tbody.append(
				$( '<tr>' ).addClass( 'bsgc-checks__row--' + c.status ).append(
					$( '<td class="bsgc-checks__result">' ).append(
						$( '<span class="bsgc-badge">' ).addClass( 'bsgc-badge--' + c.status ).text( STATUS_LABELS[ c.status ] || c.status )
					),
					$label,
					$finding
				)
			);
		} );

		return $( '<div class="bsgc-table-wrap">' ).append(
			$( '<table class="bsgc-checks">' ).append(
				$( '<thead>' ).append(
					$( '<tr>' ).append(
						$( '<th scope="col">' ).text( 'Result' ),
						$( '<th scope="col">' ).text( 'Check' ),
						$( '<th scope="col">' ).text( 'Finding' )
					)
				),
				$tbody
			)
		);
	}

	/**
	 * Print-only condensed list of passing and info checks, so the PDF leads
	 * with what needs fixing but still shows what's already right.
	 */
	function renderPasses( r ) {
		var $section = $( '<section class="bsgc-panel bsgc-passes bsgc-print-only">' ).append(
			$( '<h3>' ).text( 'Already in good shape' ),
			$( '<p class="bsgc-passes__intro">' ).text( 'These checks passed. Notes are for information and aren\'t scored.' )
		);
		var any = false;

		$.each( BSGC.categories, function ( key, label ) {
			var rows = $.grep( r.checks, function ( c ) {
				return c.category === key && ( 'pass' === c.status || 'info' === c.status );
			} );

			if ( ! rows.length ) {
				return;
			}

			any = true;
			var $list = $( '<ul class="bsgc-passes__list">' );

			$.each( rows, function ( i, c ) {
				$list.append(
					$( '<li>' ).addClass( 'bsgc-passes__item--' + c.status ).append(
						$( '<span class="bsgc-passes__mark">' ).text( 'pass' === c.status ? 'Pass' : 'Note' ),
						$( '<strong>' ).text( c.label ),
						$( '<span>' ).text( ' – ' + c.message )
					)
				);
			} );

			$section.append( $( '<h4>' ).text( label ), $list );
		} );

		return any ? $section : $();
	}

	/* ------------------------------------------------------------------
	 * Client summary
	 * --------------------------------------------------------------- */

	function buildSummary( r ) {
		var lines = [
			'SEO and AI visibility check for ' + r.final_url,
			'Checked ' + r.checked_at_label + '. Overall score: ' + r.scores.overall + '/100.' +
				( hasReadiness( r ) ? ' AI readiness: ' + r.scores.ai_readiness + '/100.' : '' ),
			''
		];

		if ( r.ai && 'done' === r.ai.status ) {
			if ( r.ai.summary ) {
				lines.push( r.ai.summary, '' );
			}
			if ( r.ai.fixes && r.ai.fixes.length ) {
				lines.push( 'What we recommend, in priority order:' );
				$.each( r.ai.fixes, function ( i, fix ) {
					lines.push( ( i + 1 ) + '. ' + fix.title + '. ' + fix.why );
				} );
			}
		} else {
			lines.push( 'Issues found:' );
			$.each( r.checks, function ( i, c ) {
				if ( 'fail' === c.status || 'warn' === c.status ) {
					lines.push( c.label + ': ' + c.message );
				}
			} );
		}

		lines.push( '', 'Thanks', BSGC.signOff );
		return lines.join( '\n' );
	}

	function copySummary() {
		var text = buildSummary( state.report );

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then(
				function () {
					setStatus( 'Client summary copied.' );
				},
				function () {
					fallbackCopy( text );
				}
			);
		} else {
			fallbackCopy( text );
		}
	}

	function fallbackCopy( text ) {
		var $ta = $( '<textarea readonly class="bsgc-offscreen">' ).val( text ).appendTo( 'body' );
		$ta[ 0 ].select();
		try {
			document.execCommand( 'copy' );
			setStatus( 'Client summary copied.' );
		} catch ( e ) {
			setStatus( 'Copy failed. Select the text manually.', true );
		}
		$ta.remove();
	}

	/* ------------------------------------------------------------------
	 * History
	 * --------------------------------------------------------------- */

	function upsertHistoryRow( item ) {
		$history.find( '.bsgc-history__empty' ).remove();
		$history.find( 'tr[data-id="' + item.id + '"]' ).remove();

		var $row = $( '<tr>' ).attr( 'data-id', item.id ).append(
			$( '<td>' ).append( $( '<a target="_blank" rel="noopener noreferrer">' ).attr( 'href', item.url ).text( item.url ) ),
			$( '<td class="bsgc-history__score">' ).text( null === item.score ? '—' : item.score ),
			$( '<td>' ).text( item.date ),
			$( '<td class="bsgc-history__actions">' ).append(
				$( '<button type="button" class="button-link bsgc-view">' ).attr( 'aria-label', 'View report for ' + item.url ).text( 'View' ),
				' ',
				$( '<button type="button" class="button-link button-link-delete bsgc-delete">' ).attr( 'aria-label', 'Delete report for ' + item.url ).text( 'Delete' )
			)
		);

		$history.prepend( $row );
	}

	function viewReport( id ) {
		setStatus( 'Loading report…' );
		request( 'bsgc_get_report', { id: id } )
			.done( function ( data ) {
				state = newState( data.report );
				render();
				setStatus( '' );
				$report[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} )
			.fail( function ( msg ) {
				setStatus( msg, true );
			} );
	}

	function deleteReport( id, $row ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Delete this report? This can\'t be undone.' ) ) {
			return;
		}

		request( 'bsgc_delete_report', { id: id } )
			.done( function () {
				$row.remove();
				if ( isCurrent( id ) ) {
					state = newState( null );
					render();
				}
				setStatus( 'Report deleted.' );
			} )
			.fail( function ( msg ) {
				setStatus( msg, true );
			} );
	}

	/* ------------------------------------------------------------------
	 * Init
	 * --------------------------------------------------------------- */

	$( function () {
		$form = $( '#bsgc-form' );
		$input = $( '#bsgc-url' );
		$button = $form.find( 'button[type="submit"]' );
		$status = $( '#bsgc-status' );
		$report = $( '#bsgc-report' );
		$history = $( '#bsgc-history-body' );

		$form.on( 'submit', function ( e ) {
			e.preventDefault();
			var url = $.trim( $input.val() );
			if ( ! url ) {
				setStatus( 'Enter a URL to check.', true );
				$input.trigger( 'focus' );
				return;
			}
			runCheck( url );
		} );

		$history.on( 'click', '.bsgc-view', function () {
			viewReport( parseInt( $( this ).closest( 'tr' ).data( 'id' ), 10 ) );
		} );

		$history.on( 'click', '.bsgc-delete', function () {
			var $row = $( this ).closest( 'tr' );
			deleteReport( parseInt( $row.data( 'id' ), 10 ), $row );
		} );

		// Browsers use the page title as the PDF file name, so swap it while printing.
		var originalTitle = document.title;
		$( window )
			.on( 'beforeprint.bonsai_bsgc', function () {
				var r = state.report;
				if ( r ) {
					var date = new Date( r.checked_at * 1000 ).toISOString().slice( 0, 10 );
					document.title = 'SEO report – ' + displayUrl( r.final_url ).replace( /[\/?#:&=]+/g, '-' ) + ' – ' + date;
				}
			} )
			.on( 'afterprint.bonsai_bsgc', function () {
				document.title = originalTitle;
			} );

		launch( BSGC.launch );
	} );

	/**
	 * Arriving from the editor box or admin bar: run a page or open a report.
	 */
	function launch( args ) {
		if ( ! args ) {
			return;
		}

		// Drop the query args so a reload doesn't run the check again.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', BSGC.pageUrl );
		}

		if ( args.report ) {
			viewReport( args.report );
			return;
		}

		$input.val( args.url );

		if ( args.autorun ) {
			runCheck( args.url, args.postId );
		} else {
			setStatus( 'That link has expired. Press Run check to check this page.' );
			$button.trigger( 'focus' );
		}
	}
}( jQuery ) );
