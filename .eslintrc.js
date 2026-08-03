module.exports = {
	extends: [
		'@nextcloud/eslint-config',
	],
	rules: {
		'no-console': 'warn',
	},
	overrides: [
		{
			// The E2E harness is Node and Cypress, not the app bundle: CommonJS,
			// `require` without extensions, and prose docblocks rather than the
			// per-parameter JSDoc the Nextcloud config asks of exported frontend
			// helpers - most of these functions take one destructured options bag,
			// and `@param root0.user` documents nothing.
			files: ['cypress/**/*.js', 'cypress.config.js'],
			env: {
				node: true,
				mocha: true,
			},
			// Declared by hand rather than pulling in eslint-plugin-cypress for four
			// names.
			globals: {
				cy: 'readonly',
				Cypress: 'readonly',
				expect: 'readonly',
				assert: 'readonly',
			},
			rules: {
				// `expect(x).to.be.true` is an expression by construction.
				'no-unused-expressions': 'off',
				'jsdoc/require-param': 'off',
				'jsdoc/require-param-type': 'off',
				'jsdoc/require-param-description': 'off',
				'import/extensions': 'off',
				'n/no-unpublished-require': 'off',
			},
		},
	],
}
