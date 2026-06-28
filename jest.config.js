module.exports = {
    testEnvironment: 'jsdom',
    transform: {
        '^.+\\.vue$': '@vue/vue3-jest',
        '^.+\\.js$': 'babel-jest',
    },
    moduleFileExtensions: ['js', 'vue', 'json'],
    moduleNameMapper: {
        '^@/(.*)$': '<rootDir>/src/$1',
        '@nextcloud/axios': '<rootDir>/src/tests/__mocks__/@nextcloud/axios.js',
        '@nextcloud/router': '<rootDir>/src/tests/__mocks__/@nextcloud/router.js',
        '@nextcloud/l10n': '<rootDir>/src/tests/__mocks__/@nextcloud/l10n.js',
        // Each @nextcloud/vue component resolves to its own stub so default
        // imports render the right element (e.g. NcButton → real <button>).
        // v9 import style: @nextcloud/vue/components/NcButton
        '^@nextcloud/vue/components/(.*)$': '<rootDir>/src/tests/__mocks__/@nextcloud/vue-components/$1.js',
        '^@nextcloud/vue$': '<rootDir>/src/tests/__mocks__/@nextcloud/vue.js',
    },
    testMatch: ['**/src/tests/**/*.spec.js'],
    collectCoverage: false,
}
