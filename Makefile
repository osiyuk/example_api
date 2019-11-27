init: clear db
	mkdir assets

server: init
	php -S localhost:80

db:
	sqlite3 database.sqlite < create.sql

schema:
	sqlite3 database.sqlite .schema

clear:
	rm -f database.sqlite
	rm -rf assets/