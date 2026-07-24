// Jenkins equivalent of .github/workflows/php.yml and .github/workflows/nodejs.yml.
//
// The GitHub triggers (push to main, pull_request) map to Multibranch Pipeline
// branch/PR discovery, so there is no `triggers` block here — configure the job
// as a Multibranch Pipeline (or GitHub Branch Source) and it fires on the same
// events.

pipeline {
	agent none

	options {
		// Mirrors `concurrency: cancel-in-progress: true` — a new build on the
		// same branch aborts the one still running.
		disableConcurrentBuilds(abortPrevious: true)
		timestamps()
		buildDiscarder(logRotator(numToKeepStr: '30'))
		timeout(time: 30, unit: 'MINUTES')
	}

	environment {
		// Stand-in for actions/cache and setup-node's `cache: npm`. Each docker
		// agent gets its own workspace, so these stay per-stage and survive
		// between builds through Jenkins' workspace reuse on the same node.
		COMPOSER_CACHE_DIR = "${env.WORKSPACE}/.composer-cache"
		npm_config_cache = "${env.WORKSPACE}/.npm-cache"
		COMPOSER_NO_INTERACTION = '1'
		CI = 'true'
	}

	stages {
		stage('Checks') {
			// `fail-fast: false` — one failing matrix leg does not cancel the rest.
			failFast false

			parallel {
				stage('Syntax (PHP 8.2)') {
					agent {
						dockerfile {
							dir 'ci'
							filename 'php.Dockerfile'
							additionalBuildArgs '--build-arg PHP_VERSION=8.2'
							label 'docker'
						}
					}
					steps {
						// `php -l` needs no dependencies, so this stage skips composer
						// install entirely and stays the first signal on a pull request.
						sh 'composer lint'
					}
				}

				stage('Syntax (PHP 8.3)') {
					agent {
						dockerfile {
							dir 'ci'
							filename 'php.Dockerfile'
							additionalBuildArgs '--build-arg PHP_VERSION=8.3'
							label 'docker'
						}
					}
					steps {
						sh 'composer lint'
					}
				}

				stage('Coding standard') {
					// The Nextcloud coding standard is version-independent, so one PHP
					// version is enough — unlike the syntax stages, which are what catch
					// version-specific parse errors. Pinned to the lowest supported version.
					agent {
						dockerfile {
							dir 'ci'
							filename 'php.Dockerfile'
							additionalBuildArgs '--build-arg PHP_VERSION=8.2'
							label 'docker'
						}
					}
					steps {
						sh 'composer install --prefer-dist --no-progress --no-interaction'
						sh 'composer cs:check'
					}
				}

				stage('PHPUnit (PHP 8.2)') {
					agent {
						dockerfile {
							dir 'ci'
							filename 'php.Dockerfile'
							additionalBuildArgs '--build-arg PHP_VERSION=8.2'
							label 'docker'
						}
					}
					steps {
						sh 'composer install --prefer-dist --no-progress --no-interaction'
						sh 'vendor/bin/phpunit --colors=always --log-junit build/phpunit-8.2.xml'
					}
					post {
						always {
							junit allowEmptyResults: true, testResults: 'build/phpunit-8.2.xml'
						}
					}
				}

				stage('PHPUnit (PHP 8.3)') {
					agent {
						dockerfile {
							dir 'ci'
							filename 'php.Dockerfile'
							additionalBuildArgs '--build-arg PHP_VERSION=8.3'
							label 'docker'
						}
					}
					steps {
						sh 'composer install --prefer-dist --no-progress --no-interaction'
						sh 'vendor/bin/phpunit --colors=always --log-junit build/phpunit-8.3.xml'
					}
					post {
						always {
							junit allowEmptyResults: true, testResults: 'build/phpunit-8.3.xml'
						}
					}
				}

				stage('ESLint') {
					agent {
						docker {
							image 'node:20-bookworm'
							label 'docker'
						}
					}
					steps {
						sh 'npm ci'
						sh 'npm run lint'
					}
				}

				stage('Jest (Node 20)') {
					agent {
						docker {
							image 'node:20-bookworm'
							label 'docker'
						}
					}
					steps {
						sh 'npm ci'
						sh 'npm test'
					}
				}

				stage('Jest (Node 22)') {
					agent {
						docker {
							image 'node:22-bookworm'
							label 'docker'
						}
					}
					steps {
						sh 'npm ci'
						sh 'npm test'
					}
				}

				stage('Webpack build') {
					agent {
						docker {
							image 'node:20-bookworm'
							label 'docker'
						}
					}
					steps {
						sh 'npm ci'
						sh 'npm run build'
					}
				}
			}
		}
	}
}
