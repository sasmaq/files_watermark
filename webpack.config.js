const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')
const MiniCssExtractPlugin = require('mini-css-extract-plugin')

// Resolve to absolute paths so they work in fully-specified ESM contexts
// (@nextcloud/files, axios and webdav ship as ESM and reject bare requests).
const bufferPolyfill = require.resolve('buffer/')
const processPolyfill = require.resolve('process/browser')

module.exports = {
    entry: {
        'admin-settings': path.resolve(__dirname, 'src/main-admin.js'),
        'files':          path.resolve(__dirname, 'src/main-files.js'),
    },
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: '[name].js',
        clean: true,
    },
    resolve: {
        extensions: ['.js', '.vue'],
        alias: {
            '@': path.resolve(__dirname, 'src'),
        },
        fallback: {
            // @nextcloud/files (bundled into files.js) uses Buffer/process at
            // runtime; polyfill them for the browser instead of stubbing out.
            buffer: bufferPolyfill,
            process: processPolyfill,
        },
    },
    module: {
        rules: [
            {
                test: /\.vue$/,
                loader: 'vue-loader',
            },
            {
                test: /\.js$/,
                loader: 'babel-loader',
                exclude: /node_modules/,
            },
            {
                test: /\.scss$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    'css-loader',
                    'sass-loader',
                ],
            },
            {
                test: /\.css$/,
                use: [MiniCssExtractPlugin.loader, 'css-loader'],
            },
        ],
    },
    plugins: [
        new VueLoaderPlugin(),
        // Output CSS to the app's css/ dir so Util::addStyle(app, name) — which
        // resolves to css/<name>.css — can find it (JS goes to js/).
        new MiniCssExtractPlugin({ filename: '../css/[name].css' }),
        // Expose Buffer/process as globals for deps that assume a Node env.
        new webpack.ProvidePlugin({
            Buffer: [bufferPolyfill, 'Buffer'],
            process: processPolyfill,
        }),
    ],
}
