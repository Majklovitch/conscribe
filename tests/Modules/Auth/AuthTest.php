<?php

declare(strict_types=1);

namespace Tests\Modules\Auth;

use App\Bootstrap;
use App\Core\Config;
use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use App\Core\Database\Migrations\Migrator;
use App\Core\Http\Response;
use App\Core\Http\Router;
use Modules\Auth\ApiTokens;
use Modules\Auth\Auth;
use Modules\Auth\Commands\TokenCreateCommand;
use Modules\Auth\Commands\UserCreateCommand;
use Modules\Auth\LoginResult;
use Modules\Auth\LoginThrottle;
use Modules\Auth\Middleware\Guest;
use Modules\Auth\Middleware\RequireLogin;
use Modules\Auth\Middleware\RequireToken;
use Modules\Auth\User;
use Modules\Auth\UserRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;
use Tests\Support\FakeSession;
use Tests\Support\RequestFactory;

/**
 * The Auth module against a real database.
 *
 * SQLite in memory, with the tables created by the module's own migrations -
 * so the migrations are exercised by every test, not trusted.
 */
#[CoversClass(Auth::class)]
#[CoversClass(ApiTokens::class)]
#[CoversClass(LoginThrottle::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(User::class)]
final class AuthTest extends TestCase {
    private const PASSWORD = 'correct horse battery';
    private const IP = '203.0.113.7';

    private PDO $db;
    private UserRepository $users;
    private ApiTokens $tokens;
    private ArraySession $session;
    private Auth $auth;
    private User $jan;

    protected function setUp(): void {
        // The lowest bcrypt cost: PHP's default makes each hash take a quarter
        // of a second, which turned this suite from milliseconds into
        // seconds. What is under test is the policy, not bcrypt's speed.
        Config::set(['auth' => ['hash_options' => ['cost' => 4]]]);

        $this->db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new Migrator($this->db, $this->migrationsPath()))->migrate();

        $this->users = new UserRepository($this->db);
        $this->tokens = new ApiTokens($this->db, $this->users);
        $this->session = new ArraySession();
        $this->auth = $this->freshAuth();

        $this->jan = $this->users->create('jan@example.cz', self::PASSWORD, 'Jan');
    }

    protected function tearDown(): void {
        Config::set([]);
    }

    private function migrationsPath(): string {
        return Bootstrap::root() . '/app/Modules/Auth/migrations';
    }

    /** A new Auth over the same session and database - the next request. */
    private function freshAuth(): Auth {
        return new Auth($this->users, $this->session, new LoginThrottle($this->db));
    }

    // -----------------------------------------------------------------
    // Migrations and storage
    // -----------------------------------------------------------------

    #[TestDox('the module migrations roll back cleanly')]
    public function testMigrationsRollBack(): void {
        $migrator = new Migrator($this->db, $this->migrationsPath());

        self::assertCount(3, $migrator->rollback());

        $tables = $this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('users', 'api_tokens', 'login_attempts')")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
    }

    #[TestDox('a created user has a hashed password and a normalised email')]
    public function testCreateHashesThePassword(): void {
        $user = $this->users->create('  Petra@Example.CZ ', 'another password');
        $stored = $this->db->query('SELECT password FROM users WHERE id = ' . $user->id)->fetchColumn();

        self::assertSame('petra@example.cz', $user->email);
        self::assertNotSame('another password', $stored);
        self::assertTrue(password_verify('another password', (string) $stored));
    }

    #[TestDox('a user never serialises its password hash')]
    public function testThePasswordHashIsNotExposed(): void {
        // Model puts unknown columns in a bag that toArray() and the JSON
        // include; an API returning a user would otherwise publish its hash.
        $user = $this->users->findById($this->jan->id);
        self::assertNotNull($user);

        $json = (string) json_encode($user);

        self::assertStringNotContainsString('password', $json);
        self::assertStringNotContainsString($user->passwordHash(), $json);
        self::assertArrayNotHasKey('password', $user->toArray());
        self::assertNull($user['password']);
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash()));
    }

    // -----------------------------------------------------------------
    // Logging in
    // -----------------------------------------------------------------

    #[TestDox('a correct password signs the user in and rotates the session id and CSRF token')]
    public function testSuccessfulAttempt(): void {
        self::assertSame(LoginResult::Success, $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP));

        self::assertTrue($this->auth->check());
        self::assertSame($this->jan->id, $this->auth->id());
        // Session fixation: an id planted before login must not survive it.
        self::assertSame(1, $this->session->regenerated);
        self::assertSame(1, $this->session->tokensRotated);
    }

    public function testWrongPasswordFails(): void {
        self::assertSame(LoginResult::Failed, $this->auth->attempt('jan@example.cz', 'wrong', self::IP));

        self::assertFalse($this->auth->check());
        self::assertSame(0, $this->session->regenerated);
    }

    public function testUnknownEmailFails(): void {
        self::assertSame(LoginResult::Failed, $this->auth->attempt('nobody@example.cz', self::PASSWORD, self::IP));
    }

    #[TestDox('the email is matched however it was typed')]
    public function testEmailIsCaseInsensitive(): void {
        self::assertSame(LoginResult::Success, $this->auth->attempt('  JAN@Example.cz ', self::PASSWORD, self::IP));
    }

    #[TestDox('the signed-in user is loaded back on the next request')]
    public function testSessionPersistsAcrossRequests(): void {
        $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP);

        self::assertSame($this->jan->id, $this->freshAuth()->id());
    }

    #[TestDox('a deleted account stops being signed in at once')]
    public function testDeletedUserIsSignedOut(): void {
        $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP);
        $this->users->delete($this->jan->id);

        self::assertNull($this->freshAuth()->user());
    }

    #[TestDox('a visitor with no session is not looked up - and not given one')]
    public function testNoSessionMeansNoLookup(): void {
        // The id is there, but the browser sent no session cookie: reading
        // would start a session and send one, for someone who never had one.
        $this->session->set('_auth_user_id', $this->jan->id);
        $this->session->exists = false;

        self::assertNull($this->freshAuth()->user());
    }

    public function testLogout(): void {
        $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP);
        $this->auth->logout();

        self::assertFalse($this->auth->check());
        self::assertNull($this->freshAuth()->user());
        self::assertSame(2, $this->session->regenerated);
    }

    #[TestDox('an outdated hash is upgraded on a successful login')]
    public function testHashIsUpgradedOnLogin(): void {
        $weak = password_hash('legacy password', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->db->prepare('INSERT INTO users (email, password, name, created_at, updated_at) VALUES (?, ?, NULL, ?, ?)')
            ->execute(['old@example.cz', $weak, '2020-01-01 00:00:00', '2020-01-01 00:00:00']);

        // The site has since raised its cost above the one the hash was made with.
        Config::set(['auth' => ['hash_options' => ['cost' => 5]]]);

        self::assertSame(LoginResult::Success, $this->auth->attempt('old@example.cz', 'legacy password', self::IP));

        $stored = (string) $this->db->query("SELECT password FROM users WHERE email = 'old@example.cz'")->fetchColumn();
        self::assertNotSame($weak, $stored);
        self::assertFalse(password_needs_rehash($stored, PASSWORD_DEFAULT, ['cost' => 5]));
        self::assertTrue(password_verify('legacy password', $stored));
    }

    // -----------------------------------------------------------------
    // Throttling
    // -----------------------------------------------------------------

    #[TestDox('five misses lock the account for that address, even against the right password')]
    public function testThrottleLocks(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt('jan@example.cz', 'wrong', self::IP);
        }

        self::assertSame(LoginResult::Throttled, $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP));
        self::assertFalse($this->auth->check());
    }

    #[TestDox('the lock is per address, so a guesser cannot lock the owner out')]
    public function testThrottleIsPerAddress(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->auth->attempt('jan@example.cz', 'wrong', '198.51.100.1');
        }

        self::assertSame(LoginResult::Success, $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP));
    }

    #[TestDox('a successful login clears the count')]
    public function testSuccessClearsTheCount(): void {
        for ($i = 0; $i < 4; $i++) {
            $this->auth->attempt('jan@example.cz', 'wrong', self::IP);
        }
        $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP);

        for ($i = 0; $i < 4; $i++) {
            $this->auth->attempt('jan@example.cz', 'wrong', self::IP);
        }

        // Eight misses in total, but never five in a row.
        self::assertSame(LoginResult::Success, $this->freshAuth()->attempt('jan@example.cz', self::PASSWORD, self::IP));
    }

    #[TestDox('the throttle table holds no email addresses')]
    public function testThrottleStoresNoEmails(): void {
        $this->auth->attempt('jan@example.cz', 'wrong', self::IP);

        $keys = $this->db->query('SELECT throttle_key FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN);

        self::assertCount(1, $keys);
        self::assertStringNotContainsString('jan', (string) $keys[0]);
    }

    // -----------------------------------------------------------------
    // API tokens
    // -----------------------------------------------------------------

    #[TestDox('a token is returned once and stored only as its hash')]
    public function testTokenIsStoredHashed(): void {
        $plain = $this->tokens->issue($this->jan, 'phone');
        $stored = $this->db->query('SELECT token_hash FROM api_tokens')->fetchAll(PDO::FETCH_COLUMN);

        self::assertStringStartsWith('cst_', $plain);
        self::assertSame([hash('sha256', $plain)], $stored);
        self::assertNotContains($plain, $stored);
    }

    public function testTokenResolvesToItsUser(): void {
        $plain = $this->tokens->issue($this->jan, 'phone');

        self::assertSame($this->jan->id, $this->tokens->userFor($plain)?->id);
    }

    #[TestDox('revoked, forged and orphaned tokens resolve to nobody')]
    public function testInvalidTokens(): void {
        $revoked = $this->tokens->issue($this->jan, 'old');
        self::assertTrue($this->tokens->revoke($revoked));

        self::assertNull($this->tokens->userFor($revoked));
        self::assertNull($this->tokens->userFor('cst_' . str_repeat('0', 64)));
        self::assertNull($this->tokens->userFor('not-even-the-right-prefix'));

        $orphan = $this->tokens->issue($this->jan, 'orphan');
        $this->users->delete($this->jan->id);
        self::assertNull($this->tokens->userFor($orphan));
    }

    // -----------------------------------------------------------------
    // Middleware
    // -----------------------------------------------------------------

    private function router(): Router {
        $router = new Router(new FakeSession());
        $auth = $this->auth;
        $whoami = static fn (): Response => Response::json(['user' => $auth->user()?->email]);

        $router->group(['middleware' => [new RequireToken($this->tokens, $this->auth)], 'csrf' => false], static function (Router $router) use ($whoami): void {
            $router->get('api/me', $whoami);
        });
        $router->group(['middleware' => [new RequireLogin($this->auth)]], static function (Router $router) use ($whoami): void {
            $router->get('account', $whoami);
        });
        $router->group(['middleware' => [new Guest($this->auth)]], static function (Router $router): void {
            $router->get('login', static fn (): Response => Response::json(['form' => true]));
        });

        return $router;
    }

    #[TestDox('RequireToken: no token is a 401 problem with WWW-Authenticate')]
    public function testRequireTokenRejects(): void {
        $response = $this->router()->handle(RequestFactory::make('GET', 'api/me', [], ['HTTP_ACCEPT' => 'application/json']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeader('Content-Type'));
        self::assertSame('Bearer', $response->getHeader('WWW-Authenticate'));
    }

    #[TestDox('RequireToken: a valid token lets the request in as its user')]
    public function testRequireTokenAdmits(): void {
        $plain = $this->tokens->issue($this->jan, 'phone');

        $response = $this->router()->handle(RequestFactory::make('GET', 'api/me', [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plain,
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['user' => 'jan@example.cz'], json_decode($response->getContent(), true));
    }

    #[TestDox('RequireLogin: a guest browser is sent to log in, and where it was going is remembered')]
    public function testRequireLoginRedirectsGuests(): void {
        $response = $this->router()->handle(RequestFactory::make('GET', 'account?tab=orders'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeader('Location'));
        self::assertSame('/account?tab=orders', $this->auth->intended());
    }

    #[TestDox('RequireLogin: a guest API client gets 401, not a redirect')]
    public function testRequireLoginAnswersJsonClients(): void {
        $response = $this->router()->handle(RequestFactory::make('GET', 'account', [], ['HTTP_ACCEPT' => 'application/json']));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeader('Content-Type'));
    }

    #[TestDox('RequireLogin: a signed-in user is let through')]
    public function testRequireLoginAdmits(): void {
        $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP);

        $response = $this->router()->handle(RequestFactory::make('GET', 'account'));

        self::assertSame(['user' => 'jan@example.cz'], json_decode($response->getContent(), true));
    }

    #[TestDox('Guest: a signed-in user is sent away from the login page')]
    public function testGuestRedirectsSignedInUsers(): void {
        self::assertSame(200, $this->router()->handle(RequestFactory::make('GET', 'login'))->getStatusCode());

        $this->auth->attempt('jan@example.cz', self::PASSWORD, self::IP);

        $response = $this->router()->handle(RequestFactory::make('GET', 'login'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeader('Location'));
    }

    // -----------------------------------------------------------------
    // Commands
    // -----------------------------------------------------------------

    /** @return array{0: int, 1: string} exit code and everything printed */
    private function runCommand(Command $command, Input $input): array {
        $stream = fopen('php://memory', 'r+b');
        $status = $command->run($input, new Output($stream, $stream, decorated: false));
        rewind($stream);

        return [$status, (string) stream_get_contents($stream)];
    }

    #[TestDox('user:create generates a password that then works')]
    public function testUserCreate(): void {
        [$status, $printed] = $this->runCommand(new UserCreateCommand($this->users), new Input(['petra@example.cz'], ['name' => 'Petra']));

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, preg_match('/^Password: (\S+)?$/m', $printed, $match));
        self::assertSame(LoginResult::Success, $this->auth->attempt('petra@example.cz', $match[1], self::IP));
        self::assertSame('Petra', $this->users->findByEmail('petra@example.cz')?->name);
    }

    public function testUserCreateRefusesDuplicatesAndBadEmails(): void {
        [$duplicate] = $this->runCommand(new UserCreateCommand($this->users), new Input(['JAN@example.cz']));
        [$invalid] = $this->runCommand(new UserCreateCommand($this->users), new Input(['not-an-email']));

        self::assertSame(Command::FAILURE, $duplicate);
        self::assertSame(Command::FAILURE, $invalid);
    }

    #[TestDox('token:create prints a token that authenticates')]
    public function testTokenCreate(): void {
        [$status, $printed] = $this->runCommand(new TokenCreateCommand($this->users, $this->tokens), new Input(['jan@example.cz', 'ci']));

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, preg_match('/^(cst_[0-9a-f]{64})?$/m', $printed, $match));
        self::assertSame($this->jan->id, $this->tokens->userFor($match[1])?->id);
    }
}
