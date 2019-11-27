pragma foreign_keys = ON;

create table magazine (
	magazine_key integer primary key autoincrement,
	title varchar(255) not null,
	short varchar(255),
	image varchar(255),
	published datetime
);

create table author (
	author_key integer primary key autoincrement,
	first_name varchar(255) not null,
	second_name varchar(255) not null,
	third_name varchar(255)
);

create table magazine_authors (
	magazine_key integer not null references magazine(magazine_key),
	author_key integer not null references author(author_key),
	primary key (magazine_key, author_key)
);
