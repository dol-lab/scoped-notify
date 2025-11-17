/**
 * Handles user interactions for notification settings within different scopes (network, site, post).
 * It sends asynchronous requests to update settings and displays any resulting conflicts or exceptions.
 * Assumes a global `data` object is available with `data.rest.endpoint` and `data.rest.nonce`.
 */
document.addEventListener('DOMContentLoaded', () => {
	initializeScopedNotify();
});

/**
 * Shows a callout with specified content.
 *
 * @param {HTMLElement} trigger The element that triggers the callout.
 * @param {string} html The HTML content to display.
 * @param {boolean} [isError=false] True to apply error styling.
 */
const showCallout = (trigger, html, isError = false) => {
	const callout = trigger?.closest('.scoped-notify-options')?.querySelector('.callout.warning');
	if (!callout) return;

	// Find content area and set its HTML if it exists
	const content = callout.querySelector('.callout-content');
	if (content) content.innerHTML = html;

	// Toggle error class and show the callout
	callout.classList.toggle('alert', isError);
	callout.style.display = 'block';
};

/**
 * Displays a warning callout with a list of settings that oppose the user's new selection.
 * For example, if a user turns notifications on globally, this will list any specific sites or posts
 * where notifications are explicitly turned off.
 *
 * @param {HTMLElement} triggerElement The element that initiated the change (e.g., a radiogroup).
 * @param {Array<Object>} opposingSettings An array of objects, each representing a conflicting setting.
 * @param {string} selectedValue The new value selected by the user (e.g., 'activate-notifications').
 */
const displayOpposingSettings = (triggerElement, opposingSettings, selectedValue) => {
	// If there are no opposing settings, hide the callout (if present) and return.
	if (!Array.isArray(opposingSettings) || opposingSettings.length === 0) {
		const container = triggerElement ? triggerElement.closest('.scoped-notify-options') : null;
		if (container) {
			const callout = container.querySelector('.callout.warning');
			if (callout) {
				callout.style.display = 'none';
				callout.classList.remove('callout-error');
			}
		}
		return;
	}

	// Determine the introductory message based on whether the user is activating or deactivating notifications.
	const isDeactivating = selectedValue.includes('deactivate') || selectedValue.includes('no-notifications');
	const introMessage = isDeactivating ? ScopedNotify.i18n.profile_notifications_off : ScopedNotify.i18n.profile_notifications_on;

	// Build the list of opposing settings as HTML.
	const itemsHtml = opposingSettings.map(setting => {
		const safeName = setting.name || '';
		const safeType = setting.type || '';
		const href = setting.link || '#';
		return `<li><a href="${href}">${safeName} (${safeType})</a></li>`;
	}).join('');

	const fullHtml = `<p>${introMessage}</p><ul class="ul-disc">${itemsHtml}</ul>`;

	showCallout(triggerElement, fullHtml, false);
};

/**
 * Sends a POST request to the WordPress REST API to update a notification setting.
 *
 * @param {Object} settings The notification settings to be saved.
 * @param {string} settings.scope The scope of the setting (e.g., 'network', 'blog', 'post').
 * @param {string} settings.blogId The ID of the blog/site.
 * @param {string} settings.postId The ID of the post.
 * @param {string} settings.value The new setting value.
 * @param {boolean} [reloadOnSuccess=false] If true, the page will reload after a successful request.
 * @param {HTMLElement|null} [triggerElement=null] The element that triggered the request, used to anchor the display of opposing settings.
 */
const sendScopedNotifyRequest = async (settings, reloadOnSuccess = false, triggerElement = null) => {
	try {
		const response = await fetch(ScopedNotify.rest.endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ScopedNotify.rest.nonce,
			},
			body: JSON.stringify(settings),
		});

		if (!response.ok) {
			throw new Error(`HTTP error! Status: ${response.status}`);
		}

		const responseData = await response.json();
		const opposing = responseData.opposing_settings;

		// If there are conflicting settings, display them.
		if (triggerElement && Array.isArray(opposing)) {
			displayOpposingSettings(triggerElement, opposing, settings.value);
		}

		if (reloadOnSuccess) {
			location.reload();
		}
	} catch (error) {
		// On failure, show an error in the callout if possible; otherwise reload the page.
		if (triggerElement) {
			const message = (error && error.message) ? error.message : ScopedNotify.i18n.request_failed || 'An error occurred';
			const errorHtml = `<p><strong>Error:</strong> ${message}</p>`;
			// showCallout is defined above displayOpposingSettings
			showCallout(triggerElement, errorHtml, true);
		} else {
			location.reload();
		}
	}
};

/**
 * Sends ntfy.sh configuration to the server
 *
 * @param {number} blogId The blog ID
 * @param {string} ntfyTopic The ntfy.sh topic
 * @param {boolean} enabled Whether ntfy is enabled
 * @param {HTMLElement} statusElement The status display element
 */
const saveNtfyConfig = async (blogId, ntfyTopic, enabled, statusElement) => {
	try {
		// Validate topic format
		if (ntfyTopic && !/^[a-zA-Z0-9_-]+$/.test(ntfyTopic)) {
			throw new Error('Invalid topic format. Only alphanumeric, hyphens, and underscores allowed.');
		}

		const endpoint = ScopedNotify.rest.endpoint.replace('/settings', '/ntfy-config');
		const response = await fetch(endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ScopedNotify.rest.nonce,
			},
			body: JSON.stringify({
				blog_id: blogId,
				ntfy_topic: ntfyTopic,
				enabled: enabled,
			}),
		});

		if (!response.ok) {
			throw new Error(`HTTP error! Status: ${response.status}`);
		}

		const responseData = await response.json();

		if (responseData.status === 'success') {
			// Show success message
			if (statusElement) {
				statusElement.innerHTML = '✓ Configuration saved successfully';
				statusElement.style.backgroundColor = '#d4edda';
				statusElement.style.color = '#155724';
				statusElement.style.display = 'block';
				setTimeout(() => {
					statusElement.style.display = 'none';
				}, 3000);
			}
		} else {
			throw new Error(responseData.message || 'Failed to save configuration');
		}
	} catch (error) {
		// Show error message
		if (statusElement) {
			statusElement.innerHTML = '✗ ' + (error.message || 'Error saving configuration');
			statusElement.style.backgroundColor = '#f8d7da';
			statusElement.style.color = '#721c24';
			statusElement.style.display = 'block';
		}
	}
};

/**
 * Initializes all notification setting controls on the page by attaching event listeners.
 */
const initializeScopedNotify = () => {
	// Listener for radio button groups that control notification defaults.
	const radiogroups = document.querySelectorAll(".js-scoped-notify-radiogroup");
	radiogroups.forEach((radiogroup) => {
		radiogroup.addEventListener("change", (event) => {
			const { scope, blogId, postId } = event.currentTarget.dataset;
			const value = event.target.value;
			const shouldReload = scope !== 'network';
			sendScopedNotifyRequest({ scope, blogId, postId, value }, shouldReload, event.currentTarget);
		});
	});

	// Listener for individual comment notification toggles (checkboxes).
	const toggles = document.querySelectorAll(".js-scoped-notify-comment-toggle");
	toggles.forEach((toggle) => {
		toggle.addEventListener("change", (event) => {
			const { scope, blogId, postId } = event.target.dataset;
			const value = event.target.checked ? "activate-notifications" : "deactivate-notifications";
			sendScopedNotifyRequest({ scope, blogId, postId, value }, false, event.currentTarget);
		});
	});

	// Listener for ntfy.sh topic inputs
	const ntfyTopicInputs = document.querySelectorAll('.js-scoped-notify-ntfy-topic');
	ntfyTopicInputs.forEach((input) => {
		let saveTimeout;
		input.addEventListener('input', (event) => {
			const blogId = event.target.dataset.blogId;
			const ntfyTopic = event.target.value;
			const container = event.target.closest('.scoped-notify-options--ntfy') || event.target.closest('div');
			const enabledToggle = container?.querySelector('.js-scoped-notify-ntfy-enabled');
			const statusElement = container?.querySelector('.js-scoped-notify-ntfy-status');
			const enabled = enabledToggle ? enabledToggle.checked : true;

			// Debounce the save operation
			clearTimeout(saveTimeout);
			saveTimeout = setTimeout(() => {
				if (ntfyTopic) {
					saveNtfyConfig(blogId, ntfyTopic, enabled, statusElement);
				}
			}, 1000);
		});
	});

	// Listener for ntfy.sh enabled toggles
	const ntfyEnabledToggles = document.querySelectorAll('.js-scoped-notify-ntfy-enabled');
	ntfyEnabledToggles.forEach((toggle) => {
		toggle.addEventListener('change', (event) => {
			const blogId = event.target.dataset.blogId;
			const enabled = event.target.checked;
			const container = event.target.closest('.scoped-notify-options--ntfy') || event.target.closest('div');
			const topicInput = container?.querySelector('.js-scoped-notify-ntfy-topic');
			const statusElement = container?.querySelector('.js-scoped-notify-ntfy-status');
			const ntfyTopic = topicInput ? topicInput.value : '';

			if (ntfyTopic) {
				saveNtfyConfig(blogId, ntfyTopic, enabled, statusElement);
			} else if (enabled) {
				// If trying to enable without a topic, show error
				if (statusElement) {
					statusElement.innerHTML = '✗ Please enter a topic name first';
					statusElement.style.backgroundColor = '#f8d7da';
					statusElement.style.color = '#721c24';
					statusElement.style.display = 'block';
				}
				// Uncheck the toggle
				event.target.checked = false;
			}
		});
	});
};
