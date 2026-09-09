# AiChatAssistant42 — DB matrix testing
#
# MySQL / PostgreSQL は常時起動が重いため、必要なときだけ brew services で
# 起動・停止する。SQLite はファイルのみでサービス不要。
#
#   make db-up            # MySQL + PostgreSQL を起動
#   make db-down          # MySQL + PostgreSQL を停止
#   make db-status        # サービスと疎通の確認
#   make test-matrix      # 1コード×3DBのマトリクステスト
#   make test-matrix-chaos # カオスモード（3DBからランダムに1つ）
#   make test-unit         # 既存の高速suite（DB不要）

MYSQL_SVC ?= mysql@8.0
PG_SVC ?= postgresql@18
PHPUNIT ?= php vendor/bin/phpunit

.PHONY: db-up db-down db-status test-matrix test-matrix-chaos test-unit

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
