<?php

declare(strict_types=1);

// Подключение автозагрузчика Composer.
// Предполагается, что папка vendor находится на одном уровне с этим файлом (в корне проекта).
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) { // Если модуль в _support, а vendor в корне
    require_once __DIR__ . '/../../vendor/autoload.php';
}


use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\MissingExtensionException; // Используется в ApcuSimpleCache
use Psr\SimpleCache\CacheInterface;

/**
 * LogManager предоставляет централизованный доступ к экземпляру логгера Monolog
 * и настраивает глобальные обработчики ошибок и исключений.
 */
final class LogManager
{
    private static ?Logger $logger = null;
    private static bool $handlerFailed = false; // Флаг неудачной инициализации основного обработчика

    public static function getLogger(): Logger
    {
        if (self::$logger === null && !self::$handlerFailed) {
            try {
                $formatter = new LineFormatter(null, null, true, true);
                $formatter->includeStacktraces(true);

                $logFilePath = __DIR__ . '/app.log'; // Путь к лог-файлу относительно этого файла (корень проекта)
                $logDir = dirname($logFilePath);
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0775, true);
                }

                if (!is_writable($logDir) || (file_exists($logFilePath) && !is_writable($logFilePath))) {
                    $errorMessage = "Каталог или файл логов ({$logFilePath}) недоступен для записи. Проверьте права доступа.";
                    error_log($errorMessage);
                    if (php_sapi_name() === 'cli') {
                        file_put_contents('php://stderr', $errorMessage . PHP_EOL);
                    }
                    self::$logger = new Logger('App_Fallback_Permissions_Error');
                    self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
                    self::$handlerFailed = true;
                } else {
                    $handler = new StreamHandler($logFilePath, Logger::DEBUG);
                    $handler->setFormatter($formatter);
                    self::$logger = new Logger('App');
                    self::$logger->pushHandler($handler);
                }

                if (self::$logger !== null) { // Устанавливаем обработчики, даже если это NullHandler
                    self::registerGlobalHandlers();
                }

            } catch (\Exception $e) {
                self::$handlerFailed = true;
                error_log("Критическая ошибка инициализации LogManager: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
                self::$logger = new Logger('App_Fallback_Init_Error');
                self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
                 if (self::$logger !== null) { // Попытка установить обработчики даже при ошибке
                    self::registerGlobalHandlers();
                }
            }
        } elseif (self::$logger === null && self::$handlerFailed) {
             if (!isset(self::$logger) || self::$logger === null) { // Дополнительная проверка
                self::$logger = new Logger('App_Fallback_Critical_Failure');
                self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
            }
        }
        return self::$logger;
    }

    private static function registerGlobalHandlers(): void
    {
        set_exception_handler(function (\Throwable $exception) {
            $loggerInstance = LogManager::getLogger();
            if ($loggerInstance) {
                $loggerInstance->critical(
                    $exception->getMessage(),
                    [
                        'exception_class' => get_class($exception),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => $exception->getTraceAsString()
                    ]
                );
            } else {
                 error_log("Fallback: Logger not available for exception: " . $exception->getMessage());
            }

            if (php_sapi_name() !== 'cli' && !headers_sent()) {
                http_response_code(500);
            }
            if (php_sapi_name() === 'cli') {
                 echo "Критическая ошибка: " . $exception->getMessage() . "\nПодробности в app.log (если доступен).\n";
            }
            // exit(1); // Не будем прерывать выполнение тестов из-за логгера
        });

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $loggerInstance = LogManager::getLogger();
            if ($loggerInstance) {
                $loggerInstance->error(
                    "Ошибка PHP: {$message}",
                    ['severity' => $severity, 'file' => $file, 'line' => $line]
                );
            } else {
                error_log("Fallback: Logger not available for PHP error: {$message} in {$file}:{$line}");
            }
            return true; // Не прерываем выполнение из-за обработчика ошибок
        });
    }
}

if (class_exists(Monolog\Logger::class)) {
    LogManager::getLogger();
}

class ApcuSimpleCache implements CacheInterface
{
    private const CACHE_PREFIX = 'app_params_';
    private const DEFAULT_TTL = 3600;

    private function isApcuActive(): bool
    {
        return extension_loaded('apcu') && apcu_enabled();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isApcuActive()) return $default;
        $success = false;
        $value = apcu_fetch(self::CACHE_PREFIX . $key, $success);
        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if (!$this->isApcuActive()) return false;
        $actualTtl = self::DEFAULT_TTL;
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $end = $now->add($ttl);
            $actualTtl = $end->getTimestamp() - $now->getTimestamp();
            if ($actualTtl < 0) $actualTtl = 0;
        } elseif (is_int($ttl)) {
            $actualTtl = $ttl;
        }
        return apcu_store(self::CACHE_PREFIX . $key, $value, $actualTtl);
    }

    public function delete(string $key): bool
    {
        if (!$this->isApcuActive()) return false;
        return apcu_delete(self::CACHE_PREFIX . $key);
    }

    public function clear(): bool
    {
        if (!$this->isApcuActive()) return false;
        LogManager::getLogger()->debug("ApcuSimpleCache::clear() called, attempting to clear by prefix: " . self::CACHE_PREFIX);
        try {
            if (class_exists('APCUIterator')) {
                $iterator = new \APCUIterator('#^' . preg_quote(self::CACHE_PREFIX, '#') . '.*#', \APC_ITER_KEY);
                $keysToDelete = [];
                foreach ($iterator as $item) {
                    $currentKey = is_array($item) && isset($item['key']) ? $item['key'] : (is_string($item) ? $item : null);
                    if ($currentKey !== null) {
                         $keysToDelete[] = $currentKey;
                    }
                }
                if (!empty($keysToDelete)) {
                    apcu_delete($keysToDelete);
                }
                return true;
            }
        } catch (MissingExtensionException $e) {
            LogManager::getLogger()->warning("APCUIterator class not found for ApcuSimpleCache::clear. Cache cannot be cleared effectively by prefix.", ['exception' => $e->getMessage()]);
        } catch (\Throwable $e) {
            LogManager::getLogger()->error("Error during APCUIterator usage for ApcuSimpleCache::clear.", ['exception' => $e]);
        }
        return false;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!$this->isApcuActive()) {
            $result = [];
            foreach ($keys as $key) { $result[(string)$key] = $default; }
            return $result;
        }
        $prefixedKeys = [];
        foreach ($keys as $key) { $prefixedKeys[] = self::CACHE_PREFIX . (string)$key; }

        $rawValues = apcu_fetch($prefixedKeys);
        if ($rawValues === false) $rawValues = [];

        $results = [];
        foreach ($keys as $originalKey) {
            $strOriginalKey = (string)$originalKey;
            $prefixedKey = self::CACHE_PREFIX . $strOriginalKey;
            $results[$strOriginalKey] = array_key_exists($prefixedKey, $rawValues) ? $rawValues[$prefixedKey] : $default;
        }
        return $results;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        if (!$this->isApcuActive()) return false;
        $actualTtl = self::DEFAULT_TTL;
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $end = $now->add($ttl);
            $actualTtl = $end->getTimestamp() - $now->getTimestamp();
             if ($actualTtl < 0) $actualTtl = 0;
        } elseif (is_int($ttl)) {
            $actualTtl = $ttl;
        }

        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[self::CACHE_PREFIX . (string)$key] = $value;
        }
        if (empty($prefixedValues)) return true;
        $errors = apcu_store($prefixedValues, null, $actualTtl);
        return empty($errors);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        if (!$this->isApcuActive()) return false;
        $prefixedKeys = [];
        foreach ($keys as $key) { $prefixedKeys[] = self::CACHE_PREFIX . (string)$key; }
        if (empty($prefixedKeys)) return true;
        $result = apcu_delete($prefixedKeys);
        return $result === true || (is_array($result) && empty($result));
    }

    public function has(string $key): bool
    {
        if (!$this->isApcuActive()) return false;
        return apcu_exists(self::CACHE_PREFIX . $key);
    }

    public function deleteByTablePrefix(string $tablePrefix): bool
    {
        if (!$this->isApcuActive()) return false;
        $fullPrefixToDelete = self::CACHE_PREFIX . $tablePrefix;
        LogManager::getLogger()->debug("ApcuSimpleCache::deleteByTablePrefix() called for prefix: " . $fullPrefixToDelete);
        try {
            if (class_exists('APCUIterator')) {
                $iterator = new \APCUIterator('#^' . preg_quote($fullPrefixToDelete, '#') . '.*#', \APC_ITER_KEY);
                $keysToDelete = [];
                foreach ($iterator as $item) {
                    $currentKey = is_array($item) && isset($item['key']) ? $item['key'] : (is_string($item) ? $item : null);
                    if ($currentKey !== null) {
                         $keysToDelete[] = $currentKey;
                    }
                }
                if (!empty($keysToDelete)) {
                    apcu_delete($keysToDelete);
                }
                return true;
            }
        } catch (MissingExtensionException $e) {
             LogManager::getLogger()->warning("APCUIterator class not found for deleteByTablePrefix. Cache for prefix '{$fullPrefixToDelete}' cannot be cleared effectively.", ['exception' => $e->getMessage()]);
        } catch (\Throwable $e) {
            LogManager::getLogger()->error("Error during APCUIterator usage for deleteByTablePrefix for prefix '{$fullPrefixToDelete}'.", ['exception' => $e]);
        }
        LogManager::getLogger()->warning("APCUIterator not available or failed for deleteByTablePrefix for prefix '{$fullPrefixToDelete}'. Cache not cleared effectively.");
        return false;
    }
}

final class CacheManager
{
    private static ?CacheInterface $cacheInstance = null;
    private static bool $apcuWarningLogged = false;
    private static bool $initialized = false;

    public static function initialize(?CacheInterface $cache = null): void
    {
        if ($cache !== null) {
            self::$cacheInstance = $cache;
        } elseif (extension_loaded('apcu') && apcu_enabled()) {
            self::$cacheInstance = new ApcuSimpleCache();
        } else {
            self::$cacheInstance = null;
             if (!self::$apcuWarningLogged && $cache === null) {
                if (class_exists(LogManager::class)) {
                     LogManager::getLogger()->warning("APCu не доступен и не предоставлена реализация кэша. Кэширование будет отключено.");
                }
                self::$apcuWarningLogged = true;
            }
        }
        self::$initialized = true;
    }

    private static function getCache(): ?CacheInterface
    {
        if (!self::$initialized) {
            self::initialize();
        }
        return self::$cacheInstance;
    }

    public static function isEnabled(): bool
    {
        return self::getCache() !== null;
    }

    public static function get(string $key): mixed
    {
        return self::getCache()?->get($key, false) ?? false;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return self::getCache()?->set($key, $value, $ttl) ?? false;
    }

    public static function delete(string $key): bool
    {
        return self::getCache()?->delete($key) ?? false;
    }
    
    public static function clear(): bool {
        $cache = self::getCache();
        if ($cache !== null) {
            return $cache->clear();
        }
        return true;
    }

    public static function deleteByTablePrefix(string $tablePrefix): bool
    {
        $cache = self::getCache();
        if ($cache instanceof ApcuSimpleCache) {
            return $cache->deleteByTablePrefix($tablePrefix);
        }
        if ($cache !== null) {
             if (class_exists(LogManager::class)) {
                 LogManager::getLogger()->warning("Метод deleteByTablePrefix специфичен для ApcuSimpleCache и не доступен для текущей реализации кэша: " . get_class($cache));
             }
        }
        return false;
    }
}

class DB
{
    private ?\PDO $pdo = null; // Сделаем nullable для явного закрытия
    private string $dsnForLog;
    private array $initializedTables = [];

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        $this->dsnForLog = $dsn;
        $logManagerExists = class_exists(LogManager::class, false);

        if (str_starts_with(strtolower($dsn), 'sqlite:')) {
            $dbFile = substr($dsn, 7);
            if ($dbFile !== ':memory:') {
                if ($logManagerExists) LogManager::getLogger()->info("DB DSN: {$dsn}, предполагаемый файл: {$dbFile}");
                $dbDir = dirname($dbFile);
                if (!empty($dbDir) && $dbDir !== '.' && !is_dir($dbDir)) {
                    if ($logManagerExists) LogManager::getLogger()->info("Попытка создать директорию для БД: {$dbDir}");
                    if (!@mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
                        if ($logManagerExists) LogManager::getLogger()->error("Не удалось создать директорию для файла БД: {$dbDir}. Проверьте права.");
                    }
                }
            }
        }

        $defaultOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $finalOptions = array_replace($defaultOptions, $options);

        try {
            $this->pdo = new \PDO($dsn, $username, $password, $finalOptions);
            if (str_starts_with(strtolower($dsn), 'sqlite:')) {
                $this->pdo->exec('PRAGMA journal_mode = WAL;');
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
                $this->pdo->exec('PRAGMA busy_timeout = 5000;');
                $this->pdo->exec('PRAGMA synchronous = NORMAL;');
            }
        } catch (\PDOException $e) {
            $this->pdo = null; // Убедимся, что pdo null в случае ошибки
            if ($logManagerExists) LogManager::getLogger()->critical("Ошибка подключения/настройки БД для DSN {$dsn}", ['exception_class' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("Ошибка инициализации БД: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    public function close(): void
    {
        if ($this->pdo !== null) {
            $this->pdo = null; // Это должно освободить блокировку файла SQLite
            if (class_exists(LogManager::class, false)) {
                LogManager::getLogger()->info("DB connection explicitly closed for DSN: {$this->dsnForLog}");
            }
        }
    }

    public function __destruct()
    {
        if ($this->pdo !== null) {
             if (class_exists(LogManager::class, false)) {
                LogManager::getLogger()->info("DB connection destructed for DSN: {$this->dsnForLog}. Consider explicit close().");
            }
            $this->pdo = null;
        }
    }
    
    private function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            // Это не должно происходить в нормальном потоке тестов, где DB создается и используется сразу.
            // Если это произошло, значит, соединение было закрыто или не установлено.
            LogManager::getLogger()->critical("Попытка использовать закрытое или неинициализированное PDO соединение.", ['dsn' => $this->dsnForLog]);
            throw new \RuntimeException("PDO соединение не доступно.");
        }
        return $this->pdo;
    }

    public function getDsnForInitKey(): string
    {
        return $this->dsnForLog;
    }

    public function isTableInitialized(string $tableName): bool
    {
        return isset($this->initializedTables[$tableName]);
    }

    public function markTableAsInitialized(string $tableName): void
    {
        $this->initializedTables[$tableName] = true;
    }

    public function executeSchemaStatement(string $statement): bool
    {
        try {
            $this->getPdo()->exec($statement);
            return true;
        } catch (\PDOException $e) {
            LogManager::getLogger()->error(
                "Ошибка выполнения DDL-выражения",
                ['sql' => $statement, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]
            );
            return false;
        }
    }

    public function execute(string $query, array $params = []): bool
    {
        try {
            $stmt = $this->getPdo()->prepare($query);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            LogManager::getLogger()->error("Ошибка выполнения SQL-запроса", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Ошибка выполнения SQL-запроса: " . $e->getMessage() . " Запрос: " . $query, (int)$e->getCode(), $e);
        }
    }

    public function fetchOne(string $query, array $params = []): ?array
    {
        try {
            $stmt = $this->getPdo()->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result === false ? null : $result;
        } catch (\PDOException $e) {
            LogManager::getLogger()->error("Ошибка SQL-запроса (fetchOne)", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Ошибка SQL-запроса: " . $e->getMessage() . " Запрос: " . $query, (int)$e->getCode(), $e);
        }
    }
    
    public function fetchValue(string $query, array $params = []): mixed
    {
        try {
            $stmt = $this->getPdo()->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (\PDOException $e) {
            LogManager::getLogger()->error("Ошибка SQL-запроса (fetchValue)", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Ошибка SQL-запроса: " . $e->getMessage() . " Запрос: " . $query, (int)$e->getCode(), $e);
        }
    }

    public function fetchAll(string $query, array $params = []): array
    {
        try {
            $stmt = $this->getPdo()->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            LogManager::getLogger()->error("Ошибка SQL-запроса (fetchAll)", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Ошибка SQL-запроса: " . $e->getMessage() . " Запрос: " . $query, (int)$e->getCode(), $e);
        }
    }

    public function beginTransaction(): bool { return $this->getPdo()->beginTransaction(); }
    public function commit(): bool { return $this->getPdo()->commit(); }
    public function rollBack(): bool { return $this->getPdo()->rollBack(); }
    public function inTransaction(): bool { return $this->getPdo()->inTransaction(); }
}

interface ParamsManagerInterface
{
    public function getParam(string $table, string $fullKey): mixed;
    public function setParam(string $table, string $fullKey, mixed $value): void;
    public function unsetParam(string $table, string $fullKey): void;
    public function unsetParams(string $table, array|string $keys): void;
    public function removeAllParamsFromTable(string $table): void;
}

class PdoParamsManager implements ParamsManagerInterface
{
    private DB $db;

    public function __construct(DB $db)
    {
        $this->db = $db;
    }

    private function getCacheKeyForId(string $table, string $id): string
    {
        return $table . '_' . $id;
    }

    public function removeAllParamsFromTable(string $table): void
    {
        try {
            $this->db->execute("DELETE FROM {$table}");
            if (CacheManager::isEnabled()) {
                CacheManager::deleteByTablePrefix($table . '_');
            }
        } catch (\Throwable $e) {
            LogManager::getLogger()->error("Ошибка при удалении всех параметров из таблицы {$table}", ['exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getParam(string $table, string $fullKey): mixed
    {
        $paramParts = explode(".", $fullKey);
        $id = array_shift($paramParts);

        if (empty($id)) {
            LogManager::getLogger()->warning("getParam вызван с пустым ID для таблицы {$table}: '{$fullKey}'");
            return null;
        }

        $cacheKey = $this->getCacheKeyForId($table, $id);
        $cachedParamsObject = CacheManager::get($cacheKey);

        $paramsObject = null;
        if ($cachedParamsObject !== false) {
            $paramsObject = $cachedParamsObject;
        } else {
            try {
                $jsonString = $this->db->fetchValue("SELECT params FROM {$table} WHERE id = :id", [':id' => $id]);
            } catch (\Throwable $e) {
                LogManager::getLogger()->error("Ошибка БД при получении параметра для таблицы {$table}, ID {$id}", ['exception_class' => get_class($e), 'message' => $e->getMessage()]);
                throw $e;
            }

            if ($jsonString === null || $jsonString === false) {
                return null;
            }
            
            $paramsObject = json_decode((string)$jsonString, true);
            if ($paramsObject === null && json_last_error() !== JSON_ERROR_NONE) {
                 LogManager::getLogger()->error("Ошибка декодирования JSON из БД для таблицы {$table}, ID {$id}", ['json_error' => json_last_error_msg(), 'json_string_snippet' => substr((string)$jsonString, 0, 100)]);
                 throw new \RuntimeException("Ошибка декодирования JSON из БД для таблицы {$table}, ID {$id}: " . json_last_error_msg());
            }

            if (CacheManager::isEnabled()) {
                CacheManager::set($cacheKey, $paramsObject);
            }
        }
        
        if (empty($paramParts)) {
            return $paramsObject;
        }

        $data = $paramsObject;
        foreach ($paramParts as $part) {
            if (is_array($data) && array_key_exists($part, $data)) {
                $data = $data[$part];
            } else {
                return null;
            }
        }
        return $data;
    }

    public function setParam(string $table, string $fullKey, mixed $value): void
    {
        $paramParts = explode(".", $fullKey);
        $id = array_shift($paramParts);

        if (empty($id)) {
            throw new \InvalidArgumentException("ID не может быть пустым для setParam в таблице {$table}.");
        }
        
        $localTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $localTransaction = true;
        }

        try {
            $selectQuery = "SELECT params FROM {$table} WHERE id = :id";
            $jsonString = $this->db->fetchValue($selectQuery, [':id' => $id]);
            $phpArray = [];

            if ($jsonString !== null && $jsonString !== false) {
                $phpArray = json_decode((string)$jsonString, true);
                if ($phpArray === null && json_last_error() !== JSON_ERROR_NONE) {
                    if ($localTransaction) $this->db->rollBack();
                    LogManager::getLogger()->error("setParam: Ошибка декодирования существующего JSON для таблицы {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                    throw new \RuntimeException("Ошибка декодирования существующего JSON для таблицы {$table}, ID {$id}: " . json_last_error_msg());
                }
            }
            
            $temp = &$phpArray;
            if (empty($paramParts)) {
                $phpArray = $value;
            } else {
                foreach ($paramParts as $index => $part) {
                    if ($index === count($paramParts) - 1) {
                        if (!is_array($temp) && !is_null($temp)) {
                             if ($localTransaction) $this->db->rollBack();
                             throw new \RuntimeException("Невозможно установить ключ '{$part}' на не-массив по пути для таблицы {$table}, ID {$id}. Текущий тип: " . gettype($temp));
                        }
                        if (is_null($temp)) $temp = [];
                        $temp[$part] = $value;
                    } else {
                        if (!isset($temp[$part]) || !is_array($temp[$part])) {
                            $temp[$part] = [];
                        }
                        $temp = &$temp[$part];
                    }
                }
            }
            unset($temp);

            $newJson = json_encode($phpArray, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if ($newJson === false) {
                if ($localTransaction) $this->db->rollBack();
                LogManager::getLogger()->error("setParam: Ошибка кодирования значения в JSON для таблицы {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                throw new \RuntimeException("Ошибка кодирования значения в JSON для таблицы {$table}, ID {$id}: " . json_last_error_msg());
            }

            $columns = ['id', 'params'];
            $placeholders = [':id', ':params_insert'];
            $bindings = [':id' => $id, ':params_insert' => $newJson, ':params_update' => $newJson];
            
            $updateAssignments = "params = :params_update";

            if ($table === Product::TABLE_NAME) {
                $columns[] = 'title';
                $placeholders[] = ':title_insert';

                $titleForInsert = 'Default Product Title'; 
                $titleForUpdate = null; 

                if (empty($paramParts) && is_array($value) && array_key_exists('title', $value)) {
                    $titleForInsert = $value['title'];
                    $titleForUpdate = $value['title'];
                } 
                elseif ($jsonString === null || $jsonString === false) {
                    // New record, title not in root $value, use default.
                }
                
                $bindings[':title_insert'] = $titleForInsert;

                if ($titleForUpdate !== null) {
                    $updateAssignments .= ", title = :title_update";
                    $bindings[':title_update'] = $titleForUpdate;
                }
            }
            
            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(id) DO UPDATE SET %s",
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders),
                $updateAssignments
            );
            
            $this->db->execute($sql, $bindings);

            if ($localTransaction) $this->db->commit();
            
            if (CacheManager::isEnabled()) {
                CacheManager::delete($this->getCacheKeyForId($table, $id));
            }

        } catch (\Throwable $e) {
            if ($localTransaction && $this->db->inTransaction()) {
                 $this->db->rollBack();
            }
            LogManager::getLogger()->error("Ошибка в setParam для таблицы {$table}, ID {$id}", ['fullKey' => $fullKey, 'exception_class' => get_class($e), 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    public function unsetParam(string $table, string $fullKey): void
    {
        $paramParts = explode(".", $fullKey);
        $id = array_shift($paramParts);

        if (empty($id)) return;

        $localTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $localTransaction = true;
        }
        
        try {
            if (empty($paramParts)) {
                $this->db->execute("DELETE FROM {$table} WHERE id = :id", [':id' => $id]);
                LogManager::getLogger()->info("Удалена запись из таблицы {$table} с ID {$id} (через unsetParam без пути).");
            } else {
                $jsonString = $this->db->fetchValue("SELECT params FROM {$table} WHERE id = :id", [':id' => $id]);

                if ($jsonString === null || $jsonString === false) {
                    if ($localTransaction) $this->db->commit(); 
                    return;
                }

                $phpArray = json_decode((string)$jsonString, true);
                if ($phpArray === null && json_last_error() !== JSON_ERROR_NONE) {
                    if ($localTransaction) $this->db->rollBack();
                    LogManager::getLogger()->error("unsetParam: Ошибка декодирования JSON для таблицы {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                    throw new \RuntimeException("Ошибка декодирования JSON для удаления (таблица {$table}, ID {$id}): " . json_last_error_msg());
                }

                $temp = &$phpArray;
                $keyToRemove = end($paramParts);
                $parentOfKey = &$temp; 
                $pathValid = true;

                for ($i = 0; $i < count($paramParts) - 1; $i++) {
                    $part = $paramParts[$i];
                    if (!is_array($parentOfKey) || !array_key_exists($part, $parentOfKey)) {
                        $pathValid = false;
                        break;
                    }
                    $parentOfKey = &$parentOfKey[$part];
                }
                
                if ($pathValid && is_array($parentOfKey) && array_key_exists($keyToRemove, $parentOfKey)) {
                    unset($parentOfKey[$keyToRemove]);
                    $newJson = json_encode($phpArray, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                    if ($newJson === false) {
                        if ($localTransaction) $this->db->rollBack();
                        LogManager::getLogger()->error("unsetParam: Ошибка кодирования JSON после удаления для таблицы {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                        throw new \RuntimeException("Ошибка кодирования JSON после удаления (таблица {$table}, ID {$id}): " . json_last_error_msg());
                    }
                    $this->db->execute("UPDATE {$table} SET params = :params WHERE id = :id", [':params' => $newJson, ':id' => $id]);
                }
                unset($temp, $parentOfKey);
            }
            if ($localTransaction) $this->db->commit();
            
            if (CacheManager::isEnabled()) {
                CacheManager::delete($this->getCacheKeyForId($table, $id));
            }
        } catch (\Throwable $e) {
             if ($localTransaction && $this->db->inTransaction()) {
                 $this->db->rollBack();
            }
            LogManager::getLogger()->error("Ошибка в unsetParam для таблицы {$table}, ID {$id}", ['fullKey' => $fullKey, 'exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function unsetParams(string $table, array|string $keys): void
    {
        $actualKeys = [];
        if (is_array($keys)) {
            foreach ($keys as $keyStr) {
                if (!is_scalar($keyStr) && !$keyStr instanceof \Stringable) {
                    LogManager::getLogger()->warning("Нестроковый ключ обнаружен в массиве unsetParams для таблицы {$table}", ['key_type' => gettype($keyStr)]);
                    continue;
                }
                $explodedKeys = explode(",", (string)$keyStr);
                foreach ($explodedKeys as $singleKey) {
                    $trimmedKey = trim($singleKey);
                    if (!empty($trimmedKey)) {
                        $actualKeys[] = $trimmedKey;
                    }
                }
            }
        } else {
            $explodedKeys = explode(",", (string)$keys);
            foreach ($explodedKeys as $singleKey) {
                $trimmedKey = trim($singleKey);
                if (!empty($trimmedKey)) {
                    $actualKeys[] = $trimmedKey;
                }
            }
        }
        $actualKeys = array_unique($actualKeys);

        if (empty($actualKeys)) {
            return;
        }

        $localTransactionOuter = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $localTransactionOuter = true;
        }

        try {
            foreach ($actualKeys as $key) {
                $this->unsetParam($table, $key);
            }
            if ($localTransactionOuter) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($localTransactionOuter && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            LogManager::getLogger()->error("Ошибка во время группового удаления (unsetParams) для таблицы {$table}", ['keys_attempted' => $actualKeys, 'exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e;
        }
    }
}

abstract class SimpleParamsModel
{
    abstract public function removeParams(): void;
    abstract public function getParam(string $key): mixed;
    abstract public function setParam(string $key, $value): void;
    abstract public function unsetParam(string $key): void;
    abstract public function unsetParams($keys): void;
}

/** @var ?DB $globalAppDbForTzTest */
$globalAppDbForTzTest = null;
/** @var ?ParamsManagerInterface $globalAppParamsManagerForTzTest */
$globalAppParamsManagerForTzTest = null;
/** @var string Имя файла БД по умолчанию. Будет переопределено в MyTest.php::_before() */
$defaultDbFileForTzCompatibility = __DIR__ . '/data.db';

class Order extends SimpleParamsModel
{
    public const TABLE_NAME = "Orders";
    private ParamsManagerInterface $paramsManager;
    private DB $dbInstance;

    public function __construct()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest, $defaultDbFileForTzCompatibility;
        
        $dbToUse = $globalAppDbForTzTest;
        $pmToUse = $globalAppParamsManagerForTzTest;
        $logManagerExists = class_exists(LogManager::class);

        if ($dbToUse === null) {
            $currentDefaultDbFile = $GLOBALS['defaultDbFileForTzCompatibility'] ?? $defaultDbFileForTzCompatibility;
            $dsn = 'sqlite:' . $currentDefaultDbFile;
            if ($logManagerExists) LogManager::getLogger()->info("Order constructor: \$globalAppDbForTzTest is null, creating new DB for '{$currentDefaultDbFile}'");
            $dbToUse = new DB($dsn);
        }
        $this->dbInstance = $dbToUse;

        if ($pmToUse === null) {
            if ($logManagerExists) LogManager::getLogger()->info("Order constructor: \$globalAppParamsManagerForTzTest is null, creating new PdoParamsManager");
            $pmToUse = new PdoParamsManager($this->dbInstance);
        }
        
        if ($pmToUse === null) {
             throw new \RuntimeException("ParamsManager не был инициализирован для Order.");
        }
        $this->paramsManager = $pmToUse;
        
        if (!$this->dbInstance->isTableInitialized(self::TABLE_NAME)) {
            $mainTableCommand = "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (id TEXT PRIMARY KEY, created_at TEXT DEFAULT CURRENT_TIMESTAMP, params TEXT)";
            if ($this->dbInstance->executeSchemaStatement($mainTableCommand)) {
                $indexCommand = "CREATE INDEX IF NOT EXISTS idx_orders_created_at ON " . self::TABLE_NAME . "(created_at)";
                if (!$this->dbInstance->executeSchemaStatement($indexCommand)) {
                    if ($logManagerExists) LogManager::getLogger()->warning("Не удалось создать индекс idx_orders_created_at для " . self::TABLE_NAME);
                }
                $this->dbInstance->markTableAsInitialized(self::TABLE_NAME);
            } else {
                 if ($logManagerExists) LogManager::getLogger()->error("КРИТИЧЕСКАЯ ОШИБКА: Не удалось создать основную таблицу " . self::TABLE_NAME);
            }
        }
    }

    public function removeParams(): void { $this->paramsManager->removeAllParamsFromTable(self::TABLE_NAME); }
    public function getParam(string $key): mixed { return $this->paramsManager->getParam(self::TABLE_NAME, $key); }
    public function setParam(string $key, $value): void { $this->paramsManager->setParam(self::TABLE_NAME, $key, $value); }
    public function unsetParam(string $key): void { $this->paramsManager->unsetParam(self::TABLE_NAME, $key); }
    public function unsetParams($keys): void { $this->paramsManager->unsetParams(self::TABLE_NAME, $keys); }
}

class Product extends SimpleParamsModel
{
    public const TABLE_NAME = "Products";
    private ParamsManagerInterface $paramsManager;
    private DB $dbInstance;

    public function __construct()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest, $defaultDbFileForTzCompatibility;

        $dbToUse = $globalAppDbForTzTest;
        $pmToUse = $globalAppParamsManagerForTzTest;
        $logManagerExists = class_exists(LogManager::class);

        if ($dbToUse === null) {
            $currentDefaultDbFile = $GLOBALS['defaultDbFileForTzCompatibility'] ?? $defaultDbFileForTzCompatibility;
            $dsn = 'sqlite:' . $currentDefaultDbFile;
            if ($logManagerExists) LogManager::getLogger()->info("Product constructor: \$globalAppDbForTzTest is null, creating new DB for '{$currentDefaultDbFile}'");
            $dbToUse = new DB($dsn);
        }
        $this->dbInstance = $dbToUse;

        if ($pmToUse === null) {
            if ($logManagerExists) LogManager::getLogger()->info("Product constructor: \$globalAppParamsManagerForTzTest is null, creating new PdoParamsManager");
            $pmToUse = new PdoParamsManager($this->dbInstance);
        }
        
        if ($pmToUse === null) {
             throw new \RuntimeException("ParamsManager не был инициализирован для Product.");
        }
        $this->paramsManager = $pmToUse;

        if (!$this->dbInstance->isTableInitialized(self::TABLE_NAME)) {
            $mainTableCommand = "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (id TEXT PRIMARY KEY, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, params TEXT)";
            if ($this->dbInstance->executeSchemaStatement($mainTableCommand)) {
                $indexCommands = [
                    "CREATE INDEX IF NOT EXISTS idx_products_created_at ON " . self::TABLE_NAME . "(created_at)",
                    "CREATE INDEX IF NOT EXISTS idx_products_title ON " . self::TABLE_NAME . "(title)"
                ];
                foreach ($indexCommands as $command) {
                    if (!$this->dbInstance->executeSchemaStatement($command)) {
                         if ($logManagerExists) LogManager::getLogger()->warning("Не удалось создать индекс для " . self::TABLE_NAME, ['command' => $command]);
                    }
                }
                $this->dbInstance->markTableAsInitialized(self::TABLE_NAME);
            } else {
                if ($logManagerExists) LogManager::getLogger()->error("КРИТИЧЕСКАЯ ОШИБКА: Не удалось создать основную таблицу " . self::TABLE_NAME);
            }
        }
    }

    public function removeParams(): void { $this->paramsManager->removeAllParamsFromTable(self::TABLE_NAME); }
    public function getParam(string $key): mixed { return $this->paramsManager->getParam(self::TABLE_NAME, $key); }
    public function setParam(string $key, $value): void { $this->paramsManager->setParam(self::TABLE_NAME, $key, $value); }
    public function unsetParam(string $key): void { $this->paramsManager->unsetParam(self::TABLE_NAME, $key); }
    public function unsetParams($keys): void { $this->paramsManager->unsetParams(self::TABLE_NAME, $keys); }
}

?>