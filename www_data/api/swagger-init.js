// Starts Swagger UI on the spec the API serves. A file of its own: the CSP is script-src 'self', so no inline script.
window.onload = function() {
	window.ui = SwaggerUIBundle({
		url: "moncycle_app_open_api.yaml",
		queryConfigEnabled: false,
		dom_id: '#swagger-ui',
		deepLinking: true,
		presets: [
			SwaggerUIBundle.presets.apis,
			SwaggerUIStandalonePreset,
		],
		plugins: [
			SwaggerUIBundle.plugins.DownloadUrl
		]
	});
}
