document.onreadystatechange = () => {
	if (document.readyState === "complete") {
		scopedNotify();
	}
};

scopedNotify = () => {
	document.querySelectorAll(".js-scoped-notify-radiogroup").forEach((radiogroup) => {
		radiogroup.addEventListener("change", (event) => {
			const scope		= event.currentTarget.dataset.scope;
			const blogId	= event.currentTarget.dataset.blogId;
			const postId	= event.currentTarget.dataset.postId;
			const value		= event.target.value;
			console.debug(`scoped-notify settings request at endpoint ${data.rest.endpoint}: scope ${scope}, blogId ${blogId}, postId ${postId}, value ${value}`);
			request = $.ajax({
				type:			"POST",
				contentType:	"application/json",
				url:			data.rest.endpoint,
				timeout:		data.rest.timeout,
				beforeSend: 	function (xhr) {
            		xhr.setRequestHeader('X-WP-Nonce', data.rest.nonce);
        		},
				data: 			JSON.stringify({
					scope,
					blogId,
					postId,
					value
				}),
				success: function (response) {
					// console.log(response);
					// reload, because this request influences the state of other notification components
					// which might be on the same page. easiest to keep in sync with a page reload
					location.reload();
				},
				error: function (request, textStatus, errorThrown) {
					// console.log(request);
					// s.a.
					location.reload();
				},
			});
		});
	});

	document.querySelectorAll(".js-scoped-notify-comment-toggle").forEach((toggle) => {
		toggle.addEventListener("change", (event) => {
			const scope		= event.target.dataset.scope;
			const blogId	= event.target.dataset.blogId;
			const postId	= event.target.dataset.postId;
			const value		= event.target.checked ? 	"activate-notifications" : "deactivate-notifications";
			console.debug(`scoped-notify settings request at endpoint ${data.rest.endpoint}: scope ${scope}, blogId ${blogId}, postId ${postId}, value ${value}`);
			request = $.ajax({
				type:			"POST",
				contentType:	"application/json",
				url:			data.rest.endpoint,
				timeout:		data.rest.timeout,
				beforeSend: 	function (xhr) {
            		xhr.setRequestHeader('X-WP-Nonce', data.rest.nonce);
        		},
				data: 			JSON.stringify({
					scope,
					blogId,
					postId,
					value
				}),
				success: function (response) {
					// no reload necessary as because the state of the toggle is independent of other components
					// console.log(response);
				},
				error: function (request, textStatus, errorThrown) {
					// console.log(request);
				},
			});
		});
	});
};
