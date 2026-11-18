pipeline {
    agent { label 'docker-linux' }

    options {
        timestamps()
        disableConcurrentBuilds()
        timeout(time: 35, unit: 'MINUTES')
        buildDiscarder(logRotator(numToKeepStr: '15'))
        skipDefaultCheckout(true)
    }

    environment {
        COMPOSE_FILE = 'compose.yml:compose.workers.yml:compose.https-e2e.yml:compose.jenkins.yml'
    }

    stages {
        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Preflight') {
            steps {
                script {
                    env.COMPOSE_PROJECT_NAME = env.BUILD_TAG
                        .toLowerCase()
                        .replaceAll(/[^a-z0-9_-]/, '-')
                }

                sh '''
                    set -eu
                    docker version
                    docker compose version
                    docker compose config --quiet
                '''
            }
        }

        stage('Build PHP image') {
            steps {
                sh 'docker compose build app'
            }
        }

        stage('Start dependencies') {
            steps {
                sh '''
                    docker compose up -d --wait \
                        db rabbitmq webhook-receiver
                '''
            }
        }

        stage('Composer') {
            steps {
                sh '''
                    docker compose run --rm --no-deps \
                        --user "$(id -u):$(id -g)" \
                        -e APP_ENV=test \
                        -e COMPOSER_HOME=/tmp/composer \
                        app composer validate --no-check-publish

                    docker compose run --rm --no-deps \
                        --user "$(id -u):$(id -g)" \
                        -e APP_ENV=test \
                        -e COMPOSER_HOME=/tmp/composer \
                        app composer install \
                        --no-interaction \
                        --prefer-dist \
                        --no-progress
                '''
            }
        }

        stage('Code quality') {
            steps {
                sh '''
                    docker compose run --rm --no-deps \
                        --user "$(id -u):$(id -g)" \
                        -e APP_ENV=test \
                        app vendor/bin/phpcs \
                        --standard=phpcs.xml.dist

                    docker compose run --rm --no-deps \
                        --user "$(id -u):$(id -g)" \
                        -e APP_ENV=test \
                        app vendor/bin/phpstan analyse \
                        --configuration=phpstan.neon.dist \
                        --no-progress \
                        --memory-limit=1G
                '''
            }
        }

        stage('Database & Symfony') {
            steps {
                sh '''
                    set -eu

                    for command in \
                        "doctrine:database:create --if-not-exists" \
                        "doctrine:migrations:migrate --no-interaction" \
                        "doctrine:schema:validate" \
                        "lint:container"
                    do
                        docker compose run --rm --no-deps \
                            --user "$(id -u):$(id -g)" \
                            -e APP_ENV=test \
                            app php bin/console $command
                    done
                '''
            }
        }

        stage('PHPUnit') {
            steps {
                sh '''
                    mkdir -p build/reports

                    docker compose run --rm --no-deps \
                        --user "$(id -u):$(id -g)" \
                        -e APP_ENV=test \
                        -e RUN_RABBITMQ_INTEGRATION=1 \
                        app php bin/phpunit \
                        --log-junit build/reports/phpunit.xml \
                        --display-phpunit-notices \
                        --fail-on-phpunit-notice
                '''
            }
        }

        stage('HTTPS E2E') {
            steps {
                sh '''
                    docker compose run --rm --no-deps \
                        --user "$(id -u):$(id -g)" \
                        -e APP_ENV=test \
                        -e RUN_HTTPS_E2E_INTEGRATION=1 \
                        app php bin/phpunit \
                        tests/Integration/RealHttpsWebhookDeliveryIntegrationTest.php \
                        --log-junit build/reports/https-e2e.xml
                '''
            }
        }

        stage('Production image') {
            steps {
                sh '''
                    docker build \
                        -f Dockerfile.prod \
                        --target runtime \
                        -t "eventflow-api:${COMPOSE_PROJECT_NAME}" \
                        .

                    docker run --rm \
                        --entrypoint sh \
                        "eventflow-api:${COMPOSE_PROJECT_NAME}" \
                        -ec '
                            test "$(id -u)" != "0"
                            test -f /app/public/index.php
                            test -f /app/vendor/autoload_runtime.php
                            test ! -e /app/.env
                            test ! -d /app/tests
                            test ! -d /app/.secrets
                            echo "Production image verified"
                        '
                '''
            }
        }
    }

    post {
        always {
            junit(
                testResults: 'build/reports/*.xml',
                allowEmptyResults: true
            )

            sh '''
                if [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then
                    docker compose down \
                        --volumes \
                        --remove-orphans || true

                    docker image rm \
                        "eventflow-api:${COMPOSE_PROJECT_NAME}" \
                        >/dev/null 2>&1 || true
                fi
            '''
        }
    }
}
