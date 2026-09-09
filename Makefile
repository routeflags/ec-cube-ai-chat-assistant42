# AiChatAssistant42 — DB matrix testing / packaging / verification envs / ship
#
# MySQL / PostgreSQL は常時起動が重いため、必要なときだけ brew services で
# 起動・停止する。SQLite はファイルのみでサービス不要。
#
#   make db-up              # MySQL + PostgreSQL を起動
#   make db-down            # MySQL + PostgreSQL を停止
#   make db-status          # サービスと疎通の確認
#   make test-matrix        # 1コード×3DBのマトリクステスト
#   make test-matrix-chaos  # カオスモード（3DBからランダムに1つ）
#   make test-unit           # 既存の高速suite（DB不要）
#   make verify             # verify-plugin.sh（5 checks）
#   make package            # Store提出用tarballを作成（PACKAGE_OUTで上書き可）
#   make dbs-up             # 検証用DB別環境（sqlite/mysql/pg）を起動
#   make dbs-down           # 検証用DB別環境を停止（データ保持）
#   make dbs-status         # 検証用DB別環境の疎通確認
#   make dbs-install        # 検証用DB別環境の初回構築（composer＋install＋権限）
#   make ship               # verify → unit → matrix → package の一気通し
#   make bump [LEVEL=patch|minor|major] [V=x.y.z]  # 4箇所のバージョン同期
#   make tag [V=x.y.z]      # gitタグ打刻（ツリークリーン必須）

MYSQL_SVC ?= mysql@8.0
PG_SVC ?= postgresql@18
PHPUNIT ?= php vendor/bin/phpunit
PACKAGE_OUT ?= AiChatAssistant42-verify.tar.gz

# 検証用DB別環境（.github/skills/docker-verify-env/SKILL.md 参照）
DBS_DIR ?= /tmp/eccube-verify-dbs
DBS_COMPOSE ?= docker-compose.dbs.yml
DBS_PROJECT ?= eccube-verify-dbs
DBS_SERVICES ?= eccube-sqlite eccube-mysql eccube-pg

.PHONY: db-up db-down db-status test-matrix test-matrix-chaos test-unit verify package dbs-up dbs-down dbs-status dbs-install ship bump tag

db-up:
	brew services start $(MYSQL_SVC)
	brew services start $(PG_SVC)

db-down:
	brew services stop $(MYSQL_SVC)
	brew services stop $(PG_SVC)

db-status:
	brew services list | grep -E "mysql|postgresql" || true
	mysqladmin -uroot status 2>&1 | head -c 120; echo
	pg_isready || true

test-matrix: db-up
	$(PHPUNIT) Tests/Matrix

test-matrix-chaos: db-up
	MATRIX_CHAOS=1 $(PHPUNIT) Tests/Matrix

test-unit:
	$(PHPUNIT) Tests/Unit

verify:
	./bin/verify-plugin.sh

package:
	./bin/package.sh --output $(PACKAGE_OUT)

dbs-up:
	docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) up -d

dbs-down:
	docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) down

dbs-status:
	docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) ps
	for p in 8088 8089 8090; do printf "%s front:%s\n" $$p $$(curl -s -o /dev/null -w "%{http_code}" http://localhost:$$p/); done

dbs-install:
	for s in $(DBS_SERVICES); do \
	  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s bash -c \
	    "php -r \"copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');\" && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet" || exit 1; \
	  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s bash -c \
	    "cd /var/www/html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress" || exit 1; \
	  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s \
	    php bin/console eccube:install --no-interaction || exit 1; \
	  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s \
	    bash -c "chown -R www-data var/ vendor/" || exit 1; \
	done

ship: verify test-unit test-matrix package

# バージョン管理（AGENTS.md「Version sync」4箇所同期）
#   make bump              # patch+1（1.1.2 → 1.1.3）
#   make bump LEVEL=minor  # minor+1（1.1.2 → 1.2.0）
#   make bump V=2.0.0      # 明示指定（LEVELより優先）
# 実処理は bin/bump-version.php（php -r に正規表現を書くと
# make→shell→php の引用層でバックスラッシュが化けるためファイル化）。
CUR_VERSION := $(shell php -r '$$c=json_decode(file_get_contents("composer.json"),true); echo $$c["version"];')
LEVEL ?= patch

bump:
	php bin/bump-version.php $(V) --level=$(LEVEL)
	grep -H '"version"' composer.json; grep -H "^version:" eccube-plugin.yaml; grep -H "SERVER_VERSION =" Service/McpHttpService.php; head -5 Documents/CHANGELOG.md

# gitタグ打刻。V省略時はcomposer.jsonのversionを使用。ツリークリーン必須
tag:
	@V="$(V)"; \
	if [ -z "$$V" ]; then V="$(CUR_VERSION)"; fi; \
	git diff --quiet && git diff --cached --quiet || (echo "tree is dirty. commit first."; exit 1); \
	git tag -a "v$$V" -m "v$$V" && git tag | tail -3
