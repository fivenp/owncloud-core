<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Federation\Tests;

use OCA\Federation\DbHandler;
use OCA\Federation\TrustedServers;
use OCP\IDBConnection;
use OCP\IL10N;
use Test\TestCase;

/**
 * @group DB
 */
class DbHandlerTest extends TestCase {
	/** @var  DbHandler */
	private $dbHandler;

	/** @var IL10N | \PHPUnit\Framework\MockObject\MockObject */
	private $il10n;

	/** @var  IDBConnection */
	private $connection;

	/** @var string  */
	private $dbTable = 'trusted_servers';

	public function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->getDatabaseConnection();
		$this->il10n = $this->createMock('OCP\IL10N');

		$this->dbHandler = new DbHandler(
			$this->connection,
			$this->il10n
		);

		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertEmpty($result, 'we need to start with a empty trusted_servers table');
	}

	public function tearDown(): void {
		parent::tearDown();
		$query = $this->connection->getQueryBuilder()->delete($this->dbTable);
		$query->execute();
	}

	/**
	 * @dataProvider dataTestAddServer
	 *
	 * @param string $url passed to the method
	 * @param string $expectedUrl the url we expect to be written to the db
	 * @param string $expectedHash the hash value we expect to be written to the db
	 */
	public function testAddServer($url, $expectedUrl, $expectedHash) {
		$id = $this->dbHandler->addServer($url);

		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertCount(1, $result);
		$this->assertSame($expectedUrl, $result[0]['url']);
		$this->assertSame($id, (int)$result[0]['id']);
		$this->assertSame($expectedHash, $result[0]['url_hash']);
		$this->assertSame(TrustedServers::STATUS_PENDING, (int)$result[0]['status']);
	}

	public function dataTestAddServer() {
		return [
				['http://owncloud.com', 'http://owncloud.com', \sha1('owncloud.com')],
				['https://owncloud.com', 'https://owncloud.com', \sha1('owncloud.com')],
				['http://owncloud.com/', 'http://owncloud.com', \sha1('owncloud.com')],
		];
	}

	public function testRemove() {
		$id1 = $this->dbHandler->addServer('server1');
		$id2 = $this->dbHandler->addServer('server2');

		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertCount(2, $result);
		$this->assertSame('server1', $result[0]['url']);
		$this->assertSame('server2', $result[1]['url']);
		$this->assertSame($id1, (int)$result[0]['id']);
		$this->assertSame($id2, (int)$result[1]['id']);

		$this->dbHandler->removeServer($id2);
		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertCount(1, $result);
		$this->assertSame('server1', $result[0]['url']);
		$this->assertSame($id1, (int)$result[0]['id']);
	}

	public function testGetServerById() {
		$this->dbHandler->addServer('server1');
		$id = $this->dbHandler->addServer('server2');

		$result = $this->dbHandler->getServerById($id);
		$this->assertSame('server2', $result['url']);
	}

	public function testGetAll() {
		$id1 = $this->dbHandler->addServer('server1');
		$id2 = $this->dbHandler->addServer('server2');

		$result = $this->dbHandler->getAllServer();
		$this->assertCount(2, $result);
		$this->assertSame('server1', $result[0]['url']);
		$this->assertSame('server2', $result[1]['url']);
		$this->assertSame($id1, (int)$result[0]['id']);
		$this->assertSame($id2, (int)$result[1]['id']);
	}

	/**
	 * @dataProvider dataTestServerExists
	 *
	 * @param string $serverInTable
	 * @param string $checkForServer
	 * @param bool $expected
	 */
	public function testServerExists($serverInTable, $checkForServer, $expected) {
		$this->dbHandler->addServer($serverInTable);
		$this->assertSame(
			$expected,
			$this->dbHandler->serverExists($checkForServer)
		);
	}

	public function dataTestServerExists() {
		return [
			['server1', 'server1', true],
			['server1', 'http://server1', true],
			['server1', 'server2', false]
		];
	}

	public function testAddToken() {
		$this->dbHandler->addServer('server1');
		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$resultToken = $result[0]['token'];
		// MySQL and sqLite support inline column comments in doctrine/dbal
		// When the result is null, and the database has been created with column comments,
		// then dbal can return a string like:
		// 'NULL --token used to exchange the shared secret'
		if (\is_string($resultToken) && (\substr($resultToken, 0, 4) === 'NULL')) {
			$resultToken = null;
		}
		$this->assertCount(1, $result);
		$this->assertNull($resultToken);
		$this->dbHandler->addToken('http://server1', 'token');
		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertCount(1, $result);
		$this->assertSame('token', $result[0]['token']);
	}

	public function testGetToken() {
		$this->dbHandler->addServer('server1');
		$this->dbHandler->addToken('http://server1', 'token');
		$this->assertSame(
			'token',
			$this->dbHandler->getToken('https://server1')
		);
	}

	public function testAddSharedSecret() {
		$this->dbHandler->addServer('server1');
		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$resultToken = $result[0]['shared_secret'];
		// MySQL and sqLite support inline column comments in doctrine/dbal
		// When the result is null, and the database has been created with column comments,
		// then dbal can return a string like:
		// 'NULL --shared secret used to authenticate'
		if (\is_string($resultToken) && (\substr($resultToken, 0, 4) === 'NULL')) {
			$resultToken = null;
		}
		$this->assertCount(1, $result);
		$this->assertNull($resultToken);
		$this->dbHandler->addSharedSecret('http://server1', 'secret');
		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertCount(1, $result);
		$this->assertSame('secret', $result[0]['shared_secret']);
	}

	public function testGetSharedSecret() {
		$this->dbHandler->addServer('server1');
		$this->dbHandler->addSharedSecret('http://server1', 'secret');
		$this->assertSame(
			'secret',
			$this->dbHandler->getSharedSecret('https://server1')
		);
	}

	public function testSetServerStatus() {
		$this->dbHandler->addServer('server1');
		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertCount(1, $result);
		$this->assertSame(TrustedServers::STATUS_PENDING, (int)$result[0]['status']);
		$this->dbHandler->setServerStatus('http://server1', TrustedServers::STATUS_OK);
		$query = $this->connection->getQueryBuilder()->select('*')->from($this->dbTable);
		$result = $query->execute()->fetchAll();
		$this->assertCount(1, $result);
		$this->assertSame(TrustedServers::STATUS_OK, (int)$result[0]['status']);
	}

	public function testGetServerStatus() {
		$this->dbHandler->addServer('server1');
		$this->dbHandler->setServerStatus('http://server1', TrustedServers::STATUS_OK);
		$this->assertSame(
			TrustedServers::STATUS_OK,
			$this->dbHandler->getServerStatus('https://server1')
		);

		// test sync token
		$this->dbHandler->setServerStatus('http://server1', TrustedServers::STATUS_OK, 'token1234567890');
		$servers = $this->dbHandler->getAllServer();
		$this->assertSame('token1234567890', $servers[0]['sync_token']);
	}

	/**
	 * hash should always be computed with the normalized URL
	 *
	 * @dataProvider dataTestHash
	 *
	 * @param string $url
	 * @param string $expected
	 */
	public function testHash($url, $expected) {
		$this->assertSame(
			$expected,
			$this->invokePrivate($this->dbHandler, 'hash', [$url])
		);
	}

	public function dataTestHash() {
		return [
			['server1', \sha1('server1')],
			['http://server1', \sha1('server1')],
			['https://server1', \sha1('server1')],
			['http://server1/', \sha1('server1')],
		];
	}

	/**
	 * @dataProvider dataTestNormalizeUrl
	 *
	 * @param string $url
	 * @param string $expected
	 */
	public function testNormalizeUrl($url, $expected) {
		$this->assertSame(
			$expected,
			$this->invokePrivate($this->dbHandler, 'normalizeUrl', [$url])
		);
	}

	public function dataTestNormalizeUrl() {
		return [
			['owncloud.com', 'owncloud.com'],
			['http://owncloud.com', 'owncloud.com'],
			['https://owncloud.com', 'owncloud.com'],
			['https://owncloud.com//mycloud', 'owncloud.com/mycloud'],
			['https://owncloud.com/mycloud/', 'owncloud.com/mycloud'],
		];
	}

	/**
	 * @dataProvider providesAuth
	 */
	public function testAuth($expectedResult, $user, $password) {
		if ($expectedResult) {
			$this->dbHandler->addServer('url1');
			$this->dbHandler->addSharedSecret('url1', $password);
		}
		$result = $this->dbHandler->auth($user, $password);
		$this->assertEquals($expectedResult, $result);
	}

	public function providesAuth() {
		return [
			[false, 'foo', ''],
			[true, 'system', '123456789'],
		];
	}
}
