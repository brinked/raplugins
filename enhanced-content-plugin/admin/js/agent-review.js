/**
 * Agent admin behaviour.
 *
 * The review queue is built for speed: J/K to move, A to apply, R to reject,
 * E to edit. Cards resolve in place and fade out rather than reloading the
 * page, so a reviewer keeps their position and their momentum.
 */
(function ($) {
	'use strict';

	var i18n = (window.ecpAgent && window.ecpAgent.i18n) || {};

	function t(key, fallback) {
		return i18n[key] || fallback || key;
	}

	function sprintf(template, values) {
		var i = 0;
		return String(template).replace(/%(\d+\$)?[ds]/g, function (match, position) {
			var index = position ? parseInt(position, 10) - 1 : i++;
			return typeof values[index] === 'undefined' ? match : values[index];
		});
	}

	/**
	 * All AJAX goes through here so the nonce and error handling live in one
	 * place.
	 */
	function post(action, data) {
		return $.ajax({
			url: window.ecpAgent.ajaxurl,
			method: 'POST',
			data: $.extend({ action: 'ecp_' + action, nonce: window.ecpAgent.nonce }, data || {}),
			dataType: 'json'
		}).then(function (response) {
			if (!response || !response.success) {
				var message = (response && response.data && response.data.message) || t('failed');
				return $.Deferred().reject(message).promise();
			}
			return response.data || {};
		}, function (xhr) {
			var message = t('networkError');
			if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}
			return $.Deferred().reject(message).promise();
		});
	}

	function announce(message) {
		if (window.wp && window.wp.a11y && window.wp.a11y.speak) {
			window.wp.a11y.speak(message, 'polite');
		}
	}

	function setStatus($card, message, isError) {
		$card.find('.ecp-card-status, .ecp-row-status')
			.first()
			.attr('class', 'ecp-card-status' + (isError ? ' is-error' : ' is-ok'))
			.text(message);

		announce(message);
	}

	function busy($card, on) {
		$card.toggleClass('is-busy', !!on);
		$card.find('button').prop('disabled', !!on);
	}

	/**
	 * Fade a resolved card out and move focus on, so keyboard reviewers are
	 * never dropped back to the top of the document.
	 */
	function retire($card, label) {
		$card.addClass('is-resolved');
		$card.find('.ecp-card-actions').html('<span class="ecp-resolved-label">' + label + '</span>');

		var $next = $card.nextAll('.ecp-card').not('.is-resolved').first();

		window.setTimeout(function () {
			$card.slideUp(200, function () {
				$card.remove();

				if (!$('.ecp-card').not('.is-resolved').length) {
					$('#ecp-cards').append('<div class="ecp-empty"><h2>' + t('nothingLeft') + '</h2></div>');
					announce(t('nothingLeft'));
				}
			});
		}, 900);

		if ($next.length) {
			focusCard($next);
		}
	}

	function updatePendingBadge(count) {
		if (typeof count === 'undefined') {
			return;
		}

		$('.ecp-tab-count, #adminmenu .plugin-count').text(count);

		if (0 === count) {
			$('.ecp-tab-count, #adminmenu .update-plugins').remove();
			$('#wp-admin-bar-ecp-pending').remove();
		}
	}

	/* ------------------------------------------------------------------
	 * Single-card actions
	 * --------------------------------------------------------------- */

	function approve($card) {
		busy($card, true);
		setStatus($card, t('approving'));

		post('approve', { id: $card.data('id') })
			.done(function (data) {
				setStatus($card, data.message || t('approved'));
				updatePendingBadge(data.pending);
				retire($card, data.message || t('approved'));
			})
			.fail(function (message) {
				busy($card, false);
				setStatus($card, message, true);
			});
	}

	function reject($card) {
		busy($card, true);
		setStatus($card, t('rejecting'));

		post('reject', { id: $card.data('id') })
			.done(function (data) {
				updatePendingBadge(data.pending);
				retire($card, t('rejected'));
			})
			.fail(function (message) {
				busy($card, false);
				setStatus($card, message, true);
			});
	}

	// Whether wp.editor (classic TinyMCE + quicktags) is available. It is
	// enqueued on our screens, but a plugin conflict can still keep it out —
	// in which case the plain textarea keeps working as before.
	function editorAvailable() {
		return window.wp && wp.editor && typeof wp.editor.initialize === 'function';
	}

	function toggleEdit($card, show) {
		var $panel = $card.find('.ecp-edit-panel');

		if (!$panel.length) {
			return;
		}

		$panel.prop('hidden', !show);
		$card.toggleClass('is-editing', show);

		var $field = $panel.find('.ecp-edit-field');
		var id = $field.attr('id');
		var visual = $field.hasClass('ecp-edit-html') && editorAvailable();

		if (show) {
			if (visual && !$field.data('ecp-editor')) {
				// Strip Gutenberg block comments before handing the HTML to
				// TinyMCE — it mangles them, and the applier re-wraps plain
				// HTML into proper blocks on save anyway.
				$field.val(String($field.val()).replace(/<!--\s*\/?wp:[\s\S]*?-->\s?/g, ''));

				wp.editor.initialize(id, {
					mediaButtons: false,
					quicktags: true,   // The Text tab, for anyone who wants the raw HTML.
					tinymce: {
						wpautop: false,
						toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,blockquote,undo,redo',
						toolbar2: '',
						height: 280
					}
				});

				$field.data('ecp-editor', true);
			}

			if (!visual) {
				$field.trigger('focus');
			}
		} else if ($field.data('ecp-editor')) {
			// Tear down on close so reopening initializes cleanly.
			wp.editor.remove(id);
			$field.data('ecp-editor', false);
		}
	}

	function saveEdit($card) {
		var $field = $card.find('.ecp-edit-field');
		var value;

		if ($field.data('ecp-editor') && editorAvailable()) {
			value = wp.editor.getContent($field.attr('id'));
		} else {
			value = $field.val();
		}

		busy($card, true);
		setStatus($card, t('approving'));

		post('edit_apply', { id: $card.data('id'), value: value })
			.done(function (data) {
				updatePendingBadge(data.pending);
				retire($card, data.message || t('approved'));
			})
			.fail(function (message) {
				busy($card, false);
				setStatus($card, message, true);
			});
	}

	function revert($el) {
		if (!window.confirm(t('confirmRevert'))) {
			return;
		}

		var $row = $el.closest('.ecp-card, tr');
		var id = $el.data('id') || $row.data('id');

		$el.prop('disabled', true).text(t('approving'));

		post('revert', { id: id })
			.done(function () {
				$row.addClass('ecp-row-reverted');
				$row.find('.ecp-card-status, .ecp-row-status').first().text(t('reverted'));
				$el.remove();
				announce(t('reverted'));
			})
			.fail(function (message) {
				$el.prop('disabled', false).text(t('cancel'));
				$row.find('.ecp-card-status, .ecp-row-status').first().addClass('is-error').text(message);
			});
	}

	/* ------------------------------------------------------------------
	 * Bulk
	 * --------------------------------------------------------------- */

	function selectedIds() {
		return $('.ecp-card-select:checked').map(function () {
			return $(this).val();
		}).get();
	}

	function refreshBulkState() {
		var count = selectedIds().length;

		$('#ecp-bulk-approve, #ecp-bulk-reject').prop('disabled', 0 === count);
		$('.ecp-selected-count').text(count ? count + ' selected' : '');
	}

	function runBulk(operation, ids) {
		if (!ids.length) {
			return;
		}

		if ('approve' === operation && !window.confirm(sprintf(t('confirmBulk'), [ids.length]))) {
			return;
		}

		var $bar = $('.ecp-bulk-bar');
		$bar.find('button').prop('disabled', true);
		$bar.find('.ecp-selected-count').text(t('approving'));

		post('bulk', { operation: operation, ids: ids })
			.done(function (data) {
				updatePendingBadge(data.pending);

				(data.succeeded || []).forEach(function (id) {
					retire($('#ecp-proposal-' + id), 'approve' === operation ? t('approved') : t('rejected'));
				});

				(data.failed || []).forEach(function (failure) {
					var $card = $('#ecp-proposal-' + failure.id);
					busy($card, false);
					setStatus($card, failure.message, true);
				});

				$bar.find('.ecp-selected-count').text(data.message || '');
				$('#ecp-select-all').prop('checked', false);
				refreshBulkState();
			})
			.fail(function (message) {
				$bar.find('.ecp-selected-count').addClass('is-error').text(message);
				$bar.find('button').prop('disabled', false);
			});
	}

	/* ------------------------------------------------------------------
	 * Keyboard
	 * --------------------------------------------------------------- */

	var focusIndex = -1;

	function cards() {
		return $('.ecp-card').not('.is-resolved');
	}

	function focusCard($card) {
		if (!$card || !$card.length) {
			return;
		}

		cards().removeClass('is-focused');
		$card.addClass('is-focused').trigger('focus');

		focusIndex = cards().index($card);

		var top = $card.offset().top - 80;
		if (top < $(window).scrollTop() || top > $(window).scrollTop() + $(window).height() - 200) {
			$('html, body').animate({ scrollTop: top }, 150);
		}
	}

	function moveFocus(delta) {
		var $all = cards();

		if (!$all.length) {
			return;
		}

		focusIndex = Math.max(0, Math.min($all.length - 1, focusIndex + delta));
		focusCard($all.eq(focusIndex));
	}

	function isTyping(event) {
		var tag = (event.target.tagName || '').toLowerCase();

		return 'input' === tag || 'textarea' === tag || 'select' === tag || event.target.isContentEditable;
	}

	$(document).on('keydown', function (event) {
		if (isTyping(event) || event.metaKey || event.ctrlKey || event.altKey) {
			return;
		}

		if (!cards().length) {
			return;
		}

		var key = event.key.toLowerCase();
		var $current = focusIndex >= 0 ? cards().eq(focusIndex) : null;

		switch (key) {
			case 'j':
				event.preventDefault();
				moveFocus(focusIndex < 0 ? 0 : 1);
				break;

			case 'k':
				event.preventDefault();
				moveFocus(-1);
				break;

			case 'a':
				if ($current && $current.length) {
					event.preventDefault();
					approve($current);
				}
				break;

			case 'r':
				if ($current && $current.length) {
					event.preventDefault();
					reject($current);
				}
				break;

			case 'e':
				if ($current && $current.length) {
					event.preventDefault();
					toggleEdit($current, true);
				}
				break;

			case 'escape':
				if ($current && $current.length) {
					toggleEdit($current, false);
				}
				break;
		}
	});

	/* ------------------------------------------------------------------
	 * Scan
	 * --------------------------------------------------------------- */

	function runScan(offset, $button, $progress) {
		post('scan', { offset: offset })
			.done(function (data) {
				if (data.done) {
					$progress.text(sprintf(t('scanDone'), [data.offset]));
					$button.prop('disabled', false).text($button.data('label'));

					window.setTimeout(function () {
						window.location.reload();
					}, 900);

					return;
				}

				$progress.text(sprintf(t('scanning'), [data.offset, data.total]));
				runScan(data.offset, $button, $progress);
			})
			.fail(function (message) {
				$progress.addClass('is-error').text(message);
				$button.prop('disabled', false).text($button.data('label'));
			});
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * --------------------------------------------------------------- */

	$(function () {
		var $cards = $('#ecp-cards');

		$cards.on('click', '.ecp-approve', function () {
			approve($(this).closest('.ecp-card'));
		});

		$cards.on('click', '.ecp-reject', function () {
			reject($(this).closest('.ecp-card'));
		});

		$cards.on('click', '.ecp-edit', function () {
			toggleEdit($(this).closest('.ecp-card'), true);
		});

		$cards.on('click', '.ecp-cancel-edit', function () {
			toggleEdit($(this).closest('.ecp-card'), false);
		});

		$cards.on('click', '.ecp-save-edit', function () {
			saveEdit($(this).closest('.ecp-card'));
		});

		// Rendered preview, fetched on demand. Running the_content for every
		// card up front would be slow and would fire other plugins' content
		// filters dozens of times per page load.
		$cards.on('click', '.ecp-render-toggle', function () {
			var $button = $(this);
			var $card = $button.closest('.ecp-card');
			var $target = $card.find('.ecp-rendered');
			var isOpen = 'true' === $button.attr('aria-expanded');

			if (isOpen) {
				$target.prop('hidden', true);
				$button.attr('aria-expanded', 'false').text(t('showRendered'));
				return;
			}

			$button.attr('aria-expanded', 'true').text(t('hideRendered'));
			$target.prop('hidden', false);

			if ($target.data('loaded')) {
				return;
			}

			$target.html('<p class="ecp-muted">' + t('loading') + '</p>');

			post('render_preview', { id: $card.data('id') })
				.done(function (data) {
					$target.data('loaded', true).html(data.html);
				})
				.fail(function (message) {
					$target.html('<p class="is-error"></p>').find('p').text(message);
				});
		});

		$(document).on('click', '.ecp-revert', function () {
			revert($(this));
		});

		$cards.on('click', function (event) {
			// Clicking anywhere on a card makes it the keyboard target.
			var $card = $(event.target).closest('.ecp-card');
			if ($card.length && !$(event.target).is('button, input, a, textarea')) {
				focusCard($card);
			}
		});

		$cards.on('change', '.ecp-card-select', refreshBulkState);

		$('#ecp-select-all').on('change', function () {
			$('.ecp-card-select').prop('checked', $(this).prop('checked'));
			refreshBulkState();
		});

		$('#ecp-bulk-approve').on('click', function () {
			runBulk('approve', selectedIds());
		});

		$('#ecp-bulk-reject').on('click', function () {
			runBulk('reject', selectedIds());
		});

		$('#ecp-approve-safe').on('click', function () {
			var ids = $('.ecp-card[data-risk="safe"]').map(function () {
				return $(this).data('id');
			}).get();

			runBulk('approve', ids);
		});

		// Scan buttons appear on three screens.
		$(document).on('click', '#ecp-run-scan', function () {
			var $button = $(this);
			var $progress = $('.ecp-scan-progress').first();

			$button.data('label', $button.text());
			$button.prop('disabled', true).text(t('scanning'));
			$progress.removeClass('is-error').text('');

			runScan(0, $button, $progress);
		});

		// Analyze one page from the opportunities table.
		$(document).on('click', '.ecp-analyze', function () {
			var $button = $(this);
			var $status = $button.closest('td').find('.ecp-row-status');

			$button.prop('disabled', true);
			$status.removeClass('is-error').text(t('analyzing'));

			post('analyze', { post_id: $button.data('post') })
				.done(function (data) {
					$status.text(data.message);

					if (data.count > 0 && data.redirect) {
						window.setTimeout(function () {
							window.location.href = data.redirect;
						}, 1200);
					} else {
						$button.prop('disabled', false);
					}
				})
				.fail(function (message) {
					$status.addClass('is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		// Content gap analysis — costs an API call, so it is explicit.
		$(document).on('click', '.ecp-analyze-gaps', function () {
			var $button = $(this);
			var $status = $button.closest('td').find('.ecp-row-status');

			$button.prop('disabled', true);
			$status.removeClass('is-error').text(t('findingGaps'));

			post('analyze_gaps', { post_id: $button.data('post') })
				.done(function (data) {
					$status.text(data.message);

					if (data.reload) {
						window.setTimeout(function () {
							window.location.reload();
						}, 2000);
					} else {
						$button.prop('disabled', false);
					}
				})
				.fail(function (message) {
					$status.addClass('is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		// Answering a question the agent refused to invent an answer for.
		$(document).on('click', '.ecp-save-answer', function () {
			var $button = $(this);
			var $field = $button.siblings('.ecp-answer-field');
			var $status = $button.siblings('.ecp-answer-status');
			var answer = String($field.val() || '').replace(/^\s+|\s+$/g, '');

			if (!answer) {
				$status.attr('class', 'ecp-answer-status is-error').text(t('answerEmpty'));
				return;
			}

			$button.prop('disabled', true);
			$status.attr('class', 'ecp-answer-status').text(t('saving'));

			post('answer_question', {
				post_id: $field.data('post'),
				question: $field.data('question'),
				answer: answer
			})
				.done(function (data) {
					$status.attr('class', 'ecp-answer-status is-ok').text(data.message);
					$field.prop('disabled', true);
					$button.remove();
				})
				.fail(function (message) {
					$status.attr('class', 'ecp-answer-status is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		// Find existing pages that should link to an orphan. Free — no AI.
		$(document).on('click', '.ecp-build-links', function () {
			var $button = $(this);
			var $status = $button.closest('td').find('.ecp-row-status');

			$button.prop('disabled', true);
			$status.removeClass('is-error').text(t('findingLinks'));

			post('build_links', { post_id: $button.data('post') })
				.done(function (data) {
					$status.text(data.message);

					if (data.redirect) {
						window.setTimeout(function () {
							window.location.href = data.redirect;
						}, 1500);
					}
				})
				.fail(function (message) {
					$status.addClass('is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		$(document).on('click', '.ecp-dismiss', function () {
			var $link = $(this);
			var $row = $link.closest('tr');

			post('dismiss', { post_id: $link.data('post') })
				.done(function () {
					$row.fadeOut(200);
				})
				.fail(function (message) {
					$row.find('.ecp-row-status').addClass('is-error').text(message);
				});
		});

		// Settings-screen helpers.
		$('#ecp-test-provider').on('click', function () {
			var $button = $(this);
			var $result = $('#ecp-test-result');
			var $key = $('#ecp_api_key');
			var payload = {};

			// Test the credentials currently in the form. Without this the
			// button checks the last-saved key, which is empty on the first
			// visit and reports "no API key" the moment you paste one in.
			if ($key.length) {
				// Native trim, not $.trim — the jQuery helper is deprecated
				// and gone in jQuery 4, and a fatal here would look exactly
				// like the bug this code exists to fix.
				var typed = String($key.val() || '').replace(/^\s+|\s+$/g, '');

				if (typed && !/^[•*]+$/.test(typed)) {
					payload.api_key = typed;
				}
			}

			if ($('#ecp_provider').length) {
				payload.provider = $('#ecp_provider').val();
			}

			if ($('#ecp_model').length) {
				payload.model = $('#ecp_model').val();
			}

			$button.prop('disabled', true);
			$result.attr('class', '').text(t('testing'));

			post('test_provider', payload)
				.done(function (data) {
					$result.attr('class', 'is-ok').text(data.message);
				})
				.fail(function (message) {
					$result.attr('class', 'is-error').text(message);
				})
				.always(function () {
					$button.prop('disabled', false);
				});
		});

		$('#ecp-sync-search').on('click', function () {
			var $button = $(this);
			var $result = $('#ecp-sync-result');

			$button.data('label', $button.text()).prop('disabled', true).text(t('syncing'));
			$result.attr('class', '').text('');

			post('sync_search')
				.done(function (data) {
					$result.attr('class', 'is-ok').text(data.message);

					if (data.reload) {
						window.setTimeout(function () {
							window.location.reload();
						}, 1500);
					}
				})
				.fail(function (message) {
					$result.attr('class', 'is-error').text(message);
					$button.prop('disabled', false).text($button.data('label'));
				});
		});

		$('#ecp-repair-search').on('click', function () {
			var $button = $(this);
			var $result = $('#ecp-sync-result');

			$button.data('label', $button.text()).prop('disabled', true).text(t('syncing'));
			$result.attr('class', '').text('');

			post('repair_search')
				.done(function (data) {
					$result.attr('class', 'is-ok').text(data.message);

					if (data.reload) {
						window.setTimeout(function () {
							window.location.reload();
						}, 1500);
					}
				})
				.fail(function (message) {
					$result.attr('class', 'is-error').text(message);
					$button.prop('disabled', false).text($button.data('label'));
				});
		});

		// "Fix this" buttons beside a failing check run the action themselves
		// rather than clicking the control further up the page. Delegating to
		// another button meant the fix silently did nothing whenever that
		// button happened not to be rendered.
		$(document).on('click', '.ecp-fix', function () {
			var $button = $(this);
			var $result = $button.siblings('.ecp-fix-result').first();

			$button.data('label', $button.text()).prop('disabled', true).text(t('syncing'));
			$result.attr('class', 'ecp-fix-result').text('');

			post($button.data('action'))
				.done(function (data) {
					$result.attr('class', 'ecp-fix-result is-ok').text(data.message);

					if (data.reload) {
						window.setTimeout(function () {
							window.location.reload();
						}, 1500);
					}
				})
				.fail(function (message) {
					$result.attr('class', 'ecp-fix-result is-error').text(message);
					$button.prop('disabled', false).text($button.data('label'));
				});
		});

		$('#ecp-save-sitekit-user').on('click', function () {
			var $button = $(this);
			var $result = $('#ecp-sitekit-user-result');
			var userId = $('#ecp-sitekit-user').val();

			if (!userId || userId === '0') {
				$result.attr('class', 'is-error').text(t('pickAccount'));
				return;
			}

			$button.prop('disabled', true);
			$result.attr('class', '').text(t('testing'));

			post('set_sitekit_user', { user_id: userId })
				.done(function (data) {
					$result.attr('class', 'is-ok').text(data.message);

					if (data.reload) {
						window.setTimeout(function () {
							window.location.reload();
						}, 1500);
					}
				})
				.fail(function (message) {
					$result.attr('class', 'is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		// Build Improvement Plan: one click from "here is what matters" to
		// changes waiting in the review queue. Runs the normal analysis and
		// follows its redirect there.
		$(document).on('click', '.ecp-build-plan', function () {
			var $button = $(this);
			var $status = $button.closest('.ecp-priority-card').find('.ecp-priority-status');

			$button.prop('disabled', true);
			$status.attr('class', 'ecp-priority-status').text(t('analyzing'));

			post('analyze', { post_id: $button.data('post') })
				.done(function (data) {
					$status.text(data.message);

					if (data.count > 0 && data.redirect) {
						window.setTimeout(function () {
							window.location.href = data.redirect;
						}, 1200);
					} else {
						$button.prop('disabled', false);
					}
				})
				.fail(function (message) {
					$status.attr('class', 'ecp-priority-status is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		// Today's Priority card: postpone and dismiss act on the underlying
		// opportunity through the existing endpoints, then retire the card.
		$(document).on('click', '.ecp-priority-snooze, .ecp-priority-dismiss', function () {
			var $button = $(this);
			var $card = $button.closest('.ecp-priority-card');
			var $status = $card.find('.ecp-priority-status');
			var snooze = $button.hasClass('ecp-priority-snooze');
			var payload = { post_id: $button.data('post') };

			if (snooze) {
				payload.days = 7;
			}

			$card.find('button').prop('disabled', true);

			post(snooze ? 'snooze' : 'dismiss', payload)
				.done(function () {
					$card.fadeOut(200);
				})
				.fail(function (message) {
					$status.attr('class', 'ecp-priority-status is-error').text(message);
					$card.find('button').prop('disabled', false);
				});
		});

		// Growth Roadmap: every button is a decision on one step. A decision
		// re-sequences the whole plan, so the page reloads to show the new
		// order rather than pretending the local row is the only thing that
		// changed.
		$(document).on('click', '.ecp-roadmap-act', function () {
			var $button = $(this);
			var $step = $button.closest('.ecp-roadmap-step');
			var $status = $step.find('.ecp-row-status');

			$step.find('button').prop('disabled', true);
			$status.text(t('saving'));

			post('roadmap_action', { id: $button.data('id'), act: $button.data('act') })
				.done(function (data) {
					$status.text(data.message);
					window.setTimeout(function () {
						window.location.reload();
					}, 800);
				})
				.fail(function (message) {
					$status.text(message);
					$step.find('button').prop('disabled', false);
				});
		});

		$('#ecp-rebuild-roadmap').on('click', function () {
			var $button = $(this);
			var $status = $('.ecp-roadmap-rebuild-status');

			$button.prop('disabled', true);
			$status.text(t('saving'));

			post('rebuild_roadmap')
				.done(function (data) {
					$status.text(data.message);
					window.setTimeout(function () {
						window.location.reload();
					}, 800);
				})
				.fail(function (message) {
					$status.text(message);
					$button.prop('disabled', false);
				});
		});

		// Help tips: hover and focus are CSS; click or tap pins one open,
		// clicking anywhere else closes it. One open at a time.
		$(document).on('click', '.ecp-help', function (event) {
			event.stopPropagation();

			var $tip = $(this);
			var open = $tip.hasClass('is-open');

			$('.ecp-help.is-open').removeClass('is-open').attr('aria-expanded', 'false');

			if (!open) {
				$tip.addClass('is-open').attr('aria-expanded', 'true');
			}
		});

		$(document).on('click', function () {
			$('.ecp-help.is-open').removeClass('is-open').attr('aria-expanded', 'false');
		});

		// The long AI builds (map, brief, draft) can outlive what a shared
		// host allows one request: the gateway cuts the connection while
		// PHP finishes the job and stores the result. So a failed build
		// request is not treated as a failure — the page snapshots the
		// stored state before starting and, when the request dies, polls
		// until the stored state changes. Only silence for three minutes
		// is reported as a real problem.
		function watchLongJob(statusParams, initial, $status, $buttons) {
			var tries = 0;

			$status.text(t('stillWorking'));

			var timer = window.setInterval(function () {
				tries++;

				post('plan_status', statusParams).done(function (state) {
					if (state.count > initial.count || (state.latest && state.latest > initial.latest)) {
						window.clearInterval(timer);
						$status.text(t('finishedAfterAll'));
						window.setTimeout(function () {
							window.location.reload();
						}, 1000);
					} else if (tries >= 36) {
						window.clearInterval(timer);
						$status.text(t('checkHistory'));
						$buttons.prop('disabled', false);
					}
				});
			}, 5000);
		}

		// Topical Map: grow a map from a seed. The build is a real AI call,
		// so the button locks until the answer comes back, then follows the
		// redirect to the freshly built map.
		$('#ecp-build-map').on('click', function () {
			var $button = $(this);
			var $status = $('.ecp-map-form-status');
			var seed = $.trim($('#ecp-map-seed').val());

			if (!seed) {
				$status.text(t('seedEmpty'));
				return;
			}

			$button.prop('disabled', true);
			$status.text(t('mapping'));

			post('plan_status', { what: 'map', key: seed }).always(function (initial) {
				initial = initial && initial.count !== undefined ? initial : { count: 0, latest: 0 };

				post('build_map', { seed: seed })
					.done(function (data) {
						$status.text(data.message);

						if (data.redirect) {
							window.setTimeout(function () {
								window.location.href = data.redirect;
							}, 1200);
						}
					})
					.fail(function () {
						watchLongJob({ what: 'map', key: seed }, initial, $status, $button);
					});
			});
		});

		// Approve / dismiss / reconsider one topic.
		$(document).on('click', '.ecp-topic-act', function () {
			var $button = $(this);
			var $topic = $button.closest('.ecp-map-topic');
			var $status = $topic.find('.ecp-row-status');

			$topic.find('button').prop('disabled', true);
			$status.text(t('saving'));

			post('topic_action', { id: $button.data('id'), act: $button.data('act') })
				.done(function (data) {
					$status.text(data.message);
					window.setTimeout(function () {
						window.location.reload();
					}, 800);
				})
				.fail(function (message) {
					$status.text(message);
					$topic.find('button').prop('disabled', false);
				});
		});

		// Approve everything still open in a cluster.
		$(document).on('click', '.ecp-cluster-approve', function () {
			var $button = $(this);
			var $status = $button.siblings('.ecp-row-status');

			$button.prop('disabled', true);
			$status.text(t('saving'));

			post('approve_cluster', { seed: $button.data('seed'), parent: $button.data('parent') })
				.done(function (data) {
					$status.text(data.message);
					window.setTimeout(function () {
						window.location.reload();
					}, 800);
				})
				.fail(function (message) {
					$status.text(message);
					$button.prop('disabled', false);
				});
		});

		// DataForSEO: test the saved credentials (free endpoint).
		$('#ecp-test-serp').on('click', function () {
			var $button = $(this);
			var $result = $('#ecp-test-serp-result');

			$button.prop('disabled', true);
			$result.attr('class', '').text(t('testing'));

			post('test_serp')
				.done(function (data) {
					$result.attr('class', 'is-ok').text(data.message);
					$button.prop('disabled', false);
				})
				.fail(function (message) {
					$result.attr('class', 'is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		// Content Plan: write or rewrite a brief (a real AI call), and
		// decide on one. Both reload — brief state changes the whole row.
		$(document).on('click', '.ecp-build-brief', function () {
			var $button = $(this);
			var $status = $button.closest('.ecp-plan-topic').find('.ecp-row-status').first();
			var topicId = $button.data('topic');

			$button.prop('disabled', true);
			$status.text(t('briefing'));

			post('plan_status', { what: 'brief', key: topicId }).always(function (initial) {
				initial = initial && initial.count !== undefined ? initial : { count: 0, latest: 0 };

				post('build_brief', { topic: topicId })
					.done(function (data) {
						$status.text(data.message);
						window.setTimeout(function () {
							window.location.reload();
						}, 1200);
					})
					.fail(function () {
						watchLongJob({ what: 'brief', key: topicId }, initial, $status, $button);
					});
			});
		});

		// Draft the article from an approved brief — the long AI call of
		// the plugin. The result is an unpublished WordPress draft.
		$(document).on('click', '.ecp-draft-article', function () {
			var $button = $(this);
			var $topic = $button.closest('.ecp-plan-topic');
			var $status = $topic.find('.ecp-row-status').first();
			var briefId = $button.data('id');

			$topic.find('button').prop('disabled', true);
			$status.text(t('drafting'));

			post('plan_status', { what: 'draft', key: briefId }).always(function (initial) {
				initial = initial && initial.count !== undefined ? initial : { count: 0, latest: 0 };

				post('draft_article', { id: briefId })
					.done(function (data) {
						$status.text(data.message);
						window.setTimeout(function () {
							window.location.reload();
						}, 1500);
					})
					.fail(function () {
						watchLongJob({ what: 'draft', key: briefId }, initial, $status, $topic.find('button'));
					});
			});
		});

		$(document).on('click', '.ecp-brief-act', function () {
			var $button = $(this);
			var $topic = $button.closest('.ecp-plan-topic');
			var $status = $topic.find('.ecp-row-status').first();

			$topic.find('button').prop('disabled', true);
			$status.text(t('saving'));

			post('brief_action', { id: $button.data('id'), act: $button.data('act') })
				.done(function (data) {
					$status.text(data.message);
					window.setTimeout(function () {
						window.location.reload();
					}, 1000);
				})
				.fail(function (message) {
					$status.text(message);
					$topic.find('button').prop('disabled', false);
				});
		});

		// Knowledge Vault: add a fact from the form.
		$('#ecp-add-fact').on('click', function () {
			var $button = $(this);
			var $status = $('.ecp-vault-form-status');
			var fact = $.trim($('#ecp-fact-text').val());

			if (!fact) {
				$status.text(t('factEmpty'));
				return;
			}

			$button.prop('disabled', true);
			$status.text(t('saving'));

			post('save_fact', {
				fact: fact,
				question: $.trim($('#ecp-fact-question').val()),
				topic: $('#ecp-fact-topic').val()
			})
				.done(function (data) {
					$status.text(data.message);
					window.setTimeout(function () {
						window.location.reload();
					}, 800);
				})
				.fail(function (message) {
					$status.text(message);
					$button.prop('disabled', false);
				});
		});

		// Mine the site's own pages for answers to the open questions.
		$('#ecp-mine-answers').on('click', function () {
			var $button = $(this);
			var $status = $('.ecp-mine-status');

			$button.prop('disabled', true);
			$status.text(t('mining'));

			post('mine_answers')
				.done(function (data) {
					$status.text(data.message);

					if (data.found > 0) {
						window.setTimeout(function () {
							window.location.reload();
						}, 1500);
					} else {
						$button.prop('disabled', false);
					}
				})
				.fail(function (message) {
					$status.text(message);
					$button.prop('disabled', false);
				});
		});

		// Confirm / retire / restore a fact.
		$(document).on('click', '.ecp-fact-act', function () {
			var $button = $(this);
			var $row = $button.closest('tr');
			var $status = $row.find('.ecp-row-status');

			$row.find('button').prop('disabled', true);
			$status.text(t('saving'));

			post('fact_action', { id: $button.data('id'), act: $button.data('act') })
				.done(function (data) {
					$status.text(data.message);
					window.setTimeout(function () {
						window.location.reload();
					}, 800);
				})
				.fail(function (message) {
					$status.text(message);
					$row.find('button').prop('disabled', false);
				});
		});

		// Edit a fact in place: swap the text for a textarea, save on
		// Enter or the save button, cancel on Escape.
		$(document).on('click', '.ecp-fact-edit', function () {
			var $row = $(this).closest('tr');
			var $cell = $row.find('.ecp-fact-cell');
			var $text = $cell.find('.ecp-fact-text');

			if ($cell.data('editing')) {
				return;
			}

			$cell.data('editing', true);

			var $area = $('<textarea class="large-text" rows="2"></textarea>').val($text.text());
			var $save = $('<button type="button" class="button button-small"></button>').text(t('saveEdit'));
			var $cancel = $('<button type="button" class="button-link"></button>').text(t('cancel'));
			var $controls = $('<div class="ecp-fact-edit-controls"></div>').append($save, ' ', $cancel);

			$text.hide().after($area, $controls);
			$area.focus();

			var close = function () {
				$area.remove();
				$controls.remove();
				$text.show();
				$cell.data('editing', false);
			};

			$cancel.on('click', close);
			$area.on('keydown', function (event) {
				if ('Escape' === event.key) {
					close();
				}
			});

			$save.on('click', function () {
				var value = $.trim($area.val());

				if (!value) {
					return;
				}

				$save.prop('disabled', true);

				post('fact_action', { id: $row.data('id'), act: 'edit', fact: value })
					.done(function () {
						window.location.reload();
					})
					.fail(function (message) {
						$row.find('.ecp-row-status').text(message);
						$save.prop('disabled', false);
					});
			});
		});

		$('#ecp-classify-now').on('click', function () {
			var $button = $(this);
			var $result = $('#ecp-classify-result');

			$button.prop('disabled', true);
			$result.attr('class', '').text(t('testing'));

			post('classify_now')
				.done(function (data) {
					$result.attr('class', 'is-ok').text(data.message);
					$button.prop('disabled', false);

					if (data.reload) {
						window.setTimeout(function () {
							window.location.reload();
						}, 1200);
					}
				})
				.fail(function (message) {
					$result.attr('class', 'is-error').text(message);
					$button.prop('disabled', false);
				});
		});

		// Inline topic correction on the Site Intelligence inventory. Click
		// the topic, type, Enter saves, Escape cancels. A saved topic is
		// locked: the classifier never overwrites a human's label.
		$(document).on('click', '.ecp-intel-topic', function () {
			var $span = $(this);

			if ($span.data('editing')) {
				return;
			}

			$span.data('editing', true);

			var current = $span.text().replace(' 🔒', '').trim();
			var $input = $('<input type="text" class="ecp-intel-topic-input">').val('—' === current ? '' : current);

			$span.hide().after($input);
			$input.focus();

			var close = function () {
				$input.remove();
				$span.show().data('editing', false);
			};

			$input.on('keydown', function (e) {
				if ('Escape' === e.key) {
					close();
					return;
				}

				if ('Enter' !== e.key) {
					return;
				}

				e.preventDefault();

				var topic = $input.val().trim();

				if (!topic) {
					close();
					return;
				}

				post('save_topic', { post_id: $span.data('post'), topic: topic })
					.done(function (data) {
						$span.text(data.topic + ' 🔒');
						close();
					})
					.fail(function (message) {
						window.alert(message);
						close();
					});
			});

			$input.on('blur', close);
		});

		$('#ecp-send-digest').on('click', function () {
			var $button = $(this);
			var $result = $('#ecp-digest-result');

			$button.prop('disabled', true);
			$result.attr('class', '').text(t('testing'));

			post('send_digest')
				.done(function (data) {
					$result.attr('class', 'is-ok').text(data.message);
				})
				.fail(function (message) {
					$result.attr('class', 'is-error').text(message);
				})
				.always(function () {
					$button.prop('disabled', false);
				});
		});

		$(document).on('click', '.ecp-enable-autopilot', function () {
			var $button = $(this);

			$button.prop('disabled', true);

			post('enable_autopilot', { type: $button.data('type') })
				.done(function (data) {
					$button.closest('li').html('<span class="ecp-resolved-label">' + data.message + '</span>');
				})
				.fail(function (message) {
					$button.prop('disabled', false);
					$button.closest('li').append('<span class="is-error"> ' + message + '</span>');
				});
		});

		/* --- Clusters ------------------------------------------------ */

		$(document).on('click', '#ecp-detect-clusters', function () {
			var $button = $(this);
			var $progress = $('.ecp-cluster-progress').first();

			$button.data('label', $button.text()).prop('disabled', true).text(t('detecting'));
			$progress.removeClass('is-error').text('');

			post('detect_clusters')
				.done(function (data) {
					$progress.text(data.message);

					if (data.reload) {
						window.setTimeout(function () {
							window.location.reload();
						}, 1200);
					} else {
						$button.prop('disabled', false).text($button.data('label'));
					}
				})
				.fail(function (message) {
					$progress.addClass('is-error').text(message);
					$button.prop('disabled', false).text($button.data('label'));
				});
		});

		$(document).on('click', '.ecp-analyze-cluster', function () {
			var $button = $(this);
			var $card = $button.closest('.ecp-cluster-card');

			$card.addClass('is-busy');
			$card.find('button').prop('disabled', true);
			setStatus($card, t('analyzing'));

			post('analyze_cluster', { cluster_id: $button.data('cluster') })
				.done(function (data) {
					setStatus($card, data.message);

					window.setTimeout(function () {
						window.location.reload();
					}, 1200);
				})
				.fail(function (message) {
					$card.removeClass('is-busy');
					$card.find('button').prop('disabled', false);
					setStatus($card, message, true);
				});
		});

		$(document).on('click', '.ecp-dismiss-cluster, .ecp-resolve-cluster', function () {
			var $button = $(this);
			var $card = $button.closest('.ecp-cluster-card');
			var status = $button.hasClass('ecp-dismiss-cluster') ? 'dismissed' : 'resolved';

			$card.find('button').prop('disabled', true);

			post('cluster_status', { cluster_id: $button.data('cluster'), status: status })
				.done(function (data) {
					setStatus($card, data.message);
					$card.addClass('is-resolved');

					window.setTimeout(function () {
						$card.slideUp(200, function () {
							$card.remove();
						});
					}, 900);
				})
				.fail(function (message) {
					$card.find('button').prop('disabled', false);
					setStatus($card, message, true);
				});
		});

		// Warn before losing an in-progress edit.
		$(window).on('beforeunload', function () {
			if ($('.ecp-card.is-editing').length) {
				return t('unsavedEdit');
			}
		});
	});
})(jQuery);
