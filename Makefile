# AiChatAssistant44 — DB matrix testing / packaging / verification envs / ship
#
# MySQL / PostgreSQL は常時起動が重いため、必要なときだけ brew services で
# 起動・停止する。SQLite はファイルのみでサービス不要。
#
#   make db-up              # MySQL + PostgreSQL を起動
#   make db-wait            # 起動後の疎通待ち（test-matrixの前に必須）
#   make db-down            # MySQL + PostgreSQL を停止
#   make db-status          # サービスと疎通の確認
#   make test-matrix        # 1コード×3DBのマトリクステスト
#   make test-matrix-chaos  # カオスモード（3DBからランダムに1つ）
#   make test-unit           # 既存の高速suite（DB不要）
#   make verify             # verify-plugin.sh（5 checks）
#   make package            # Store提出用tarballを作成（PACKAGE_OUTで上書き可）
#   make dbs-fetch          # 検証用EC-CUBE実体が無い場合のみ取得（git clone -b $(DBS_BRANCH) --depth 1 ×3。dbs/{sqlite,mysql,pg}/ はgit管理外）
#   make dbs-fetch DBS_BRANCH=4.4  # 4.4系を取得 (既定は4.2。alpha期は tag 4.4.0-alpha-1 も可)
#   make dbs-up             # 検証用DB別環境（sqlite/mysql/pg）を起動
#   make dbs-wait           # 起動時プロビジョニング完了待ち（up直後に必須）
#   make dbs-down           # 検証用DB別環境を停止（データ保持）
#   make dbs-status         # 検証用DB別環境の疎通確認
#   make dbs-install        # 検証用DB別環境の初回構築（composer＋install＋権限）
#   make ship               # verify → unit → matrix → package の一気通し
#   make bump [LEVEL=patch|minor|major] [V=x.y.z]  # 4箇所のバージョン同期
#   make tag [V=x.y.z]      # gitタグ打刻（ツリークリーン必須）

MYSQL_SVC ?= mysql@8.0
PG_SVC ?= postgresql@18
PHPUNIT ?= php vendor/bin/phpunit
PACKAGE_OUT ?= AiChatAssistant44-verify.tar.gz

# 検証用DB別環境（Tests/Docker/dbs 配下に永続化。/tmp は再起動で消えるため使用しない）
DBS_DIR ?= Tests/Docker/dbs
DBS_COMPOSE ?= docker-compose.dbs.yml
DBS_PROJECT ?= eccube-verify-dbs
DBS_BRANCH ?= 4.2
DBS_SERVICES ?= eccube-sqlite eccube-mysql eccube-pg

.PHONY: db-up db-wait db-down db-status matrix-guard test-matrix test-matrix-chaos test-unit verify package dbs-fetch dbs-up dbs-wait dbs-down dbs-status dbs-install ship bump tag

db-up:
	brew services start $(MYSQL_SVC)
	brew services start $(PG_SVC)

# brew services start は即時復帰するため、疎通できるまで待つ。
# 無しに test-matrix を走らせると接続拒否で全DB skip になる。
db-wait:
	for i in $$(seq 1 30); do \
	  mysqladmin -uroot ping 2>/dev/null | grep -q "mysqld is alive" && break; \
	  sleep 2; \
	done
	mysqladmin -uroot ping 2>&1 | head -c 120; echo
	for i in $$(seq 1 30); do \
	  pg_isready 2>/dev/null | grep -q "accepting connections" && break; \
	  sleep 2; \
	done
	pg_isready || true

db-down:
	brew services stop $(MYSQL_SVC)
	brew services stop $(PG_SVC)

db-status:
	brew services list | grep -E "mysql|postgresql" || true
	mysqladmin -uroot status 2>&1 | head -c 120; echo
	pg_isready || true

# 本番一致ガード: 4.4 の本番相当は DBAL 4 / ORM 3 / Symfony 7.4。
# composer.json require-dev を ^4.0/^3.0/^7.4 に固定済みだが、vendor が古い解決の
# まま残っていると DBAL 3 での green が DBAL 4 の保証にならない (getName() 500 の
# 再発)。Matrix 実行前に installed.json の major を検証し、不一致なら fail-fast
# する。composer.lock は plugin ではコミットしない方針 (.gitignore) のため、
# 範囲固定 + 本ガードの二重化で担保する。
matrix-guard:
	php -r '$$f = "vendor/composer/installed.json"; if (!file_exists($$f)) { fwrite(STDERR, "matrix-guard: vendor/ が無い。composer install/update を先に実行すること\n"); exit(1); } $$pkgs = json_decode(file_get_contents($$f), true)["packages"]; $$need = ["doctrine/dbal" => "4", "doctrine/orm" => "3"]; foreach ($$need as $$name => $$major) { $$found = null; foreach ($$pkgs as $$p) { if ($$p["name"] === $$name) { $$found = ltrim($$p["version"], "v"); break; } } if ($$found === null) { fwrite(STDERR, "matrix-guard: $$name が vendor/ に無い\n"); exit(1); } echo "$$name=$$found\n"; if (explode(".", $$found)[0] !== $$major) { fwrite(STDERR, "matrix-guard: $$name は本番相当 major $$major が必須 (現在 $$found)。composer update $$name を実行すること\n"); exit(1); } } echo "matrix-guard: OK (DBAL4/ORM3)\n";'

test-matrix: db-up db-wait matrix-guard
	$(PHPUNIT) Tests/Matrix

test-matrix-chaos: db-up db-wait matrix-guard
	MATRIX_CHAOS=1 $(PHPUNIT) Tests/Matrix

test-unit:
	$(PHPUNIT) Tests/Unit

verify:
	./bin/verify-plugin.sh

package:
	./bin/package.sh --output $(PACKAGE_OUT)

dbs-fetch:
	for d in sqlite mysql pg; do \
	  if [ -d "$(DBS_DIR)/$$d" ]; then echo "$$d exists, skip"; \
	  else git clone -b $(DBS_BRANCH) --depth 1 https://github.com/EC-CUBE/ec-cube.git $(DBS_DIR)/$$d || git clone -b 4.4.0-alpha-1 --depth 1 https://github.com/EC-CUBE/ec-cube.git $(DBS_DIR)/$$d; fi; \
	done

dbs-up:
	docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) up -d

# 起動時プロビジョニング（apt+PHP拡張ビルド、数分）の完了待ち。
# up直後の dbs-install は ext-intl/ext-zip 不足で失敗するため、必ず経由する。
# 各サービス最大10分ポーリングする。
dbs-wait:
	for s in $(DBS_SERVICES); do \
	  echo "waiting for $$s extensions..."; \
	  for i in $$(seq 1 60); do \
	    if docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s php -m 2>/dev/null | grep -qE "^intl$$" \
	    && docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s php -m 2>/dev/null | grep -qE "^zip$$"; then \
	      echo "$$s ready"; break; \
	    fi; \
	    sleep 10; \
	  done; \
	done

dbs-down:
	docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) down

dbs-status:
	docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) ps
	for p in 8082 8088 8089 8090; do printf "%s front:%s\n" $$p $$(curl -s -o /dev/null -w "%{http_code}" http://localhost:$$p/); done

dbs-install:
	for s in $(DBS_SERVICES); do \
	  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s bash -c \
	    "php -r \"copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');\" && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet" || exit 1; \
	  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s bash -c \
	    "cd /var/www/html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress" || exit 1; \
  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s \
    php bin/console eccube:install --no-interaction || exit 1; \
  docker compose -p $(DBS_PROJECT) -f $(DBS_DIR)/$(DBS_COMPOSE) exec -T $$s \
    bash -c "chown -R www-data var/ vendor/ app/Plugin/" || exit 1; \
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

# gitタグ打刻。V省略時はcomposer.jsonのversionを使用。
# ガード: ツリーがcleanでないと打刻しない（if-fi一体形。; 区切りでの継続を禁止）
tag:
	@V="$(V)"; \
	if [ -z "$$V" ]; then V="$(CUR_VERSION)"; fi; \
	if git diff --quiet && git diff --cached --quiet; then \
	  echo "tagging v$$V"; \
	  git tag -a "v$$V" -m "v$$V" && git tag | tail -3; \
	else \
	  echo "tree is dirty. commit first."; exit 1; \
	fi
