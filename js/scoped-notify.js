document.onreadystatechange = () => {
	if (document.readyState === "complete") {
		scopedNotify();
	}
};

scopedNotify = () => {
	document.querySelectorAll(".scoped-notify-options").forEach((radiogroup) => {
		radiogroup.addEventListener("change", (event) => {
			const scope = event.currentTarget.dataset.scope;
			const blogId = event.currentTarget.dataset.blogId;
			const postId = event.currentTarget.dataset.postId;
			const value = event.target.value;

			console.log(`Scoped notify hallo`, scope, blogId, postId, value);
			request = $.ajax({
				type:		"POST",
				url:		data.rest.endpoint,
				timeout:	data.rest.timeout,
				beforeSend: function (xhr) {
            		xhr.setRequestHeader('X-WP-Nonce', data.rest.nonce);
        		},
				data: {
					scope,
					blogId,
					postId,
					value
				},
				success: function (response) {
					console.log(response);
				},
				error: function (request, textStatus, errorThrown) {
					console.log(request);
				},
			});
		})
	})
};
