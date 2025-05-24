<?php

declare(strict_types=1);

// Убедитесь, что зависимости установлены:
// composer require psr/simple-cache monolog/monolog
// и автозагрузчик подключен:
// require_once 'vendor/autoload.php'; // Если используете Composer

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\MissingExtensionException;
use Psr\SimpleCache\CacheInterface;
use PDO;
use PDOException;

// --- LogManager ---
// Остается без изменений (предполагается его наличие из предыдущей версии)
final class LogManager
{
    private static ?Logger $logger = null;
    private static bool $handlerFailed = false;

    public static function getLogger(): Logger
    {
        if (self::$logger === null && !self::$handlerFailed) {
            try {
                $formatter = new LineFormatter(null, null, true, true);
                $formatter->includeStacktraces(true);

                $logFilePath = __DIR__ . '/app.log';
                $logDir = dirname($logFilePath);
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0775, true);
                }

                if (!is_writable($logDir) || (file_exists($logFilePath) && !is_writable($logFilePath))) {
                    $errorMessage = "Log file/directory ({$logFilePath}) is not writable. Please check permissions.";
                    error_log($errorMessage);
                    if (php_sapi_name() === 'cli') {
                        file_put_contents('php://stderr', $errorMessage . PHP_EOL);
                    }
                    self::$logger = new Logger('App_Fallback');
                    self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
                    self::$handlerFailed = true;
                } else {
                    $handler = new StreamHandler($logFilePath, Logger::DEBUG);
                    $handler->setFormatter($formatter);
                    self::$logger = new Logger('App');
                    self::$logger->pushHandler($handler);
                }

                if (self::$logger !== null) {
                    set_exception_handler(function (\Throwable $exception) {
                        LogManager::getLogger()->critical(
                            $exception->getMessage(),
                            ['exception_class' => get_class($exception), 'file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => $exception->getTraceAsString()]
                        );
                        if (php_sapi_name() !== 'cli' && !headers_sent()) {
                            http_response_code(500);
                        }
                        if (php_sapi_name() === 'cli') {
                             echo "Critical Error: " . $exception->getMessage() . "\nCheck app.log for details.\n";
                        }
                        exit(1);
                    });

                    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
                        if (!(error_reporting() & $severity)) {
                            return false;
                        }
                        LogManager::getLogger()->error(
                            "PHP Error: {$message}",
                            ['severity' => $severity, 'file' => $file, 'line' => $line]
                        );
                        return true;
                    });
                }

            } catch (\Exception $e) {
                self::$handlerFailed = true;
                error_log("Failed to initialize LogManager: " . $e->getMessage());
                self::$logger = new Logger('App_Fallback_Init_Error');
                self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
            }
        } elseif (self::$logger === null && self::$handlerFailed) {
             if (!isset(self::$logger) || self::$logger === null) {
                self::$logger = new Logger('App_Fallback_Critical');
                self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
            }
        }
        return self::$logger;
    }
}

if (class_exists(Monolog\Logger::class)) {
    LogManager::getLogger();
}


// --- Cache Implementation (APCu specific, PSR-16) ---
// Остается без изменений (уже хорошо оптимизирован)
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
                $iterator = new \APCUIterator('user', '#^' . preg_quote(self::CACHE_PREFIX, '#') . '.*#', APC_ITER_KEY);
                $keysToDelete = [];
                foreach ($iterator as $item) {
                    if (isset($item['key']) && is_string($item['key'])) {
                         $keysToDelete[] = $item['key'];
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
                $iterator = new \APCUIterator('user', '#^' . preg_quote($fullPrefixToDelete, '#') . '.*#', APC_ITER_KEY);
                $keysToDelete = [];
                foreach ($iterator as $item) {
                    if (isset($item['key']) && is_string($item['key'])) {
                         $keysToDelete[] = $item['key'];
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

// --- CacheManager (PSR-16 Facade) ---
// Остается без изменений
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
                if (class_exists(LogManager::class)) LogManager::getLogger()->warning("APCu not available and no cache instance provided. Caching will be disabled.");
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

    public static function deleteByTablePrefix(string $tablePrefix): bool
    {
        $cache = self::getCache();
        if ($cache instanceof ApcuSimpleCache) {
            return $cache->deleteByTablePrefix($tablePrefix);
        }
        if ($cache !== null) {
             if (class_exists(LogManager::class)) LogManager::getLogger()->warning("deleteByTablePrefix is specific to ApcuSimpleCache and not available for the current cache implementation: " . get_class($cache));
        }
        return false;
    }
}

// --- DB Class (PDO based) ---
class DB
{
    // УЛУЧШЕНИЕ: readonly свойство для PDO
    private readonly PDO $pdo;
    // Можно также сделать $dsn readonly, если он сохраняется
    // private readonly string $dsn;


    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        // $this->dsn = $dsn; // Если бы сохраняли
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $finalOptions = array_replace($defaultOptions, $options);

        try {
            $this->pdo = new PDO($dsn, $username, $password, $finalOptions);
            if (str_starts_with(strtolower($dsn), 'sqlite:')) {
                $this->pdo->exec('PRAGMA journal_mode = WAL;');
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
                $this->pdo->exec('PRAGMA busy_timeout = 5000;');
                $this->pdo->exec('PRAGMA synchronous = NORMAL;');
            }
        } catch (PDOException $e) {
            LogManager::getLogger()->critical("DB Connection/Setup failed for DSN {$dsn}", ['exception_class' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("DB Initialization failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
    
    // Остальные методы DB без изменений...
    public function executeSchemaStatement(string $statement): bool
    {
        try {
            $this->pdo->exec($statement);
            return true;
        } catch (PDOException $e) {
            LogManager::getLogger()->error(
                "Failed to execute schema statement",
                ['sql' => $statement, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]
            );
            return false;
        }
    }

    public function execute(string $query, array $params = []): bool
    {
        try {
            $stmt = $this->pdo->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            LogManager::getLogger()->error("Failed to execute SQL query", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Failed to execute SQL query: " . $e->getMessage() . " Query: " . $query, (int)$e->getCode(), $e);
        }
    }

    public function fetchOne(string $query, array $params = []): ?array
    {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result === false ? null : $result;
        } catch (PDOException $e) {
            LogManager::getLogger()->error("Failed to execute SQL query for fetchOne", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Failed to execute SQL query: " . $e->getMessage() . " Query: " . $query, (int)$e->getCode(), $e);
        }
    }

    public function fetchValue(string $query, array $params = []): mixed
    {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value;
        } catch (PDOException $e) {
            LogManager::getLogger()->error("Failed to execute SQL query for fetchValue", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Failed to execute SQL query: " . $e->getMessage() . " Query: " . $query, (int)$e->getCode(), $e);
        }
    }
    
    public function fetchAll(string $query, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            LogManager::getLogger()->error("Failed to execute SQL query for fetchAll", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Failed to execute SQL query: " . $e->getMessage() . " Query: " . $query, (int)$e->getCode(), $e);
        }
    }

    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->rollBack(); }
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }
}

// --- ParamsManagerInterface & PdoParamsManager ---
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
    // УЛУЧШЕНИЕ: readonly свойство для DB
    public function __construct(private readonly DB $db) {}

    private function getCacheKeyForId(string $table, string $id): string
    {
        return $table . '_' . $id;
    }

    public function removeAllParamsFromTable(string $table): void
    {
        // ... (без изменений)
        try {
            $this->db->execute("DELETE FROM {$table}");
            if (CacheManager::isEnabled()) {
                CacheManager::deleteByTablePrefix($table . '_');
            }
        } catch (\Throwable $e) {
            LogManager::getLogger()->error("Failed removeAllParamsFromTable for table {$table}", ['exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getParam(string $table, string $fullKey): mixed
    {
        // ... (без изменений)
        $paramParts = explode(".", $fullKey);
        $id = array_shift($paramParts);

        if (empty($id)) {
            LogManager::getLogger()->warning("getParam called with empty ID in fullKey for table {$table}: {$fullKey}");
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
                LogManager::getLogger()->error("DB error in getParam for table {$table}, ID {$id}", ['exception_class' => get_class($e), 'message' => $e->getMessage()]);
                throw $e;
            }

            if ($jsonString === null || $jsonString === false) {
                return null;
            }
            
            $paramsObject = json_decode((string)$jsonString, true);
            if ($paramsObject === null && json_last_error() !== JSON_ERROR_NONE) {
                 LogManager::getLogger()->error("Failed to decode JSON from DB for table {$table}, ID {$id}", ['json_error' => json_last_error_msg(), 'json_string_snippet' => substr((string)$jsonString, 0, 100)]);
                 throw new \RuntimeException("Failed to decode JSON from DB for table {$table}, ID {$id}: " . json_last_error_msg());
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
            throw new \InvalidArgumentException("ID cannot be empty for setParam in table {$table}.");
        }
        
        $localTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $localTransaction = true;
        }

        try {
            $jsonString = $this->db->fetchValue("SELECT params FROM {$table} WHERE id = :id", [':id' => $id]);
            $phpArray = [];

            if ($jsonString !== null && $jsonString !== false) {
                $phpArray = json_decode((string)$jsonString, true);
                if ($phpArray === null && json_last_error() !== JSON_ERROR_NONE) {
                    if ($localTransaction) $this->db->rollBack();
                    LogManager::getLogger()->error("setParam: Failed to decode existing JSON for table {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                    throw new \RuntimeException("Failed to decode existing JSON for table {$table}, ID {$id}: " . json_last_error_msg());
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
                             throw new \RuntimeException("Cannot set key '$part' on a non-array value at path for table {$table}, ID {$id}. Current type: " . gettype($temp));
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
                LogManager::getLogger()->error("setParam: Failed to encode value to JSON for table {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                throw new \RuntimeException("Failed to encode value to JSON for table {$table}, ID {$id}: " . json_last_error_msg());
            }

            $columns = ['id', 'params'];
            $placeholders = [':id', ':params_insert'];
            $bindings = [':id' => $id, ':params_insert' => $newJson, ':params_update' => $newJson];
            
            $updateAssignments = "params = :params_update";

            // УЛУЧШЕНИЕ: Более явная и предсказуемая логика для поля title в Product
            if ($table === Product::TABLE_NAME) {
                $columns[] = 'title';
                $placeholders[] = ':title_insert'; // Плейсхолдер для title в INSERT части

                $titleForInsert = 'Default Product Title'; // Значение по умолчанию для новых записей
                $titleForUpdate = null; // null означает, что title не будет обновлен, если не указан в $value

                // Если $value устанавливает корневой объект и содержит 'title'
                if (empty($paramParts) && is_array($value) && array_key_exists('title', $value)) {
                    $titleForInsert = $value['title'];
                    $titleForUpdate = $value['title']; // Также обновляем title
                } elseif ($jsonString !== null && $jsonString !== false) { // Если запись существует и title не передан в корне
                    $existingProductData = $this->db->fetchOne("SELECT title FROM ".Product::TABLE_NAME." WHERE id = :id", [':id' => $id]);
                    if ($existingProductData && isset($existingProductData['title'])) {
                        $titleForInsert = $existingProductData['title']; // Для UPSERT, если это обновление, это значение не будет использовано напрямую
                    }
                }
                // (Если это новая запись и title не в корне $value, используется $titleForInsert = 'Default Product Title')

                $bindings[':title_insert'] = $titleForInsert;

                if ($titleForUpdate !== null) {
                    $updateAssignments .= ", title = :title_update";
                    $bindings[':title_update'] = $titleForUpdate;
                }
                // Если $titleForUpdate === null, колонка title не будет в SET части UPDATE,
                // и ее значение при конфликте не изменится (если запись уже существовала).
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
            LogManager::getLogger()->error("Error in setParam for table {$table}, ID {$id}", ['fullKey' => $fullKey, 'exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function unsetParam(string $table, string $fullKey): void
    {
        // ... (без изменений - уже хорошо оптимизирован и транзакционен)
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
                LogManager::getLogger()->info("Deleted record from table {$table} with ID {$id} via unsetParam (empty path).");
            } else {
                $jsonString = $this->db->fetchValue("SELECT params FROM {$table} WHERE id = :id", [':id' => $id]);

                if ($jsonString === null || $jsonString === false) {
                    if ($localTransaction) $this->db->commit();
                    return;
                }

                $phpArray = json_decode((string)$jsonString, true);
                if ($phpArray === null && json_last_error() !== JSON_ERROR_NONE) {
                    if ($localTransaction) $this->db->rollBack();
                    LogManager::getLogger()->error("unsetParam: Failed to decode existing JSON for table {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                    throw new \RuntimeException("Failed to decode existing JSON for unsetting (table {$table}, ID {$id}): " . json_last_error_msg());
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
                        LogManager::getLogger()->error("unsetParam: Failed to encode JSON after unsetting for table {$table}, ID {$id}", ['json_error' => json_last_error_msg()]);
                        throw new \RuntimeException("Failed to encode JSON after unsetting (table {$table}, ID {$id}): " . json_last_error_msg());
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
            LogManager::getLogger()->error("Error in unsetParam for table {$table}, ID {$id}", ['fullKey' => $fullKey, 'exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function unsetParams(string $table, array|string $keys): void
    {
        // ... (без изменений - уже с внешней транзакцией)
        $actualKeys = [];
        if (is_array($keys)) {
            foreach ($keys as $keyStr) {
                if (!is_scalar($keyStr) && !$keyStr instanceof \Stringable) {
                    LogManager::getLogger()->warning("Non-stringable key found in unsetParams array for table {$table}", ['key_type' => gettype($keyStr)]);
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
            LogManager::getLogger()->error("Error during bulk unsetParams for table {$table}", ['keys_attempted' => $actualKeys, 'exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e;
        }
    }
}

// --- Order & Product Classes ---
class Order
{
    public const TABLE_NAME = "Orders";
    // УЛУЧШЕНИЕ: readonly свойство
    private readonly ParamsManagerInterface $paramsManager;
    // private readonly DB $dbInstance; // Если бы DB инстанс сохранялся и использовался для других нужд

    public function __construct(DB $dbInstance, ParamsManagerInterface $paramsManager)
    {
        $this->paramsManager = $paramsManager;
        // $this->dbInstance = $dbInstance; // Если бы сохраняли

        $mainTableCommand = "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (id TEXT PRIMARY KEY, created_at TEXT DEFAULT CURRENT_TIMESTAMP, params TEXT)";
        if (!$dbInstance->executeSchemaStatement($mainTableCommand)) {
             LogManager::getLogger()->warning("Failed to create table " . self::TABLE_NAME . " (check logs for details).");
        }
        
        $indexCommand = "CREATE INDEX IF NOT EXISTS idx_orders_created_at ON " . self::TABLE_NAME . "(created_at)";
        if (!$dbInstance->executeSchemaStatement($indexCommand)) {
            LogManager::getLogger()->warning("Failed to create index idx_orders_created_at for table " . self::TABLE_NAME . " (check logs for details).");
        }
    }

    // Методы остаются без изменений
    public function removeParams(): void { $this->paramsManager->removeAllParamsFromTable(self::TABLE_NAME); }
    public function getParam(string $key): mixed { return $this->paramsManager->getParam(self::TABLE_NAME, $key); }
    public function setParam(string $key, mixed $value): void { $this->paramsManager->setParam(self::TABLE_NAME, $key, $value); }
    public function unsetParam(string $key): void { $this->paramsManager->unsetParam(self::TABLE_NAME, $key); }
    public function unsetParams(array|string $keys): void { $this->paramsManager->unsetParams(self::TABLE_NAME, $keys); }
}

class Product
{
    public const TABLE_NAME = "Products";
    // УЛУЧШЕНИЕ: readonly свойство
    private readonly ParamsManagerInterface $paramsManager;
    // private readonly DB $dbInstance;

    public function __construct(DB $dbInstance, ParamsManagerInterface $paramsManager)
    {
        $this->paramsManager = $paramsManager;
        // $this->dbInstance = $dbInstance;

        $mainTableCommand = "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (id TEXT PRIMARY KEY, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, params TEXT)";
        if (!$dbInstance->executeSchemaStatement($mainTableCommand)) {
            LogManager::getLogger()->warning("Failed to create table " . self::TABLE_NAME . " (check logs for details).");
        }
        
        $indexCommands = [
            "CREATE INDEX IF NOT EXISTS idx_products_created_at ON " . self::TABLE_NAME . "(created_at)",
            "CREATE INDEX IF NOT EXISTS idx_products_title ON " . self::TABLE_NAME . "(title)"
        ];
        foreach ($indexCommands as $command) {
            if (!$dbInstance->executeSchemaStatement($command)) {
                 LogManager::getLogger()->warning("Failed to create index for table " . self::TABLE_NAME, ['command' => $command]);
            }
        }
    }

    // Методы остаются без изменений
    public function removeParams(): void { $this->paramsManager->removeAllParamsFromTable(self::TABLE_NAME); }
    public function getParam(string $key): mixed { return $this->paramsManager->getParam(self::TABLE_NAME, $key); }
    public function setParam(string $key, mixed $value): void { $this->paramsManager->setParam(self::TABLE_NAME, $key, $value); }
    public function unsetParam(string $key): void { $this->paramsManager->unsetParam(self::TABLE_NAME, $key); }
    public function unsetParams(array|string $keys): void { $this->paramsManager->unsetParams(self::TABLE_NAME, $keys); }
}

// --- Тестовый код ---
// Остается без изменений (уже хорошо структурирован)
$dbFileOrder = __DIR__ . '/data_order_test.db';
$dbFileProduct = __DIR__ . '/data_product_test.db';
$dsnOrder = 'sqlite:' . $dbFileOrder;
$dsnProduct = 'sqlite:' . $dbFileProduct;


function cleanupTestDb(string $dbFile): void {
    if (file_exists($dbFile)) unlink($dbFile);
    if (file_exists($dbFile . '-wal')) unlink($dbFile . '-wal');
    if (file_exists($dbFile . '-shm')) unlink($dbFile . '-shm');
}

if (!class_exists('\Codeception\Test\Unit')) {
    class MockCodeceptionTest
    {
        protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void { if ($expected !== $actual) { $e = var_export($expected, true); $a = var_export($actual, true); throw new \Exception("Assertion failed: $message. Expected: $e, Actual: $a"); } echo "PASSED: $message\n"; }
        protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void { if (!array_key_exists($key, $array)) { $a = var_export($array, true); throw new \Exception("Assertion failed: $message. Array $a does not have key '$key'."); } echo "PASSED: $message\n"; }
        protected function assertNull(mixed $actual, string $message = ''): void { if ($actual !== null) { $a = var_export($actual, true); throw new \Exception("Assertion failed: $message. Expected NULL, Actual: $a"); } echo "PASSED: $message\n"; }
        protected function assertTrue(mixed $condition, string $message = ''): void { if ($condition !== true) { throw new \Exception("Assertion failed: $message. Expected true, got ".var_export($condition, true)); } echo "PASSED: $message\n"; }
        protected function assertIsArray(mixed $actual, string $message = ''): void { if (!is_array($actual)) { $a = var_export($actual, true); throw new \Exception("Assertion failed: $message. Expected array, Actual: $a"); } echo "PASSED: $message\n"; }
        protected function assertNotNull(mixed $actual, string $message = ''): void { if ($actual === null) { throw new \Exception("Assertion failed: $message. Expected not NULL."); } echo "PASSED: $message\n"; }

    }
    class_alias('MockCodeceptionTest', '\Codeception\Test\Unit');
}

class ParamsTest extends \Codeception\Test\Unit
{
    private ?DB $dbOrderInstance = null;
    private ?DB $dbProductInstance = null;

    public function testOrderParams(): void
    {
        global $dbFileOrder, $dsnOrder;
        cleanupTestDb($dbFileOrder);
        
        CacheManager::initialize(new ApcuSimpleCache());
        if (CacheManager::isEnabled()) {
            CacheManager::deleteByTablePrefix(Order::TABLE_NAME . '_');
        }
        
        $this->dbOrderInstance = new DB($dsnOrder);
        $paramsManager = new PdoParamsManager($this->dbOrderInstance);
        $order = new Order($this->dbOrderInstance, $paramsManager);
        
        $this->processParamsTest($order, "Order");
        
        $this->dbOrderInstance = null;
        cleanupTestDb($dbFileOrder);
    }

    public function testProductParams(): void
    {
        global $dbFileProduct, $dsnProduct;
        cleanupTestDb($dbFileProduct);
        CacheManager::initialize(new ApcuSimpleCache());
        if (CacheManager::isEnabled()) {
            CacheManager::deleteByTablePrefix(Product::TABLE_NAME . '_');
        }

        $this->dbProductInstance = new DB($dsnProduct);
        $paramsManager = new PdoParamsManager($this->dbProductInstance);
        $product = new Product($this->dbProductInstance, $paramsManager);
        
        $this->processParamsTest($product, "Product");
        
        $this->dbProductInstance = null;
        cleanupTestDb($dbFileProduct);
    }
    
    protected function processParamsTest(Order|Product $model, string $modelName): void
    {
        // Тесты остаются теми же, они проверяют публичный API
        echo "\n--- Testing {$modelName} with PdoParamsManager (APCu Caching: ".(CacheManager::isEnabled() ? "Enabled" : "Disabled/Not Available").") ---\n";
        $model->removeParams();

        $model->setParam('simple_id.value', 1);
        $this->assertEquals(1, $model->getParam('simple_id.value'), "$modelName: Simple Key Value");
        $this->assertEquals(['value' => 1], $model->getParam('simple_id'), "$modelName: Get full params for simple_id");
        $this->assertEquals(1, $model->getParam('simple_id.value'), "$modelName: Simple Key Value (cache check)");

        $model->setParam('array_id.data', ['one' => 1, 'two' => 2]);
        $model->setParam('array_id.data.three', 3);
        $model->setParam('array_id.data.five', 5);
        
        $expectedArrayData = ['one' => 1, 'two' => 2, 'three' => 3, 'five' => 5];
        $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "$modelName: Full data array after additions");
        $this->assertEquals(2, $model->getParam('array_id.data.two'), "$modelName: Simple Array Key 'two'");
        $this->assertArrayHasKey('one', $model->getParam('array_id.data'), "$modelName: Array Data has key 'one'");
        $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "$modelName: Full data array (cache check)");

        $model->unsetParam('array_id.data.three');
        $this->assertNull($model->getParam('array_id.data.three'), "$modelName: array.data.three should be null after unset");
        $expectedAfterUnsetThree = ['one' => 1, 'two' => 2, 'five' => 5];
        $this->assertEquals($expectedAfterUnsetThree, $model->getParam('array_id.data'), "$modelName: Full data array after unsetting 'three'");

        $model->unsetParam('array_id.data.nonexistent.key'); 
        $this->assertNull($model->getParam('array_id.data.nonexistent'), "$modelName: array.data.nonexistent should be null");
        $this->assertEquals($expectedAfterUnsetThree, $model->getParam('array_id.data'), "$modelName: Full data array after attempting to unset non-existent deep key");

        $model->setParam('a.value', 100);
        $model->setParam('b.value', 200);
        
        $model->unsetParams(['a','b','array_id.data.five', 'non_existent_record']);
        $this->assertNull($model->getParam('a'), "$modelName: Record 'a' should be null after unsetParams");
        $this->assertNull($model->getParam('b'), "$modelName: Record 'b' should be null after unsetParams");
        $this->assertNull($model->getParam('array_id.data.five'), "$modelName: 'array_id.data.five' should be null after unsetParams");
        $expectedAfterUnsetFive = ['one' => 1, 'two' => 2];
        $this->assertEquals($expectedAfterUnsetFive, $model->getParam('array_id.data'), "$modelName: 'array_id.data' after unsetting 'five'");
        $this->assertNull($model->getParam('non_existent_record'), "$modelName: Non-existent record check after unsetParams");

        $model->setParam('c.value', 300);
        $model->setParam('d.another.key', 400);
        $model->setParam('d.another.level2.val', 500);

        $model->unsetParams(['c', 'd.another.key']);
        $this->assertNull($model->getParam('c'), "$modelName: Record 'c' should be null after unsetParams (array)");
        $this->assertNull($model->getParam('d.another.key'), "$modelName: 'd.another.key' should be null after unsetParams (array)");
        
        $dAnotherParams = $model->getParam('d.another');
        $this->assertIsArray($dAnotherParams, "$modelName: 'd.another' should still be an array");
        $this->assertEquals(['level2' => ['val' => 500]], $dAnotherParams, "$modelName: 'd.another' content after unsetting 'key'");
        
        $model->setParam('new_id.name', 'Tester');
        $this->assertEquals('Tester', $model->getParam('new_id.name'), "$modelName: Set param on new ID");
        $this->assertEquals(['name' => 'Tester'], $model->getParam('new_id'), "$modelName: Get full params for new_id");

        $model->setParam('overwrite_id', ['initial' => 'data']);
        $this->assertEquals(['initial' => 'data'], $model->getParam('overwrite_id'), "$modelName: Initial params for overwrite_id");
        $model->setParam('overwrite_id', ['title' => 'Product Title Overwritten', 'new_data' => true, 'value' => 42]);
        $expectedOverwrite = ['title' => 'Product Title Overwritten', 'new_data' => true, 'value' => 42];
        // Если это Product, то title из $value должен был попасть в колонку title, а не в JSON params.
        // Наша текущая логика setParam для Product не извлекает title из $value, если $value - это весь объект params.
        // Это нужно уточнить в тестах и логике setParam.
        // Давайте пока оставим как есть, но это потенциальная точка для более глубокой проработки,
        // если title должен управляться через setParam('id', ['title'=>'val', 'other_param'=>...])
        if ($model instanceof Product) {
            // Если title обновляется через setParam, то он не должен быть в JSON
            // unset($expectedOverwrite['title']); // Если title в отдельной колонке
        }
        $this->assertEquals($expectedOverwrite, $model->getParam('overwrite_id'), "$modelName: Overwritten full params for overwrite_id");


        $model->setParam('new_id.profile.age', 30);
        $expectedNewIdParams = ['name' => 'Tester', 'profile' => ['age' => 30]];
        $this->assertEquals($expectedNewIdParams, $model->getParam('new_id'), "$modelName: Get full params for new_id after adding nested structure");

        $model->setParam('to_delete_record.data', 'some data');
        $this->assertNotNull($model->getParam('to_delete_record'), "$modelName: Record to_delete_record exists before unset");
        $model->unsetParam('to_delete_record');
        $this->assertNull($model->getParam('to_delete_record'), "$modelName: Record to_delete_record should be null after unset with ID only");

        $model->setParam('temp1.val', 1);
        $model->setParam('temp2.val', 2);
        $model->removeParams();
        $this->assertNull($model->getParam('temp1'), "$modelName: temp1 should be null after removeParams");
        $this->assertNull($model->getParam('temp2'), "$modelName: temp2 should be null after removeParams");

        echo "--- {$modelName} Test Completed ---\n";
    }
}

// --- Запуск тестов ---
try {
    if (!class_exists(Monolog\Logger::class)) {
        echo "Monolog library is not available.\n";
    } else {
        LogManager::getLogger();
    }
    
    CacheManager::initialize();

    if (!CacheManager::isEnabled()) {
        echo "Caching will be skipped.\n";
    }

    $test = new ParamsTest();
    $test->testOrderParams();
    $test->testProductParams();
    echo "\nAll tests completed successfully! Check app.log for detailed logs.\n";

} catch (\Throwable $e) {
    $errorMessage = "\nCRITICAL ERROR during test execution: " . $e->getMessage() . "\n";
    $errorMessage .= "In file: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    $errorMessage .= "Check app.log for more details.\n";
    
    if (php_sapi_name() === 'cli') {
        file_put_contents('php://stderr', $errorMessage);
    } else {
        error_log(str_replace(["\r", "\n"], ' ', $errorMessage));
    }
    echo $errorMessage;
    exit(1);
}

?>