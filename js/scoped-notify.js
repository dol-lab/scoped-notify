/**
 * Handles user interactions for notification settings within different scopes (network, site, post).
 * It sends asynchronous requests to update settings and displays any resulting conflicts or exceptions.
 * Assumes a global `data` object is available with `data.rest.endpoint` and `data.rest.nonce`.
 */
document.addEventListener('DOMContentLoaded', () => {
	initializeScopedNotify();
});

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
	const container = triggerElement.closest('.scoped-notify-options');
	if (!container) return;

	const callout = container.querySelector('.callout.warning');
	if (!callout) return;

	const calloutContent = callout.querySelector('.callout-content');
	if (!calloutContent) return;

	// Determine the introductory message based on whether the user is activating or deactivating notifications.
	const isDeactivating = selectedValue.includes('deactivate') || selectedValue.includes('no-notifications');
	const introMessage = isDeactivating ? ScopedNotify.i18n.profile_notifications_off : ScopedNotify.i18n.profile_notifications_on;

	// Clear previous content and build the new list of opposing settings.
	calloutContent.innerHTML = '';

	const introParagraph = document.createElement('p');
	introParagraph.innerHTML = introMessage; // Use innerHTML to render the <strong> tag.

	const list = document.createElement('ul');
	list.className = 'ul-disc';

	opposingSettings.forEach(setting => {
		const listItem = document.createElement('li');
		const link = document.createElement('a');
		link.href = setting.link;
		link.textContent = `${setting.name} (${setting.type})`;
		listItem.appendChild(link);
		list.appendChild(listItem);
	});

	calloutContent.appendChild(introParagraph);
	calloutContent.appendChild(list);
	callout.style.display = 'block';
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
		if (triggerElement && Array.isArray(opposing) && opposing.length > 0) {
			displayOpposingSettings(triggerElement, opposing, settings.value);
		}

		if (reloadOnSuccess) {
			location.reload();
		}
	} catch (error) {
		// On failure, reload the page to show the last-known correct state.
		location.reload();
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
};
