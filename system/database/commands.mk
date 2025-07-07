.PHONY: db-init
db-init:  ## [Database] Create bdd and schema
	$(CONSOLE) doctrine:database:drop --force
	$(CONSOLE) doctrine:schema:create

.PHONY: db-backup
DATESTAMP:= $(shell date +%Y-%m-%d)
db-backup:  ## [Database] Import SQLite's dbs from prod
	scp nayte@ovh.anagraph.org:/srv/web/street-fighter-6/system/database/SF6-anagraph.db ./system/database/backup/SF6-anagraph-${DATESTAMP}.db
	scp nayte@ovh.anagraph.org:/srv/web/street-fighter-0/system/database/SF0-anagraph.db ./system/database/backup/SF0-anagraph-${DATESTAMP}.db
	scp nayte@ovh.anagraph.org:/srv/web/street-fighter-2/system/database/SF2-anagraph.db ./system/database/backup/SF2-anagraph-${DATESTAMP}.db
	scp nayte@ovh.anagraph.org:/srv/web/street-fighter-3/system/database/SF3-anagraph.db ./system/database/backup/SF3-anagraph-${DATESTAMP}.db

.PHONY: db-dump
db-dump: ## [Database] Use the prod's last imports to use in local dev
	cp ./system/database/backup/SF6-anagraph-${DATESTAMP}.db ./system/database/SF6-anagraph.db
	cp ./system/database/backup/SF0-anagraph-${DATESTAMP}.db ./system/database/SF0-anagraph.db
	cp ./system/database/backup/SF2-anagraph-${DATESTAMP}.db ./system/database/SF2-anagraph.db
	cp ./system/database/backup/SF3-anagraph-${DATESTAMP}.db ./system/database/SF3-anagraph.db