<?php

declare(strict_types=1);

namespace YooperGEDView\Console;

use InvalidArgumentException;
use PDOException;
use YooperGEDView\Database\ConnectionFactory;
use YooperGEDView\Security\PasswordHasher;
use YooperGEDView\User\Administrator;
use YooperGEDView\User\AdministratorRepository;

/**
 * The command-line tool, bin/yooper. One command so far: "install" creates the first administrator, which the
 * web installer used to do from a page that anyone could reach first.
 */
final readonly class Yooper
{
    public const int OK = 0;
    public const int FAILED = 1;
    public const int WRONG_USE = 2;

    private const int SHORTEST_PASSWORD = 12;

    private const string USAGE = <<<'TEXT'
        Usage: bin/yooper install <user-name> --email=<address> [--first-name=<name>] [--last-name=<name>]

        Creates the site's first administrator. The password is read from standard input, one line, so that
        it does not show up in the list of running programs or in the shell's history:

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
        if ($arguments[0] !== 'install') {
            fwrite($errors, 'Unknown command "' . $arguments[0] . "\".\n\n" . self::USAGE);

            return self::WRONG_USE;
        }

        try {
            $administrator = $this->administratorFrom(array_slice($arguments, 1));
        } catch (InvalidArgumentException $exception) {
            fwrite($errors, $exception->getMessage() . "\n\n" . self::USAGE);

            return self::WRONG_USE;
        }

        try {
            $repository = new AdministratorRepository(
                (new ConnectionFactory())->fromEnvironment($environment),
                $environment['DB_PREFIX'] ?? 'pgv_',
            );
            if (!$repository->tablesExist()) {
                fwrite($errors, "The database has no tables yet. Open the site in a browser once, then run this again.\n");

                return self::FAILED;
            }
            if ($repository->administratorExists()) {
                fwrite($errors, "This site already has an administrator. Further ones are added on the site's own pages.\n");

                return self::FAILED;
            }
            if ($repository->userNameIsTaken($administrator->userName)) {
                fwrite($errors, 'There is already a user called "' . $administrator->userName . "\".\n");

                return self::FAILED;
            }
            $line = fgets($input);
            $password = $line === false ? '' : rtrim($line, "\r\n");
            if (mb_strlen($password) < self::SHORTEST_PASSWORD) {
                fwrite($errors, 'The password on standard input must be at least ' . self::SHORTEST_PASSWORD . " characters.\n");

                return self::FAILED;
            }
            $repository->create($administrator, (new PasswordHasher())->hash($password), $this->now);
        } catch (InvalidArgumentException $exception) {
            fwrite($errors, $exception->getMessage() . "\n");

            return self::FAILED;
        } catch (PDOException $exception) {
            fwrite($errors, 'The database refused: ' . $exception->getMessage() . "\n");

            return self::FAILED;
        }

        fwrite($output, 'Administrator "' . $administrator->userName . "\" created. Sign in on the site to add a family tree.\n");

        return self::OK;
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
