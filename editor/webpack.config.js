const CopyPlugin = require('copy-webpack-plugin'); // see https://github.com/webpack-contrib/copy-webpack-plugin
module.exports = {
	entry: "./src/inline-editor.js",
	output: {
		filename: "../../public/js/inline-editor-bundle.js"
	},
	mode: "development",
	plugins: [
		new CopyPlugin([
			{ from: './style', to: '../../public/css/inline-editor-bundle' }
	    ])
	]
}