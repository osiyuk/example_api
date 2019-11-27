<?php

ini_set('display_errors', true);
ini_set('post_max_size', '2M');
ini_set('upload_max_filesize', '2M');

$db = new SQLite3('database.sqlite', SQLITE3_OPEN_READWRITE);


interface CRUD {
	public function create(array $fields): int;
	public function read(int $page, int $perPage): array;
	public function update(int $key, array $fields): int;
	public function delete(int $key): int;
}


final class Author implements CRUD
{
	protected $field_names = array('first_name', 'second_name', 'third_name');

	public function create(array $fields): int
	{
		global $db;

		$query = $db->prepare("
INSERT INTO author (first_name, second_name, third_name)
VALUES (:a, :b, :c);"
		);
		$query->bindValue(':a', $fields['first_name'], SQLITE3_TEXT);
		$query->bindValue(':b', $fields['second_name'], SQLITE3_TEXT);
		$query->bindValue(':c', $fields['third_name'], SQLITE3_TEXT);

		$query->execute();
		return $db->lastInsertRowID();
	}

	public function read(int $page = 1, int $limit = 20): array
	{
		global $db;

		$offset = ($page - 1) * $limit;
		$result = $db->query("
SELECT author_key, first_name, second_name, third_name
FROM author LIMIT $limit OFFSET $offset;"
		);

		$authors = array();
		while ($row = $result->fetchArray(SQLITE3_ASSOC))
			$authors[] = $row;

		return $authors;
	}

	public function update(int $key, array $fields) : int
	{
		global $db;

		foreach ($this->field_names as $name) {
			if (isset($fields[$name])) {
				$escaped = $db->escapeString($fields[$name]);
				$values[] = "$name = '$escaped'";
			}
		}
		$values = join(', ', $values);
		return $db->exec("UPDATE author SET $values WHERE author_key = $key;");
	}

	public function delete(int $key) : int
	{
		global $db;

		return $db->exec("DELETE FROM author WHERE author_key = $key;");
	}
}


final class Magazine implements CRUD
{
	protected $field_names = array('title', 'short', 'image', 'published');

	protected function magazine_authors(int $magazine_key, array $authors) : bool
	{
		global $db;

		$author_keys = array_map('intval', $authors);
		foreach ($author_keys as $author_key)
			$pairs[] = "($magazine_key, $author_key)";

		$values = join(', ', $pairs);
		return $db->exec("
INSERT INTO magazine_authors (magazine_key, author_key) VALUES $values;"
		);
	}

	public function create(array $fields): int
	{
		global $db;

		$query = $db->prepare("
INSERT INTO magazine (title, short, image, published)
VALUES (:title, :short, :image, :published);"
		);
		foreach ($this->field_names as $name)
			$query->bindValue($name, $fields[$name], SQLITE3_TEXT);

		$query->execute();
		$magazine_key = $db->lastInsertRowID();

		$this->magazine_authors($magazine_key, $fields['authors']);

		return $magazine_key;
	}

	public function read(int $page = 1, int $limit = 20): array
	{
		global $db;

		$offset = ($page - 1) * $limit;
		$result = $db->query("
SELECT magazine_key, title, short, image, published
FROM magazine LIMIT $limit OFFSET $offset;"
		);

		$magazines = array();
		while ($row = $result->fetchArray(SQLITE3_ASSOC))
			$magazines[] = $row;

		return $magazines;
	}

	public function update(int $key, array $fields): int
	{
		global $db;

		if (array_key_exists('authors', $fields)) {
			$insert = $this->magazine_authors($key, $fields['authors']);

			if (!$insert) return false;
		}

		foreach ($this->field_names as $name) {
			if (isset($fields[$name])) {
				$escaped = $db->escapeString($fields[$name]);
				$values[] = "$name = '$escaped'";
			}
		}
		$values = join(', ', $values);
		return $db->exec("UPDATE magazine SET $values WHERE magazine_key = $key;");
	}

	public function delete(int $key): int
	{
		global $db;

		return $db->exec("
DELETE FROM magazine_authors WHERE magazine_key = $key;
DELETE FROM magazine WHERE magazine_key = $key;"
		);
	}
}


function response(string $key, $data)
{
	die(json_encode(array($key => $data)));
}

function error(string $message)
{
	http_response_code(400);
	response('error', $message);
}

function should_be_set(string $key, array $arr)
{
	if (array_key_exists($key, $arr))
		return;

	http_response_code(400);
	response($key, 'not set');
}

function should_be(bool $sign, string $key, string $message)
{
	if ($sign) return;
	http_response_code(400);
	response($key, $message);
}

function method_should_be(string $method)
{
	if ($method == $_SERVER['REQUEST_METHOD'])
		return;

	http_response_code(403);
	die('Forbidden');
}


// phpinfo(INFO_VARIABLES); // INFO_CONFIGURATION

if ('/photo/upload' == $_SERVER['REQUEST_URI']) {
	method_should_be('POST');
	should_be_set('magazine_key', $_POST);
	should_be_set('image', $_FILES);
	should_be(UPLOAD_ERR_OK == $_FILES['image']['error'], 'upload', 'failed');

	$tmp_name = $_FILES['image']['tmp_name'];
	$jpg_or_png = strstr($tmp_name, 'jpg') > 0 || strstr($tmp_name, 'png') > 0;
	should_be($jpg_or_png, 'image', 'jpg or png only');

	$fname = 'assets/' . $tmp_name;
	should_be(move_uploaded_file($tmp_name, $fname));

	$magazine['image'] = '/' . $fname;
	$update = (new Magazine)->update($_POST['magazine_key'], $magazine);
	$update ? response('update', 'success') : error('not updated');
}

if ('POST' == $_SERVER['REQUEST_METHOD']) {
	$json = file_get_contents('php://input');
	$in   = json_decode($json, true); // assoc
	if (!is_array($in))
		error('invalid query');
}


switch ($_SERVER['REQUEST_URI']) {

case '/author/add':
	method_should_be('POST');
	should_be_set('first_name', $in);
	should_be_set('second_name', $in);

	$author_key = (new Author)->create($in);
	$author_key > 0 ? response('author_key', $author_key) : error('not created');


case '/author/update':
	method_should_be('POST');
	should_be_set('author_key', $in);

	$author_key = $in['author_key'];
	unset($in['author_key']);

	$update = (new Author)->update($author_key, $in);
	$update ? response('update', 'success') : error('not updated');


case '/author/delete':
	method_should_be('POST');
	should_be_set('author_key', $in);

	$delete = (new Author)->delete($in['author_key']);
	$delete ? response('delete', 'success') : error('not deleted');


case '/author/list':
	method_should_be('GET');
	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	$limit = isset($_GET['perPage']) ? intval($_GET['perPage']) : 20;
	$authors = (new Author)->read($page, $limit);
	response('authors', $authors);


case '/magazine/add':
	method_should_be('POST');
	should_be_set('title', $in);
	should_be_set('authors', $in);
	should_be(is_array($in['authors']), 'authors', 'not array');

	$magazine_key = (new Magazine)->create($in);
	response('magazine_key', $magazine_key);


case '/magazine/update':
	method_sould_be('POST');
	should_be_set('magazine_key', $in);

	$update = (new Magazine)->update($in['magazine_key'], $in);
	$update ? response('update', 'success') : error('not updated');


case '/magazine/delete':
	method_should_be('POST');
	should_be_set('magazine_key', $in);

	$delete = (new Magazine)->delete($in['magazine_key']);
	$delete ? response('delete', 'success') : error('not deleted');


case '/magazine/list':
	method_should_be('GET');
	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	$limit = isset($_GET['perPage']) ? intval($_GET['perPage']) : 20;
	$magazines = (new Magazine)->read($page, $limit);
	response('magazines', $magazines);


default:
	http_response_code(403);
	die('Forbidden');
}

