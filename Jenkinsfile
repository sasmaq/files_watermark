// Jenkins equivalent of .github/workflows/php.yml and .github/workflows/nodejs.yml.
//
// The GitHub triggers (push to main, pull_request) map to Multibranch Pipeline
// branch/PR discovery, so there is no `triggers` block here - configure the job
// as a Multibranch Pipeline (or GitHub Branch Source) and it fires on the same
// events.

pipeline {
	agent none

	options {
		// Mirrors `concurrency: cancel-in-progress: true` - a new build on the
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
			// `fail-fast: false` - one failing matrix leg does not cancel the rest.
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
					// version is enough - unlike the syntax stages, which are what catch
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

				stage('Static analysis (Psalm)') {
					// One version is enough: psalm.xml pins `phpVersion="8.2"`, so the
					// analysis is identical on 8.3 and the second leg would only re-prove
					// that. The ci/ image carries imagick, which is what gets the optional
					// Imagick branch of ImageWatermarker analysed - Psalm reflects extension
					// classes from the running PHP, and psalm.xml suppresses the
					// undefined-class noise that a host without the extension would produce.
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
						sh 'composer psalm'
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

		// Mirrors .github/workflows/e2e.yml. Deliberately *not* inside a container
		// agent and not in the parallel block above: it stands up a real Nextcloud
		// with `docker compose`, bind-mounting the workspace into the container, so
		// it needs the agent's own Docker rather than Docker inside a Docker agent -
		// a bind mount from within a container agent would resolve against the host
		// filesystem and mount the wrong (or an empty) directory.
		//
		// The agent therefore needs php + composer, node + npm, and docker compose on
		// PATH, and port 8080 free while it runs.
		stage('E2E (Cypress)') {
			agent {
				label 'docker'
			}
			steps {
				sh 'composer install --no-dev --prefer-dist --no-progress --no-interaction'
				sh 'npm ci'
				sh 'npm run build'
				sh 'docker compose up -d'
				sh '''
					for attempt in $(seq 1 60); do
						if curl -sf http://localhost:8080/status.php | grep -q '"installed":true'; then
							exit 0
						fi
						sleep 5
					done
					echo "Nextcloud did not finish installing"
					docker compose logs --tail=100
					exit 1
				'''
				sh 'docker compose exec -T -u www-data nextcloud php occ app:enable files_watermark'
				sh 'npm run test:e2e'
			}
			post {
				always {
					archiveArtifacts artifacts: 'cypress/screenshots/**', allowEmptyArchive: true
					sh 'docker compose down -v || true'
				}
			}
		}
	}
}
