<?php

namespace MediaWiki\Session;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserNameUtils;
use MediaWikiIntegrationTestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use TestLogger;
use User;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Session
 * @group Database
 * @covers \MediaWiki\Session\CookieSessionProvider
 */
class CookieSessionProviderTest extends MediaWikiIntegrationTestCase {

	private function getConfig() {
		return new \HashConfig( [
			'CookiePrefix' => 'CookiePrefix',
			'CookiePath' => 'CookiePath',
			'CookieDomain' => 'CookieDomain',
			'CookieSecure' => true,
			'CookieHttpOnly' => true,
			'CookieSameSite' => '',
			'SessionName' => false,
			'CookieExpiration' => 100,
			'ExtendedLoginCookieExpiration' => 200,
			'ForceHTTPS' => false,
		] );
	}

	/**
	 * @return HookContainer
	 */
	private function getHookContainer() {
		// Need a real HookContainer for testPersistSession() which modifies $wgHooks
		return MediaWikiServices::getInstance()->getHookContainer();
	}

	/**
	 * Provider for testing both values of $wgForceHTTPS
	 */
	public static function provideForceHTTPS() {
		return [
			[ false ],
			[ true ]
		];
	}

	public function testConstructor() {
		try {
			new CookieSessionProvider();
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'MediaWiki\\Session\\CookieSessionProvider::__construct: priority must be specified',
				$ex->getMessage()
			);
		}

		try {
			new CookieSessionProvider( [ 'priority' => 'foo' ] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'MediaWiki\\Session\\CookieSessionProvider::__construct: Invalid priority',
				$ex->getMessage()
			);
		}
		try {
			new CookieSessionProvider( [ 'priority' => SessionInfo::MIN_PRIORITY - 1 ] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'MediaWiki\\Session\\CookieSessionProvider::__construct: Invalid priority',
				$ex->getMessage()
			);
		}
		try {
			new CookieSessionProvider( [ 'priority' => SessionInfo::MAX_PRIORITY + 1 ] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'MediaWiki\\Session\\CookieSessionProvider::__construct: Invalid priority',
				$ex->getMessage()
			);
		}

		try {
			new CookieSessionProvider( [ 'priority' => 1, 'cookieOptions' => null ] );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \InvalidArgumentException $ex ) {
			$this->assertSame(
				'MediaWiki\\Session\\CookieSessionProvider::__construct: cookieOptions must be an array',
				$ex->getMessage()
			);
		}

		$config = $this->getConfig();
		$p = TestingAccessWrapper::newFromObject(
			new CookieSessionProvider( [ 'priority' => 1 ] )
		);
		$p->init(
			new TestLogger(),
			$config,
			$this->createNoOpMock( SessionManager::class ),
			$this->createHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);
		$this->assertSame( 1, $p->priority );
		$this->assertEquals( [
			'callUserSetCookiesHook' => false,
			'sessionName' => 'CookiePrefix_session',
		], $p->params );
		$this->assertEquals( [
			'prefix' => 'CookiePrefix',
			'path' => 'CookiePath',
			'domain' => 'CookieDomain',
			'secure' => true,
			'httpOnly' => true,
			'sameSite' => '',
		], $p->cookieOptions );

		$config->set( 'SessionName', 'SessionName' );
		$p = TestingAccessWrapper::newFromObject(
			new CookieSessionProvider( [ 'priority' => 3 ] )
		);
		$p->init(
			new TestLogger(),
			$config,
			$this->createNoOpMock( SessionManager::class ),
			$this->createHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);
		$this->assertEquals( 3, $p->priority );
		$this->assertEquals( [
			'callUserSetCookiesHook' => false,
			'sessionName' => 'SessionName',
		], $p->params );
		$this->assertEquals( [
			'prefix' => 'CookiePrefix',
			'path' => 'CookiePath',
			'domain' => 'CookieDomain',
			'secure' => true,
			'httpOnly' => true,
			'sameSite' => '',
		], $p->cookieOptions );

		$p = TestingAccessWrapper::newFromObject( new CookieSessionProvider( [
			'priority' => 10,
			'callUserSetCookiesHook' => true,
			'cookieOptions' => [
				'prefix' => 'XPrefix',
				'path' => 'XPath',
				'domain' => 'XDomain',
				'secure' => 'XSecure',
				'httpOnly' => 'XHttpOnly',
				'sameSite' => 'XSameSite',
			],
			'sessionName' => 'XSession',
		] ) );
		$p->init(
			new TestLogger(),
			$config,
			$this->createNoOpMock( SessionManager::class ),
			$this->createHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);
		$this->assertEquals( 10, $p->priority );
		$this->assertEquals( [
			'callUserSetCookiesHook' => true,
			'sessionName' => 'XSession',
		], $p->params );
		$this->assertEquals( [
			'prefix' => 'XPrefix',
			'path' => 'XPath',
			'domain' => 'XDomain',
			'secure' => 'XSecure',
			'httpOnly' => 'XHttpOnly',
			'sameSite' => 'XSameSite',
		], $p->cookieOptions );
	}

	public function testBasics() {
		$provider = new CookieSessionProvider( [ 'priority' => 10 ] );

		$this->assertTrue( $provider->persistsSessionId() );
		$this->assertTrue( $provider->canChangeUser() );

		$extendedCookies = [ 'UserID', 'UserName', 'Token' ];

		$this->assertEquals(
			$extendedCookies,
			TestingAccessWrapper::newFromObject( $provider )->getExtendedLoginCookies(),
			'List of extended cookies (subclasses can add values, but we\'re calling the core one here)'
		);

		$msg = $provider->whyNoSession();
		$this->assertInstanceOf( \Message::class, $msg );
		$this->assertSame( 'sessionprovider-nocookies', $msg->getKey() );
	}

	public function testProvideSessionInfo() {
		$params = [
			'priority' => 20,
			'sessionName' => 'session',
			'cookieOptions' => [ 'prefix' => 'x' ],
		];
		$provider = new CookieSessionProvider( $params );
		$logger = new TestLogger( true );
		$provider->init(
			$logger,
			$this->getConfig(),
			new SessionManager(),
			$this->createHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);

		$user = static::getTestSysop()->getUser();
		$id = $user->getId();
		$name = $user->getName();
		$token = $user->getToken( true );

		$this->hideDeprecated(
			'UserSetCookies hook (used in '
			. get_class( $this->getMockBuilder( __CLASS__ ) )
			. '::onUserSetCookies)'
		);

		$sessionId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

		// No data
		$request = new \FauxRequest();
		$info = $provider->provideSessionInfo( $request );
		$this->assertNull( $info );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		// Session key only
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNotNull( $info );
		$this->assertSame( $params['priority'], $info->getPriority() );
		$this->assertSame( $sessionId, $info->getId() );
		$this->assertNotNull( $info->getUserInfo() );
		$this->assertSame( 0, $info->getUserInfo()->getId() );
		$this->assertNull( $info->getUserInfo()->getName() );
		$this->assertFalse( $info->forceHTTPS() );
		$this->assertSame( [
			[
				LogLevel::DEBUG,
				'Session "{session}" requested without UserID cookie',
			],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		// User, no session key
		$request = new \FauxRequest();
		$request->setCookies( [
			'xUserID' => $id,
			'xToken' => $token,
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNotNull( $info );
		$this->assertSame( $params['priority'], $info->getPriority() );
		$this->assertNotSame( $sessionId, $info->getId() );
		$this->assertNotNull( $info->getUserInfo() );
		$this->assertSame( $id, $info->getUserInfo()->getId() );
		$this->assertSame( $name, $info->getUserInfo()->getName() );
		$this->assertFalse( $info->forceHTTPS() );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		// User and session key
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
			'xUserID' => $id,
			'xToken' => $token,
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNotNull( $info );
		$this->assertSame( $params['priority'], $info->getPriority() );
		$this->assertSame( $sessionId, $info->getId() );
		$this->assertNotNull( $info->getUserInfo() );
		$this->assertSame( $id, $info->getUserInfo()->getId() );
		$this->assertSame( $name, $info->getUserInfo()->getName() );
		$this->assertFalse( $info->forceHTTPS() );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		// User with bad token
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
			'xUserID' => $id,
			'xToken' => 'BADTOKEN',
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNull( $info );
		$this->assertSame( [
			[
				LogLevel::WARNING,
				'Session "{session}" requested with invalid Token cookie.'
			],
		], $logger->getBuffer() );
		$logger->clearBuffer();

		// User id with no token
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
			'xUserID' => $id,
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNotNull( $info );
		$this->assertSame( $params['priority'], $info->getPriority() );
		$this->assertSame( $sessionId, $info->getId() );
		$this->assertNotNull( $info->getUserInfo() );
		$this->assertFalse( $info->getUserInfo()->isVerified() );
		$this->assertSame( $id, $info->getUserInfo()->getId() );
		$this->assertSame( $name, $info->getUserInfo()->getName() );
		$this->assertFalse( $info->forceHTTPS() );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		$request = new \FauxRequest();
		$request->setCookies( [
			'xUserID' => $id,
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNull( $info );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		// User and session key, with forceHTTPS flag
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
			'xUserID' => $id,
			'xToken' => $token,
			'forceHTTPS' => true,
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNotNull( $info );
		$this->assertSame( $params['priority'], $info->getPriority() );
		$this->assertSame( $sessionId, $info->getId() );
		$this->assertNotNull( $info->getUserInfo() );
		$this->assertSame( $id, $info->getUserInfo()->getId() );
		$this->assertSame( $name, $info->getUserInfo()->getName() );
		$this->assertTrue( $info->forceHTTPS() );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		// Invalid user id
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
			'xUserID' => '-1',
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNull( $info );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		// User id with matching name
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
			'xUserID' => $id,
			'xUserName' => $name,
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNotNull( $info );
		$this->assertSame( $params['priority'], $info->getPriority() );
		$this->assertSame( $sessionId, $info->getId() );
		$this->assertNotNull( $info->getUserInfo() );
		$this->assertFalse( $info->getUserInfo()->isVerified() );
		$this->assertSame( $id, $info->getUserInfo()->getId() );
		$this->assertSame( $name, $info->getUserInfo()->getName() );
		$this->assertFalse( $info->forceHTTPS() );
		$this->assertSame( [], $logger->getBuffer() );
		$logger->clearBuffer();

		// User id with wrong name
		$request = new \FauxRequest();
		$request->setCookies( [
			'session' => $sessionId,
			'xUserID' => $id,
			'xUserName' => 'Wrong',
		], '' );
		$info = $provider->provideSessionInfo( $request );
		$this->assertNull( $info );
		$this->assertSame( [
			[
				LogLevel::WARNING,
				'Session "{session}" requested with mismatched UserID and UserName cookies.',
			],
		], $logger->getBuffer() );
		$logger->clearBuffer();
	}

	public function testGetVaryCookies() {
		$provider = new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'cookieOptions' => [ 'prefix' => 'MyCookiePrefix' ],
		] );
		$this->assertArrayEquals( [
			'MyCookiePrefixToken',
			'MyCookiePrefixLoggedOut',
			'MySessionName',
			'forceHTTPS',
		], $provider->getVaryCookies() );
	}

	public function testSuggestLoginUsername() {
		$provider = new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'cookieOptions' => [ 'prefix' => 'x' ],
		] );

		$request = new \FauxRequest();
		$this->assertNull( $provider->suggestLoginUsername( $request ) );

		$request->setCookies( [
			'xUserName' => 'Example',
		], '' );
		$this->assertEquals( 'Example', $provider->suggestLoginUsername( $request ) );
	}

	/** @dataProvider provideForceHTTPS */
	public function testPersistSession( $forceHTTPS ) {
		$provider = new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'callUserSetCookiesHook' => false,
			'cookieOptions' => [ 'prefix' => 'x' ],
		] );
		$config = $this->getConfig();
		$config->set( 'ForceHTTPS', $forceHTTPS );
		$hookContainer = $this->getHookContainer();
		$provider->init(
			new TestLogger(),
			$config,
			SessionManager::singleton(),
			$hookContainer,
			$this->createNoOpMock( UserNameUtils::class )
		);

		$sessionId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		$store = new TestBagOStuff();

		// For User::requiresHTTPS
		$this->setMwGlobals( [ 'wgForceHTTPS' => $forceHTTPS ] );

		$user = static::getTestSysop()->getUser();
		$anon = new User;

		$backend = new SessionBackend(
			new SessionId( $sessionId ),
			new SessionInfo( SessionInfo::MIN_PRIORITY, [
				'provider' => $provider,
				'id' => $sessionId,
				'persisted' => true,
				'idIsSafe' => true,
			] ),
			$store,
			new NullLogger(),
			$hookContainer,
			10
		);
		TestingAccessWrapper::newFromObject( $backend )->usePhpSessionHandling = false;

		$mock = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'onUserSetCookies' ] )
			->getMock();
		$mock->expects( $this->never() )->method( 'onUserSetCookies' );
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'UserSetCookies' => [ $mock ] ] );

		// Anonymous user
		$backend->setUser( $anon );
		$backend->setRememberUser( true );
		$backend->setForceHTTPS( false );
		$request = new \FauxRequest();
		$provider->persistSession( $backend, $request );
		$this->assertSame( $sessionId, $request->response()->getCookie( 'MySessionName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xUserID' ) );
		$this->assertSame( null, $request->response()->getCookie( 'xUserName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xToken' ) );
		if ( $forceHTTPS ) {
			$this->assertSame( null, $request->response()->getCookie( 'forceHTTPS' ) );
		} else {
			$this->assertSame( '', $request->response()->getCookie( 'forceHTTPS' ) );
		}
		$this->assertSame( [], $backend->getData() );

		// Logged-in user, no remember
		$backend->setUser( $user );
		$backend->setRememberUser( false );
		$backend->setForceHTTPS( false );
		$request = new \FauxRequest();
		$provider->persistSession( $backend, $request );
		$this->assertSame( $sessionId, $request->response()->getCookie( 'MySessionName' ) );
		$this->assertSame( (string)$user->getId(), $request->response()->getCookie( 'xUserID' ) );
		$this->assertSame( $user->getName(), $request->response()->getCookie( 'xUserName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xToken' ) );
		if ( $forceHTTPS ) {
			$this->assertSame( null, $request->response()->getCookie( 'forceHTTPS' ) );
		} else {
			$this->assertSame( '', $request->response()->getCookie( 'forceHTTPS' ) );
		}
		$this->assertSame( [], $backend->getData() );

		// Logged-in user, remember
		$backend->setUser( $user );
		$backend->setRememberUser( true );
		$backend->setForceHTTPS( true );
		$request = new \FauxRequest();
		$time = time();
		$provider->persistSession( $backend, $request );
		$this->assertSame( $sessionId, $request->response()->getCookie( 'MySessionName' ) );
		$this->assertSame( (string)$user->getId(), $request->response()->getCookie( 'xUserID' ) );
		$this->assertSame( $user->getName(), $request->response()->getCookie( 'xUserName' ) );
		$this->assertSame( $user->getToken(), $request->response()->getCookie( 'xToken' ) );
		if ( $forceHTTPS ) {
			$this->assertSame( null, $request->response()->getCookie( 'forceHTTPS' ) );
		} else {
			$this->assertSame( 'true', $request->response()->getCookie( 'forceHTTPS' ) );
		}
		$this->assertSame( [], $backend->getData() );
	}

	/**
	 * @dataProvider provideCookieData
	 * @param bool $secure
	 * @param bool $remember
	 * @param bool $forceHTTPS
	 */
	public function testCookieData( $secure, $remember, $forceHTTPS ) {
		$this->setMwGlobals( [
			'wgSecureLogin' => false,
			'wgForceHTTPS' => $forceHTTPS,
		] );

		$provider = new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'callUserSetCookiesHook' => false,
			'cookieOptions' => [ 'prefix' => 'x' ],
		] );
		$config = $this->getConfig();
		$config->set( 'CookieSecure', $secure );
		$config->set( 'ForceHTTPS', $forceHTTPS );
		$hookContainer = $this->getHookContainer();
		$provider->init(
			new TestLogger(),
			$config,
			SessionManager::singleton(),
			$hookContainer,
			$this->createNoOpMock( UserNameUtils::class )
		);

		$sessionId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		$user = static::getTestSysop()->getUser();
		$this->assertSame( $user->requiresHTTPS(), $forceHTTPS, 'sanity check' );

		$backend = new SessionBackend(
			new SessionId( $sessionId ),
			new SessionInfo( SessionInfo::MIN_PRIORITY, [
				'provider' => $provider,
				'id' => $sessionId,
				'persisted' => true,
				'idIsSafe' => true,
			] ),
			new TestBagOStuff(),
			new NullLogger(),
			$hookContainer,
			10
		);
		TestingAccessWrapper::newFromObject( $backend )->usePhpSessionHandling = false;
		$backend->setUser( $user );
		$backend->setRememberUser( $remember );
		$backend->setForceHTTPS( $secure );
		$request = new \FauxRequest();
		$time = time();
		$provider->persistSession( $backend, $request );

		$defaults = [
			'expire' => (int)100,
			'path' => $config->get( 'CookiePath' ),
			'domain' => $config->get( 'CookieDomain' ),
			'secure' => $secure || $forceHTTPS,
			'httpOnly' => $config->get( 'CookieHttpOnly' ),
			'raw' => false,
		];

		$normalExpiry = $config->get( 'CookieExpiration' );
		$extendedExpiry = $config->get( 'ExtendedLoginCookieExpiration' );
		$extendedExpiry = (int)( $extendedExpiry ?? 0 );
		$expect = [
			'MySessionName' => [
				'value' => (string)$sessionId,
				'expire' => 0,
			] + $defaults,
			'xUserID' => [
				'value' => (string)$user->getId(),
				'expire' => $remember ? $extendedExpiry : $normalExpiry,
			] + $defaults,
			'xUserName' => [
				'value' => $user->getName(),
				'expire' => $remember ? $extendedExpiry : $normalExpiry
			] + $defaults,
			'xToken' => [
				'value' => $remember ? $user->getToken() : '',
				'expire' => $remember ? $extendedExpiry : -31536000,
			] + $defaults
		];
		if ( !$forceHTTPS ) {
			$expect['forceHTTPS'] = [
				'value' => $secure ? 'true' : '',
				'secure' => false,
				'expire' => $secure ? ( $remember ? $defaults['expire'] : 0 ) : -31536000,
			] + $defaults;
		}
		foreach ( $expect as $key => $value ) {
			$actual = $request->response()->getCookieData( $key );
			if ( $actual && $actual['expire'] > 0 ) {
				// Round expiry so we don't randomly fail if the seconds ticked during the test.
				$actual['expire'] = round( $actual['expire'] - $time, -2 );
			}
			$this->assertEquals( $value, $actual, "Cookie $key" );
		}
	}

	public static function provideCookieData() {
		return \ArrayUtils::cartesianProduct(
			[ false, true ], // $secure
			[ false, true ], // $remember
			[ false, true ] // $forceHTTPS
		);
	}

	protected function getSentRequest() {
		$sentResponse = $this->getMockBuilder( \FauxResponse::class )
			->onlyMethods( [ 'headersSent', 'setCookie', 'header' ] )->getMock();
		$sentResponse->method( 'headersSent' )
			->willReturn( true );
		$sentResponse->expects( $this->never() )->method( 'setCookie' );
		$sentResponse->expects( $this->never() )->method( 'header' );

		$sentRequest = $this->getMockBuilder( \FauxRequest::class )
			->onlyMethods( [ 'response' ] )->getMock();
		$sentRequest->method( 'response' )
			->willReturn( $sentResponse );
		return $sentRequest;
	}

	public function testPersistSessionWithHook() {
		$hookContainer = $this->getHookContainer();
		$provider = new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'callUserSetCookiesHook' => true,
			'cookieOptions' => [ 'prefix' => 'x' ],
		] );
		$provider->init(
			new NullLogger(),
			$this->getConfig(),
			SessionManager::singleton(),
			$hookContainer,
			$this->createNoOpMock( UserNameUtils::class )
		);

		// For User::requiresHTTPS
		$this->setMwGlobals( [ 'wgForceHTTPS' => false ] );

		$sessionId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		$store = new TestBagOStuff();
		$user = static::getTestSysop()->getUser();
		$anon = new User;

		$backend = new SessionBackend(
			new SessionId( $sessionId ),
			new SessionInfo( SessionInfo::MIN_PRIORITY, [
				'provider' => $provider,
				'id' => $sessionId,
				'persisted' => true,
				'idIsSafe' => true,
			] ),
			$store,
			new NullLogger(),
			$hookContainer,
			10
		);
		TestingAccessWrapper::newFromObject( $backend )->usePhpSessionHandling = false;

		// Anonymous user
		$mock = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'onUserSetCookies' ] )->getMock();
		$mock->expects( $this->never() )->method( 'onUserSetCookies' );
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'UserSetCookies' => [ $mock ] ] );
		$backend->setUser( $anon );
		$backend->setRememberUser( true );
		$backend->setForceHTTPS( false );
		$request = new \FauxRequest();
		$provider->persistSession( $backend, $request );
		$this->assertSame( $sessionId, $request->response()->getCookie( 'MySessionName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xUserID' ) );
		$this->assertSame( null, $request->response()->getCookie( 'xUserName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xToken' ) );
		$this->assertSame( '', $request->response()->getCookie( 'forceHTTPS' ) );
		$this->assertSame( [], $backend->getData() );

		$provider->persistSession( $backend, $this->getSentRequest() );

		// Logged-in user, no remember
		$mock = $this->getMockBuilder( __CLASS__ )
			->onlyMethods( [ 'onUserSetCookies' ] )->getMock();
		$mock->expects( $this->once() )->method( 'onUserSetCookies' )
			->will( $this->returnCallback( function ( $u, &$sessionData, &$cookies ) use ( $user ) {
				$this->assertSame( $user, $u );
				$this->assertEquals( [
					'wsUserID' => $user->getId(),
					'wsUserName' => $user->getName(),
					'wsToken' => $user->getToken(),
				], $sessionData );
				$this->assertEquals( [
					'UserID' => $user->getId(),
					'UserName' => $user->getName(),
					'Token' => false,
				], $cookies );

				$sessionData['foo'] = 'foo!';
				$cookies['bar'] = 'bar!';
				return true;
			} ) );
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'UserSetCookies' => [ $mock ] ] );
		$backend->setUser( $user );
		$backend->setRememberUser( false );
		$backend->setForceHTTPS( false );
		$backend->setLoggedOutTimestamp( $loggedOut = time() );
		$request = new \FauxRequest();

		$this->hideDeprecated( 'UserSetCookies hook (used in onUserSetCookies)' );

		$provider->persistSession( $backend, $request );
		$this->assertSame( $sessionId, $request->response()->getCookie( 'MySessionName' ) );
		$this->assertSame( (string)$user->getId(), $request->response()->getCookie( 'xUserID' ) );
		$this->assertSame( $user->getName(), $request->response()->getCookie( 'xUserName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xToken' ) );
		$this->assertSame( '', $request->response()->getCookie( 'forceHTTPS' ) );
		$this->assertSame( 'bar!', $request->response()->getCookie( 'xbar' ) );
		$this->assertSame( (string)$loggedOut, $request->response()->getCookie( 'xLoggedOut' ) );
		$this->assertEquals( [
			'wsUserID' => $user->getId(),
			'wsUserName' => $user->getName(),
			'wsToken' => $user->getToken(),
			'foo' => 'foo!',
		], $backend->getData() );

		$provider->persistSession( $backend, $this->getSentRequest() );

		// Logged-in user, remember
		$mock = $this->getMockBuilder( __CLASS__ )
			->onlyMethods( [ 'onUserSetCookies' ] )->getMock();
		$mock->expects( $this->once() )->method( 'onUserSetCookies' )
			->will( $this->returnCallback( function ( $u, &$sessionData, &$cookies ) use ( $user ) {
				$this->assertSame( $user, $u );
				$this->assertEquals( [
					'wsUserID' => $user->getId(),
					'wsUserName' => $user->getName(),
					'wsToken' => $user->getToken(),
				], $sessionData );
				$this->assertEquals( [
					'UserID' => $user->getId(),
					'UserName' => $user->getName(),
					'Token' => $user->getToken(),
				], $cookies );

				$sessionData['foo'] = 'foo 2!';
				$cookies['bar'] = 'bar 2!';
				return true;
			} ) );
		$this->mergeMwGlobalArrayValue( 'wgHooks', [ 'UserSetCookies' => [ $mock ] ] );
		$backend->setUser( $user );
		$backend->setRememberUser( true );
		$backend->setForceHTTPS( true );
		$backend->setLoggedOutTimestamp( 0 );
		$request = new \FauxRequest();
		$provider->persistSession( $backend, $request );
		$this->assertSame( $sessionId, $request->response()->getCookie( 'MySessionName' ) );
		$this->assertSame( (string)$user->getId(), $request->response()->getCookie( 'xUserID' ) );
		$this->assertSame( $user->getName(), $request->response()->getCookie( 'xUserName' ) );
		$this->assertSame( $user->getToken(), $request->response()->getCookie( 'xToken' ) );
		$this->assertSame( 'true', $request->response()->getCookie( 'forceHTTPS' ) );
		$this->assertSame( 'bar 2!', $request->response()->getCookie( 'xbar' ) );
		$this->assertSame( null, $request->response()->getCookie( 'xLoggedOut' ) );
		$this->assertEquals( [
			'wsUserID' => $user->getId(),
			'wsUserName' => $user->getName(),
			'wsToken' => $user->getToken(),
			'foo' => 'foo 2!',
		], $backend->getData() );

		$provider->persistSession( $backend, $this->getSentRequest() );
	}

	public function testUnpersistSession() {
		$provider = new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'cookieOptions' => [ 'prefix' => 'x' ],
		] );
		$provider->init(
			new NullLogger(),
			$this->getConfig(),
			SessionManager::singleton(),
			$this->getHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);

		$request = new \FauxRequest();
		$provider->unpersistSession( $request );
		$this->assertSame( '', $request->response()->getCookie( 'MySessionName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xUserID' ) );
		$this->assertSame( null, $request->response()->getCookie( 'xUserName' ) );
		$this->assertSame( '', $request->response()->getCookie( 'xToken' ) );
		$this->assertSame( '', $request->response()->getCookie( 'forceHTTPS' ) );

		$provider->unpersistSession( $this->getSentRequest() );
	}

	public function testSetLoggedOutCookie() {
		$provider = TestingAccessWrapper::newFromObject( new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'cookieOptions' => [ 'prefix' => 'x' ],
		] ) );
		$provider->init(
			new NullLogger(),
			$this->getConfig(),
			SessionManager::singleton(),
			$this->getHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);

		$t1 = time();
		$t2 = time() - 86400 * 2;

		// Set it
		$request = new \FauxRequest();
		$provider->setLoggedOutCookie( $t1, $request );
		$this->assertSame( (string)$t1, $request->response()->getCookie( 'xLoggedOut' ) );

		// Too old
		$request = new \FauxRequest();
		$provider->setLoggedOutCookie( $t2, $request );
		$this->assertSame( null, $request->response()->getCookie( 'xLoggedOut' ) );

		// Don't reset if it's already set
		$request = new \FauxRequest();
		$request->setCookies( [
			'xLoggedOut' => $t1,
		], '' );
		$provider->setLoggedOutCookie( $t1, $request );
		$this->assertSame( null, $request->response()->getCookie( 'xLoggedOut' ) );
	}

	/**
	 * To be mocked for hooks, since PHPUnit can't otherwise mock methods that
	 * take references.
	 * @param User $user
	 * @param array &$sessionData
	 * @param string[] &$cookies
	 */
	public function onUserSetCookies( $user, &$sessionData, &$cookies ) {
	}

	public function testGetCookie() {
		$provider = new CookieSessionProvider( [
			'priority' => 1,
			'sessionName' => 'MySessionName',
			'cookieOptions' => [ 'prefix' => 'x' ],
		] );
		$provider->init(
			new NullLogger(),
			$this->getConfig(),
			SessionManager::singleton(),
			$this->getHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);
		$provider = TestingAccessWrapper::newFromObject( $provider );

		$request = new \FauxRequest();
		$request->setCookies( [
			'xFoo' => 'foo!',
			'xBar' => 'deleted',
		], '' );
		$this->assertSame( 'foo!', $provider->getCookie( $request, 'Foo', 'x' ) );
		$this->assertNull( $provider->getCookie( $request, 'Bar', 'x' ) );
		$this->assertNull( $provider->getCookie( $request, 'Baz', 'x' ) );
	}

	public function testGetRememberUserDuration() {
		$config = $this->getConfig();
		$provider = new CookieSessionProvider( [ 'priority' => 10 ] );
		$provider->init(
			new NullLogger(),
			$config,
			SessionManager::singleton(),
			$this->getHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);

		$this->assertSame( 200, $provider->getRememberUserDuration() );

		$config->set( 'ExtendedLoginCookieExpiration', null );

		$this->assertSame( 100, $provider->getRememberUserDuration() );

		$config->set( 'ExtendedLoginCookieExpiration', 0 );

		$this->assertSame( null, $provider->getRememberUserDuration() );
	}

	public function testGetLoginCookieExpiration() {
		$config = $this->getConfig();
		$provider = TestingAccessWrapper::newFromObject( new CookieSessionProvider( [
			'priority' => 10
		] ) );
		$provider->init(
			new NullLogger(),
			$config,
			SessionManager::singleton(),
			$this->getHookContainer(),
			$this->createNoOpMock( UserNameUtils::class )
		);

		// First cookie is an extended cookie, remember me true
		$this->assertSame( 200, $provider->getLoginCookieExpiration( 'Token', true ) );
		$this->assertSame( 100, $provider->getLoginCookieExpiration( 'User', true ) );

		// First cookie is an extended cookie, remember me false
		$this->assertSame( 100, $provider->getLoginCookieExpiration( 'UserID', false ) );
		$this->assertSame( 100, $provider->getLoginCookieExpiration( 'User', false ) );

		$config->set( 'ExtendedLoginCookieExpiration', null );

		$this->assertSame( 100, $provider->getLoginCookieExpiration( 'Token', true ) );
		$this->assertSame( 100, $provider->getLoginCookieExpiration( 'User', true ) );

		$this->assertSame( 100, $provider->getLoginCookieExpiration( 'Token', false ) );
		$this->assertSame( 100, $provider->getLoginCookieExpiration( 'User', false ) );
	}
}
