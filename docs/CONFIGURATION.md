# Configuration

This package has **no configuration tree** and reads no environment variables. Everything is passed in code:

| Where | Option | Default | Meaning |
|-------|--------|---------|---------|
| `forConnection()` / `forEntityManager()` | `$retryAfterSeconds` | `1` | Hint carried by `TransientStorageFailure::retryAfterSeconds()` (`null`: no hint) |
| `DoctrineTransactionRunner::forEntityManager()` | `$registry`, `$managerName` | `null` | Reset a closed manager through the registry; without one the manager is cleared |
| `ConnectionRepairer::__construct()` | `$emulatedNesting` | `null` (detect) | Force DBAL 3 emulated-nesting handling on or off |

The test suite reads `TD_MYSQL_URL`, `TD_MARIADB_URL` and `TD_POSTGRES_URL` (real-database suite only); `make
test-with-db` sets them for the Docker containers.
