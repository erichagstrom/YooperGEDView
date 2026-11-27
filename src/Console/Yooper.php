<?php

declare(strict_types=1);

namespace YooperGEDView\Console;

use InvalidArgumentException;
use PDOException;
use YooperGEDView\Database\ConnectionFactory;
use YooperGEDView\Security\PasswordHasher;
use YooperGEDView\Security\PasswordPolicy;
use YooperGEDView\Upgrade\LegacySiteRepository;
use YooperGEDView\Upgrade\UpgradeFromPgv;
use YooperGEDView\User\Administrator;
use YooperGEDView\User\AdministratorRepository;
use YooperGEDView\User\UserRepository;

/**
 * The command-line tool, bin/yooper. "install" creates the first administrator, which the web installer used
 * to do from a page that anyone could reach first. "set-password" gives an existing user a new password, for
 * when the link by mail cannot be used: no mail set up, or an account carried over without a usable password.
 * "upgrade-from-pgv" prepares a copy of a PhpGedView site for this program.
 */
final readonly class Yooper
{
    public const int OK = 0;
    public const int FAILED = 1;
    public const int WRONG_USE = 2;

    private const string USAGE = <<<'TEXT'
        Usage: bin/yooper install <user-name> --email=<address> [--first-name=<name>] [--last-name=<name>]
               bin/yooper set-password <user-name>
               bin/yooper upgrade-from-pgv --index=<folder> [--apply]

        install            Creates the site's first administrator.
        set-password       Gives an existing user a new password and signs them out everywhere.
        upgrade-from-pgv   Looks over a copy of a PhpGedView 4.3.0 site (its database, and its "index" folder)
                           and says what moving it to this program involves. With --apply it makes the
                           changes that are needed before the program is started on it. Use a copy.

        The password is read from standard input, one line, so that it does not show up in the list of
        running programs or in the shell's history:

            bin/yooper install anna --email=anna@example.org < password-file

        The database is found through the environment: DB_TYPE (mysql, pgsql or sqlite), DB_HOST, DB_PORT,
        DB_NAME, DB_USER, DB_PASSWORD, and DB_PREFIX for the table prefix (pgv_ when not set).

        TEXT;

    public function __construct(private int $now) {}

    /**
     * @param list<string> $arguments the command line without the program's own name
     * @param array<string, string> $environment
     * @param resource $input where the password is read from
     * @param resource $output
     * @param resource $errors
     */
    public function run(array $arguments, array $environment, $input, $output, $errors): int
    {
        if ($arguments === [] || in_array($arguments[0], ['help', '--help', '-h'], true)) {
            fwrite($output, self::USAGE);

            return $arguments === [] ? self::WRONG_USE : self::OK;
        }

        try {
            $message = match ($arguments[0]) {
                'install' => $this->install($this->administratorFrom(array_slice($arguments, 1)), $environment, $input),
                'set-password' => $this->setPassword($this->userNameFrom(array_slice($arguments, 1)), $environment, $input),
                'upgrade-from-pgv' => $this->upgradeFromPgv(array_slice($arguments, 1), $environment),
                default => throw new InvalidArgumentException('Unknown command "' . $arguments[0] . '".'),
            };
        } catch (InvalidArgumentException $exception) {
            fwrite($errors, $exception->getMessage() . "\n\n" . self::USAGE);

            return self::WRONG_USE;
        } catch (CommandFailed $exception) {
            fwrite($errors, $exception->getMessage() . "\n");

            return self::FAILED;
        } catch (PDOException $exception) {
            fwrite($errors, 'The database refused: ' . $exception->getMessage() . "\n");

            return self::FAILED;
        }
        fwrite($output, $message . "\n");

        return self::OK;
    }

    /**
     * @param array<string, string> $environment
     * @param resource $input
     */
    private function install(Administrator $administrator, array $environment, $input): string
    {
        $repository = new AdministratorRepository($this->database($environment), $this->tablePrefix($environment));
        if (!$repository->tablesExist()) {
            throw new CommandFailed('The database has no tables yet. Open the site in a browser once, then run this again.');
        }
        if ($repository->administratorExists()) {
            throw new CommandFailed("This site already has an administrator. Further ones are added on the site's own pages.");
        }
        if ($repository->userNameIsTaken($administrator->userName)) {
            throw new CommandFailed('There is already a user called "' . $administrator->userName . '".');
        }
        $repository->create($administrator, (new PasswordHasher())->hash($this->passwordFrom($input)), $this->now);

        return 'Administrator "' . $administrator->userName . '" created. Sign in on the site to add a family tree.';
    }

    /**
     * @param array<string, string> $environment
     * @param resource $input
     */
    private function setPassword(string $userName, array $environment, $input): string
    {
        $prefix = $this->tablePrefix($environment);
        $database = $this->database($environment);
        if (!(new AdministratorRepository($database, $prefix))->tablesExist()) {
            throw new CommandFailed('The database has no tables yet. Open the site in a browser once, then run this again.');
        }
        $users = new UserRepository($database, $prefix);
        $userId = $users->idOf($userName);
        if ($userId === null) {
            throw new CommandFailed('There is no user called "' . $userName . '".');
        }
        $users->setPasswordHash($userId, (new PasswordHasher())->hash($this->passwordFrom($input)));
        // A new mark ends every session the user has open: see endOtherSessions() in the program.
        $users->setSetting($userId, 'session_mark', bin2hex(random_bytes(8)));

        return 'The password of "' . $userName . '" has been changed, and their open sessions are ended.';
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    private function upgradeFromPgv(array $arguments, array $environment): string
    {
        $index = null;
        $apply = false;
        foreach ($arguments as $argument) {
            if ($argument === '--apply') {
                $apply = true;
            } elseif (str_starts_with($argument, '--index=')) {
                $index = rtrim(substr($argument, 8), '/');
            } else {
                throw new InvalidArgumentException('upgrade-from-pgv takes --index=<folder> and, to make changes, --apply.');
            }
        }
        if ($index === null || $index === '') {
            throw new InvalidArgumentException('Say where the old site\'s index folder is: --index=<folder>.');
        }
        if (!is_dir($index)) {
            throw new CommandFailed('There is no folder at ' . $index . '.');
        }
        $prefix = $this->tablePrefix($environment);
        $database = $this->database($environment);
        if (!(new AdministratorRepository($database, $prefix))->tablesExist()) {
            throw new CommandFailed('The database has no user tables. Is this the database of the PhpGedView site?');
        }

        return implode("\n", (new UpgradeFromPgv(new LegacySiteRepository($database, $prefix), $index))->run($apply));
    }

    /**
     * @param array<string, string> $environment
     */
    private function database(array $environment): \PDO
    {
        try {
            return (new ConnectionFactory())->fromEnvironment($environment);
        } catch (InvalidArgumentException $exception) {
            throw new CommandFailed($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function tablePrefix(array $environment): string
    {
        $prefix = $environment['DB_PREFIX'] ?? 'pgv_';
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new CommandFailed('A table prefix may hold only letters, digits and underscores. Check DB_PREFIX.');
        }

        return $prefix;
    }

    /**
     * @param resource $input
     */
    private function passwordFrom($input): string
    {
        $line = fgets($input);
        $password = $line === false ? '' : rtrim($line, "\r\n");
        $refusal = (new PasswordPolicy())->refusal($password);
        if ($refusal !== null) {
            throw new CommandFailed($refusal . ' It is read from standard input.');
        }

        return $password;
    }

    /**
     * @param list<string> $arguments
     */
    private function userNameFrom(array $arguments): string
    {
        if (count($arguments) !== 1 || str_starts_with($arguments[0], '--')) {
            throw new InvalidArgumentException('set-password takes one user name and nothing else.');
        }

        return $arguments[0];
    }

    /**
     * @param list<string> $arguments
     */
    private function administratorFrom(array $arguments): Administrator
    {
        $userName = null;
        $options = ['email' => null, 'first-name' => 'Site', 'last-name' => 'Administrator'];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $match) === 1) {
                if (!array_key_exists($match[1], $options)) {
                    throw new InvalidArgumentException('Unknown option --' . $match[1] . '.');
                }
                $options[$match[1]] = $match[2];
            } elseif (str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('Options are written --name=value.');
            } elseif ($userName === null) {
                $userName = $argument;
            } else {
                throw new InvalidArgumentException('Only one user name can be given.');
            }
        }
        if ($userName === null) {
            throw new InvalidArgumentException('No user name given.');
        }
        if ($options['email'] === null) {
            throw new InvalidArgumentException('No mail address given (--email=<address>).');
        }

        return new Administrator($userName, $options['email'], $options['first-name'], $options['last-name']);
    }
}
