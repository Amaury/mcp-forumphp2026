.PHONY: doc wp mcp db

BOLD  := $(shell tput bold 2>/dev/null)
DIM   := $(shell tput dim  2>/dev/null)
RESET := $(shell tput sgr0 2>/dev/null)

doc:
	@echo "$(BOLD)Usage$(RESET)"
	@echo "  make wp   $(DIM)Lance le WordPress (http://127.0.0.1:8081)$(RESET)"
	@echo "  make mcp  $(DIM)Lance le serveur MCP (http://127.0.0.1:8080)$(RESET)"
	@echo "  make db   $(DIM)Réinjecte la base de données.$(RESET)"

wp:
	@echo "$(BOLD)Lancement du serveur WordPress sur le port 8081$(RESET)"
	@echo "  Site web : $(DIM)http://127.0.0.1:8081$(RESET)"
	@echo "  Admin    : $(DIM)http://127.0.0.1:8081/wp-admin/$(RESET)"
	@echo
	cd wpdemo; PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8081

mcp:
	@echo "$(BOLD)Lancement du serveur MCP sur le port 8080$(RESET)"
	@echo "  Pour ajouter le serveur dans Claude : $(DIM)claude mcp add --transport http blog http://127.0.0.1:8080/mcp$(RESET)"
	@echo
	cd mcp-wp-server; php -S 127.0.0.1:8080 public/index.php

db:
	@echo "$(BOLD)Injection des données de démo en base$(RESET)"
	@echo "  $(DIM)Nom de la base$(RESET) : wpdemo"
	@echo "  $(DIM)Utilisateur$(RESET)    : wpdemo"
	@echo "  $(DIM)Mot d epasse$(RESET)   : wpdemo"
	@MYSQL_PWD="wpdemo" mysql -u wpdemo wpdemo < mcp-wp-server/fixtures/wordpress-demo.sql

