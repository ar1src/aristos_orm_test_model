<?php

declare(strict_types=1);

// Подключение автозагрузчика Composer.
// Предполагается, что папка vendor находится в корне проекта или на один уровень выше.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) { // Для случаев, когда этот файл может быть в поддиректории (например, _support)
    require_once __DIR__ . '/../../vendor/autoload.php';
}

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\MissingExtensionException; // Используется в ApcuSimpleCache для обработки отсутствия APCUIterator
use Psr\SimpleCache\CacheInterface; // Интерфейс для кэширования (PSR-16)

/**
 * LogManager предоставляет централизованный доступ к экземпляру логгера Monolog.
 * Также настраивает глобальные обработчики ошибок и исключений PHP для их логирования.
 */
final class LogManager
{
    private static ?Logger $logger = null;
    private static bool $handlerFailed = false; // Флаг, указывающий на неудачную инициализацию основного файлового обработчика логов

    /**
     * Возвращает единственный экземпляр Logger (Singleton).
     * При первой инициализации настраивает файловый обработчик и глобальные обработчики ошибок/исключений.
     * В случае сбоя основного обработчика, предоставляет запасной NullHandler.
     *
     * @return Logger Экземпляр логгера Monolog.
     */
    public static function getLogger(): Logger
    {
        if (self::$logger === null && !self::$handlerFailed) {
            try {
                $formatter = new LineFormatter(null, null, true, true); // Формат: [datetime] channel.LEVEL: message context extra stacktraces
                $formatter->includeStacktraces(true); // Включаем трассировку стека в логи

                // Путь к лог-файлу относительно текущего файла (ParamsManagerModuleV3.php)
                // Предполагается, что этот файл находится в корне проекта или в директории, где лог должен создаваться.
                $logFilePath = __DIR__ . '/app.log';
                $logDir = dirname($logFilePath);

                // Попытка создать директорию для логов, если она не существует
                if (!is_dir($logDir)) {
                    // Подавляем ошибку, если директория уже создана другим процессом между is_dir и mkdir
                    @mkdir($logDir, 0775, true);
                }

                // Проверка прав на запись в директорию или файл логов
                if (!is_writable($logDir) || (file_exists($logFilePath) && !is_writable($logFilePath))) {
                    $errorMessage = "Каталог или файл логов ({$logFilePath}) недоступен для записи. Проверьте права доступа.";
                    error_log($errorMessage); // Запасное логирование через error_log PHP
                    if (php_sapi_name() === 'cli') { // Вывод в stderr для CLI
                        file_put_contents('php://stderr', $errorMessage . PHP_EOL);
                    }
                    // Используем NullHandler, чтобы приложение не падало, но проблема была зафиксирована
                    self::$logger = new Logger('App_Fallback_Permissions_Error');
                    self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
                    self::$handlerFailed = true;
                } else {
                    // Основной файловый обработчик
                    $handler = new StreamHandler($logFilePath, Logger::DEBUG); // Логируем все сообщения уровня DEBUG и выше
                    $handler->setFormatter($formatter);
                    self::$logger = new Logger('App'); // Имя канала логгера
                    self::$logger->pushHandler($handler);
                }

                // Регистрируем глобальные обработчики, даже если используется NullHandler,
                // чтобы перехватывать исключения/ошибки и пытаться их логировать (хотя бы через error_log PHP)
                if (self::$logger !== null) {
                    self::registerGlobalHandlers();
                }

            } catch (\Exception $e) { // Отлов любых исключений при инициализации логгера
                self::$handlerFailed = true;
                error_log("Критическая ошибка инициализации LogManager: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
                self::$logger = new Logger('App_Fallback_Init_Error');
                self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
                 if (self::$logger !== null) {
                    self::registerGlobalHandlers(); // Попытка зарегистрировать обработчики даже при ошибке
                }
            }
        } elseif (self::$logger === null && self::$handlerFailed) {
             // Если основная инициализация не удалась, и логгер все еще null, создаем самый базовый NullHandler
             if (!isset(self::$logger) || self::$logger === null) {
                self::$logger = new Logger('App_Fallback_Critical_Failure');
                self::$logger->pushHandler(new \Monolog\Handler\NullHandler());
            }
        }
        return self::$logger;
    }

    /**
     * Регистрирует глобальные обработчики для неперехваченных исключений и ошибок PHP.
     */
    private static function registerGlobalHandlers(): void
    {
        // Обработчик для неперехваченных исключений
        set_exception_handler(function (\Throwable $exception) {
            $loggerInstance = LogManager::getLogger(); // Получаем уже существующий или fallback логгер
            if ($loggerInstance) {
                $loggerInstance->critical(
                    "Неперехваченное исключение: " . $exception->getMessage(),
                    [
                        'exception_class' => get_class($exception),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => $exception->getTraceAsString()
                    ]
                );
            } else {
                 // Крайний случай, если логгер совсем недоступен
                 error_log("Fallback Logger: Неперехваченное исключение: " . $exception->getMessage() . " в " . $exception->getFile() . ":" . $exception->getLine());
            }

            // Для веб-запросов отправляем HTTP 500, если заголовки еще не отправлены
            if (php_sapi_name() !== 'cli' && !headers_sent()) {
                http_response_code(500);
                // Можно вывести простое сообщение об ошибке для пользователя, не раскрывая деталей
                // echo "Произошла внутренняя ошибка сервера.";
            }
            // Для CLI можно вывести сообщение в stderr
            // В текущей реализации это не делается, чтобы не дублировать вывод из set_exception_handler, если он есть у фреймворка
        });

        // Обработчик для ошибок PHP (warning, notice и т.д.)
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            // Этот обработчик ошибок будет вызван только для ошибок, указанных в error_reporting
            if (!(error_reporting() & $severity)) {
                return false; // Не обрабатывать эту ошибку, передать стандартному обработчику PHP
            }
            $loggerInstance = LogManager::getLogger();
            if ($loggerInstance) {
                // Определяем уровень логирования Monolog на основе серьезности ошибки PHP
                $level = Logger::ERROR; // По умолчанию
                switch ($severity) {
                    case E_WARNING:
                    case E_USER_WARNING:
                        $level = Logger::WARNING;
                        break;
                    case E_NOTICE:
                    case E_USER_NOTICE:
                    case E_DEPRECATED:
                    case E_USER_DEPRECATED:
                    case E_STRICT: // E_STRICT устарел, но для полноты
                        $level = Logger::NOTICE;
                        break;
                }
                $loggerInstance->log(
                    $level,
                    "Ошибка PHP: {$message}",
                    ['severity_php' => $severity, 'file' => $file, 'line' => $line]
                );
            } else {
                error_log("Fallback Logger: Ошибка PHP: {$message} в {$file}:{$line} (Severity: {$severity})");
            }
            return true; // Сообщаем PHP, что мы обработали ошибку, и стандартный обработчик не нужен
        });
    }
}

// Первичная инициализация LogManager для установки глобальных обработчиков,
// если библиотека Monolog доступна.
if (class_exists(Monolog\Logger::class)) {
    LogManager::getLogger();
}

/**
 * Реализация интерфейса PSR-16 SimpleCache с использованием расширения APCu.
 * Предоставляет базовые операции кэширования: get, set, delete, clear и т.д.
 */
class ApcuSimpleCache implements CacheInterface
{
    /** @var string Префикс для всех ключей кэша, чтобы избежать коллизий. */
    private const CACHE_PREFIX = 'app_params_';
    /** @var int Время жизни кэша по умолчанию в секундах (1 час). */
    private const DEFAULT_TTL = 3600;

    /**
     * Проверяет, активно ли расширение APCu.
     * @return bool True, если APCu загружено и включено, иначе false.
     */
    private function isApcuActive(): bool
    {
        return extension_loaded('apcu') && apcu_enabled();
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isApcuActive()) return $default;
        $success = false;
        $value = apcu_fetch(self::CACHE_PREFIX . $key, $success);
        return $success ? $value : $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if (!$this->isApcuActive()) return false;
        $actualTtl = self::DEFAULT_TTL;
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable(); // Используем неизменяемый объект даты
            $end = $now->add($ttl);
            $actualTtl = $end->getTimestamp() - $now->getTimestamp();
            if ($actualTtl < 0) $actualTtl = 0; // TTL не может быть отрицательным
        } elseif (is_int($ttl)) {
            $actualTtl = $ttl;
        }
        return apcu_store(self::CACHE_PREFIX . $key, $value, $actualTtl);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (!$this->isApcuActive()) return false;
        return apcu_delete(self::CACHE_PREFIX . $key);
    }

    /**
     * {@inheritdoc}
     * Очищает все записи кэша с текущим префиксом, если доступен APCUIterator.
     * В противном случае, очистка по префиксу может быть неэффективной или невозможной.
     */
    public function clear(): bool
    {
        if (!$this->isApcuActive()) return false;
        LogManager::getLogger()->debug("ApcuSimpleCache::clear() called, attempting to clear by prefix: " . self::CACHE_PREFIX);
        try {
            // APCUIterator необходим для эффективной очистки по префиксу.
            if (class_exists('APCUIterator')) {
                // Регулярное выражение для поиска ключей, начинающихся с префикса
                $regex = '#^' . preg_quote(self::CACHE_PREFIX, '#') . '.*#';
                $iterator = new \APCUIterator($regex, \APC_ITER_KEY);
                $keysToDelete = [];
                foreach ($iterator as $item) {
                    // В зависимости от версии APCU/PHP, $item может быть строкой (ключом) или массивом
                    $currentKey = is_array($item) && isset($item['key']) ? $item['key'] : (is_string($item) ? $item : null);
                    if ($currentKey !== null) {
                         $keysToDelete[] = $currentKey;
                    }
                }
                if (!empty($keysToDelete)) {
                    apcu_delete($keysToDelete); // Удаляем найденные ключи
                }
                return true;
            }
        } catch (MissingExtensionException $e) { // Исключение, если APCUIterator не найден (например, apcu.enable_cli=0)
            LogManager::getLogger()->warning("APCUIterator class not found for ApcuSimpleCache::clear. Cache cannot be cleared effectively by prefix.", ['exception' => $e->getMessage()]);
        } catch (\Throwable $e) { // Любые другие ошибки при работе с итератором
            LogManager::getLogger()->error("Error during APCUIterator usage for ApcuSimpleCache::clear.", ['exception' => $e]);
        }
        // Если APCUIterator недоступен, полная очистка по префиксу невозможна стандартными средствами APCu.
        // apcu_clear_cache() очистит весь пользовательский кэш, что может быть нежелательно.
        // Поэтому возвращаем false, если итератор не сработал.
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!$this->isApcuActive()) {
            $result = [];
            foreach ($keys as $key) { $result[(string)$key] = $default; }
            return $result;
        }
        $prefixedKeys = [];
        foreach ($keys as $key) { $prefixedKeys[] = self::CACHE_PREFIX . (string)$key; }

        $rawValues = apcu_fetch($prefixedKeys); // apcu_fetch может вернуть false при ошибке или пустой массив
        if ($rawValues === false) $rawValues = []; // Нормализуем до пустого массива в случае ошибки

        $results = [];
        foreach ($keys as $originalKey) {
            $strOriginalKey = (string)$originalKey;
            $prefixedKey = self::CACHE_PREFIX . $strOriginalKey;
            // Проверяем наличие ключа в полученных значениях, так как apcu_fetch для отсутствующих ключей не добавляет их в результат
            $results[$strOriginalKey] = array_key_exists($prefixedKey, $rawValues) ? $rawValues[$prefixedKey] : $default;
        }
        return $results;
    }

    /**
     * {@inheritdoc}
     */
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
        if (empty($prefixedValues)) return true; // Нет значений для установки

        // apcu_store для массива значений возвращает массив с ключами, которые не удалось сохранить, или пустой массив при успехе.
        $errors = apcu_store($prefixedValues, null, $actualTtl);
        return empty($errors); // Успех, если массив ошибок пуст
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        if (!$this->isApcuActive()) return false;
        $prefixedKeys = [];
        foreach ($keys as $key) { $prefixedKeys[] = self::CACHE_PREFIX . (string)$key; }
        if (empty($prefixedKeys)) return true;

        // apcu_delete для массива ключей возвращает true при успехе или массив с ключами, которые не удалось удалить.
        $result = apcu_delete($prefixedKeys);
        return $result === true || (is_array($result) && empty($result)); // Успех, если true или пустой массив ошибок
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        if (!$this->isApcuActive()) return false;
        return apcu_exists(self::CACHE_PREFIX . $key);
    }

    /**
     * Удаляет все записи кэша, ключи которых начинаются с указанного префикса таблицы.
     * Например, для 'Orders' будут удалены ключи, начинающиеся с 'app_params_Orders_'.
     * Требует APCUIterator для эффективной работы.
     *
     * @param string $tablePrefix Префикс таблицы (например, "Orders").
     * @return bool True в случае успеха или если APCu неактивен, false при ошибке или если итератор недоступен.
     */
    public function deleteByTablePrefix(string $tablePrefix): bool
    {
        if (!$this->isApcuActive()) return false; // Или true, если считать, что нет кэша = успешно очищено
        
        // Формируем полный префикс для поиска в APCu
        $fullPrefixToDelete = self::CACHE_PREFIX . $tablePrefix . '_'; // Убедимся, что префикс таблицы отделен (например, "Orders_")
        LogManager::getLogger()->debug("ApcuSimpleCache::deleteByTablePrefix() called for prefix: " . $fullPrefixToDelete);
        try {
            if (class_exists('APCUIterator')) {
                $regex = '#^' . preg_quote($fullPrefixToDelete, '#') . '.*#';
                $iterator = new \APCUIterator($regex, \APC_ITER_KEY);
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

/**
 * CacheManager предоставляет статический доступ к экземпляру кэша.
 * Позволяет легко переключать реализацию кэша или отключать его.
 */
final class CacheManager
{
    private static ?CacheInterface $cacheInstance = null;
    private static bool $apcuWarningLogged = false; // Флаг, чтобы не логировать предупреждение об APCu многократно
    private static bool $initialized = false; // Флаг инициализации

    /**
     * Инициализирует менеджер кэша.
     * Можно передать конкретную реализацию CacheInterface,
     * либо менеджер попытается использовать ApcuSimpleCache, если APCu доступен.
     * Если кэш не может быть инициализирован, он будет отключен.
     *
     * @param CacheInterface|null $cache Экземпляр реализации кэша или null для автоопределения.
     */
    public static function initialize(?CacheInterface $cache = null): void
    {
        if ($cache !== null) {
            self::$cacheInstance = $cache;
        } elseif (extension_loaded('apcu') && apcu_enabled()) {
            self::$cacheInstance = new ApcuSimpleCache();
        } else {
            self::$cacheInstance = null; // Кэширование будет отключено
             if (!self::$apcuWarningLogged && $cache === null) { // Логируем предупреждение только один раз
                if (class_exists(LogManager::class)) { // Убедимся, что LogManager доступен
                     LogManager::getLogger()->warning("APCu не доступен и не предоставлена кастомная реализация кэша. Кэширование будет отключено.");
                }
                self::$apcuWarningLogged = true;
            }
        }
        self::$initialized = true;
    }

    /**
     * Возвращает экземпляр кэша. Инициализирует его при первом вызове, если не был инициализирован ранее.
     * @return CacheInterface|null Экземпляр кэша или null, если кэширование отключено.
     */
    private static function getCache(): ?CacheInterface
    {
        if (!self::$initialized) {
            self::initialize(); // Автоинициализация при первом обращении
        }
        return self::$cacheInstance;
    }

    /**
     * Проверяет, включено ли кэширование.
     * @return bool True, если кэш активен, иначе false.
     */
    public static function isEnabled(): bool
    {
        return self::getCache() !== null;
    }

    /**
     * Получает значение из кэша по ключу.
     * @param string $key Ключ.
     * @return mixed Значение из кэша или false, если ключ не найден или кэш отключен.
     */
    public static function get(string $key): mixed
    {
        // Возвращаем false, если ключ не найден или кэш отключен, чтобы отличать от null, который может быть закэширован.
        // Пользователь должен проверять на === false.
        return self::getCache()?->get($key, false) ?? false;
    }

    /**
     * Сохраняет значение в кэше.
     * @param string $key Ключ.
     * @param mixed $value Значение.
     * @param int $ttl Время жизни в секундах.
     * @return bool True в случае успеха, false при ошибке или если кэш отключен.
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return self::getCache()?->set($key, $value, $ttl) ?? false;
    }

    /**
     * Удаляет значение из кэша по ключу.
     * @param string $key Ключ.
     * @return bool True в случае успеха, false при ошибке или если кэш отключен.
     */
    public static function delete(string $key): bool
    {
        return self::getCache()?->delete($key) ?? false;
    }
    
    /**
     * Очищает весь кэш (если реализация это поддерживает).
     * @return bool True в случае успеха или если кэш неактивен, false при ошибке.
     */
    public static function clear(): bool {
        $cache = self::getCache();
        if ($cache !== null) {
            return $cache->clear();
        }
        return true; // Если кэш неактивен, считаем очистку успешной (нечего очищать)
    }

    /**
     * Удаляет записи из кэша по префиксу таблицы.
     * Специфично для реализаций кэша, поддерживающих такой метод (например, ApcuSimpleCache с APCUIterator).
     * @param string $tablePrefix Префикс таблицы (например, "Orders").
     * @return bool True в случае успеха, false при ошибке или если метод не поддерживается.
     */
    public static function deleteByTablePrefix(string $tablePrefix): bool
    {
        $cache = self::getCache();
        // Проверяем, является ли наш кэш экземпляром ApcuSimpleCache, который имеет этот метод
        if ($cache instanceof ApcuSimpleCache) {
            return $cache->deleteByTablePrefix($tablePrefix);
        }
        // Если используется другая реализация кэша, логируем предупреждение
        if ($cache !== null) {
             if (class_exists(LogManager::class)) {
                 LogManager::getLogger()->warning("Метод deleteByTablePrefix специфичен для ApcuSimpleCache и не доступен для текущей реализации кэша: " . get_class($cache));
             }
        }
        return false; // Метод не поддерживается текущей реализацией или кэш отключен
    }
}

/**
 * Класс DB предоставляет обертку над PDO для взаимодействия с базой данных.
 * Поддерживает базовые операции и специфичные настройки для SQLite.
 */
class DB
{
    /** @var \PDO|null Экземпляр PDO для работы с БД. Null, если соединение не установлено или закрыто. */
    private ?\PDO $pdo = null;
    /** @var string Строка DSN, используемая для подключения. Только для чтения после инициализации. */
    private readonly string $dsnForLog; // PHP 8.1+
    /** @var array<string, bool> Массив для отслеживания инициализированных таблиц в рамках данного экземпляра DB. */
    private array $initializedTables = [];

    /**
     * Конструктор класса DB. Устанавливает соединение с БД и применяет начальные настройки.
     *
     * @param string $dsn Строка Data Source Name для PDO.
     * @param string|null $username Имя пользователя для подключения к БД.
     * @param string|null $password Пароль для подключения к БД.
     * @param array $options Дополнительные опции для драйвера PDO.
     * @throws \RuntimeException Если не удалось инициализировать БД.
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        $this->dsnForLog = $dsn;
        $logManagerExists = class_exists(LogManager::class, false); // Проверяем без автозагрузки, чтобы не вызвать ее случайно

        // Если используется SQLite и файл БД не в памяти, пытаемся создать директорию для файла БД
        if (str_starts_with(strtolower($dsn), 'sqlite:')) {
            $dbFile = substr($dsn, 7); // Получаем путь к файлу из DSN
            if ($dbFile !== ':memory:') { // Не применяем для БД в памяти
                if ($logManagerExists) LogManager::getLogger()->info("DB DSN: {$dsn}, предполагаемый файл БД: {$dbFile}");
                $dbDir = dirname($dbFile);
                // Создаем директорию, только если она указана и не является текущей директорией
                if (!empty($dbDir) && $dbDir !== '.' && !is_dir($dbDir)) {
                    if ($logManagerExists) LogManager::getLogger()->info("Попытка создать директорию для файла БД: {$dbDir}");
                    if (!@mkdir($dbDir, 0775, true) && !is_dir($dbDir)) { // Подавляем ошибку, если директория уже существует
                        if ($logManagerExists) LogManager::getLogger()->error("Не удалось создать директорию для файла БД: {$dbDir}. Проверьте права доступа.");
                        // Не бросаем исключение здесь, так как PDO может сам создать файл, если директория верхнего уровня доступна.
                    }
                }
            }
        }

        // Опции PDO по умолчанию
        $defaultOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, // Выбрасывать исключения при ошибках PDO
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, // Возвращать результаты как ассоциативные массивы
            \PDO::ATTR_EMULATE_PREPARES => false, // Использовать настоящие подготовленные запросы
        ];
        $finalOptions = array_replace($defaultOptions, $options); // Пользовательские опции переопределяют дефолтные

        try {
            $this->pdo = new \PDO($dsn, $username, $password, $finalOptions);
            // Специфичные PRAGMA для SQLite для улучшения производительности и надежности
            if (str_starts_with(strtolower($dsn), 'sqlite:')) {
                $this->pdo->exec('PRAGMA journal_mode = WAL;'); // Write-Ahead Logging
                $this->pdo->exec('PRAGMA foreign_keys = ON;'); // Включить поддержку внешних ключей
                $this->pdo->exec('PRAGMA busy_timeout = 5000;'); // Таймаут при блокировке БД (5 секунд)
                $this->pdo->exec('PRAGMA synchronous = NORMAL;'); // Режим синхронизации (NORMAL - хороший компромисс)
            }
        } catch (\PDOException $e) {
            $this->pdo = null; // Убедимся, что pdo null в случае ошибки подключения
            if ($logManagerExists) LogManager::getLogger()->critical("Ошибка подключения/настройки БД для DSN {$dsn}", ['exception_class' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
            throw new \RuntimeException("Ошибка инициализации БД: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Явно закрывает соединение с БД, обнуляя объект PDO.
     * Это важно для освобождения ресурсов, особенно для SQLite, чтобы файл не оставался заблокированным.
     */
    public function close(): void
    {
        if ($this->pdo !== null) {
            $this->pdo = null; // Обнуление объекта PDO инициирует закрытие соединения
            if (class_exists(LogManager::class, false)) {
                LogManager::getLogger()->info("Соединение с БД было явно закрыто для DSN: {$this->dsnForLog}");
            }
        }
    }

    /**
     * Деструктор. Гарантирует закрытие соединения при уничтожении объекта DB.
     */
    public function __destruct()
    {
        $this->close(); // Вызываем явный метод закрытия
    }
    
    /**
     * Внутренний метод для получения активного экземпляра PDO.
     * @return \PDO Активный экземпляр PDO.
     * @throws \RuntimeException Если соединение PDO не доступно.
     */
    private function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            // Эта ситуация не должна возникать в нормальном потоке, если объект DB был успешно создан.
            // Может произойти, если методы вызываются после явного close() без пересоздания объекта DB.
            LogManager::getLogger()->critical("Попытка использовать закрытое или неинициализированное PDO соединение.", ['dsn' => $this->dsnForLog]);
            throw new \RuntimeException("PDO соединение не доступно. Возможно, оно было закрыто или не удалось его установить.");
        }
        return $this->pdo;
    }

    /**
     * Возвращает DSN, использованный для инициализации этого экземпляра DB.
     * Может использоваться для генерации уникальных ключей, связанных с конкретной БД.
     * @return string DSN строка.
     */
    public function getDsnForInitKey(): string
    {
        return $this->dsnForLog;
    }

    /**
     * Проверяет, была ли таблица уже инициализирована (создана схема)
     * в рамках текущего экземпляра соединения DB.
     * @param string $tableName Имя таблицы.
     * @return bool True, если таблица помечена как инициализированная, иначе false.
     */
    public function isTableInitialized(string $tableName): bool
    {
        return isset($this->initializedTables[$tableName]);
    }

    /**
     * Помечает таблицу как инициализированную в рамках текущего экземпляра DB.
     * @param string $tableName Имя таблицы.
     */
    public function markTableAsInitialized(string $tableName): void
    {
        $this->initializedTables[$tableName] = true;
    }

    /**
     * Выполняет DDL-выражение (например, CREATE TABLE, CREATE INDEX).
     * @param string $statement SQL-выражение для изменения схемы.
     * @return bool True в случае успеха, false при ошибке (ошибка будет залогирована).
     */
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

    /**
     * Выполняет SQL-запрос (INSERT, UPDATE, DELETE) с возможностью передачи параметров.
     * @param string $query SQL-запрос с плейсхолдерами.
     * @param array $params Ассоциативный массив параметров для подготовленного запроса.
     * @return bool True в случае успеха, false при ошибке (исключение будет выброшено).
     * @throws \RuntimeException При ошибке выполнения SQL-запроса.
     */
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
    
    /**
     * Выполняет SQL-запрос и возвращает значение первого столбца первой строки результата.
     * @param string $query SQL-запрос.
     * @param array $params Параметры для подготовленного запроса.
     * @return mixed Значение из ячейки или false, если результат пуст или произошла ошибка.
     * @throws \RuntimeException При ошибке выполнения SQL-запроса.
     */
    public function fetchValue(string $query, array $params = []): mixed
    {
        try {
            $stmt = $this->getPdo()->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchColumn(); // Возвращает одно значение или false
        } catch (\PDOException $e) {
            LogManager::getLogger()->error("Ошибка SQL-запроса (fetchValue)", ['query' => $query, 'params' => $params, 'error_code' => $e->getCode(), 'error_info' => $e->errorInfo, 'message' => $e->getMessage()]);
            throw new \RuntimeException("Ошибка SQL-запроса: " . $e->getMessage() . " Запрос: " . $query, (int)$e->getCode(), $e);
        }
    }

    /**
     * Выполняет SQL-запрос и возвращает все строки результата.
     * @param string $query SQL-запрос.
     * @param array $params Параметры для подготовленного запроса.
     * @return array Массив всех строк результата. Пустой массив, если результат пуст.
     * @throws \RuntimeException При ошибке выполнения SQL-запроса.
     */
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

    /** Начинает транзакцию. @return bool */
    public function beginTransaction(): bool { return $this->getPdo()->beginTransaction(); }
    /** Фиксирует транзакцию. @return bool */
    public function commit(): bool { return $this->getPdo()->commit(); }
    /** Откатывает транзакцию. @return bool */
    public function rollBack(): bool { return $this->getPdo()->rollBack(); }
    /** Проверяет, активна ли транзакция. @return bool */
    public function inTransaction(): bool { return $this->getPdo()->inTransaction(); }
}

/**
 * Интерфейс для менеджера параметров.
 * Определяет основные операции для работы с параметрами, хранящимися в таблицах.
 */
interface ParamsManagerInterface
{
    /**
     * Получает параметр или вложенное значение из поля params.
     * @param string $table Имя таблицы.
     * @param string $fullKey Полный ключ в формате "id.path.to.value" или "id" для получения всего объекта params.
     * @return mixed Значение параметра или null, если не найдено.
     */
    public function getParam(string $table, string $fullKey): mixed;

    /**
     * Устанавливает параметр или вложенное значение в поле params.
     * Создает запись, если она не существует (UPSERT).
     * @param string $table Имя таблицы.
     * @param string $fullKey Полный ключ в формате "id.path.to.value" или "id" для установки всего объекта params.
     * @param mixed $value Устанавливаемое значение.
     */
    public function setParam(string $table, string $fullKey, mixed $value): void;

    /**
     * Удаляет параметр, вложенное значение из поля params или всю запись.
     * Если $fullKey содержит только "id", удаляется вся запись.
     * Иначе удаляется элемент по указанному пути в JSON-структуре поля params.
     * @param string $table Имя таблицы.
     * @param string $fullKey Полный ключ в формате "id.path.to.value" или "id".
     */
    public function unsetParam(string $table, string $fullKey): void;

    /**
     * Удаляет несколько параметров или записей.
     * @param string $table Имя таблицы.
     * @param array|string $keys Массив ключей или строка ключей, разделенных запятой.
     *                           Каждый ключ в формате "id.path.to.value" или "id".
     */
    public function unsetParams(string $table, array|string $keys): void;

    /**
     * Удаляет все записи (и их параметры) из указанной таблицы.
     * @param string $table Имя таблицы.
     */
    public function removeAllParamsFromTable(string $table): void;
}

/**
 * Реализация ParamsManagerInterface с использованием PDO для хранения параметров в виде JSON.
 */
class PdoParamsManager implements ParamsManagerInterface
{
    /** @var DB Экземпляр для работы с базой данных. Только для чтения после инициализации. */
    private readonly DB $db; // PHP 8.1+

    /**
     * @param DB $db Экземпляр DB для взаимодействия с базой данных.
     */
    public function __construct(DB $db)
    {
        $this->db = $db;
    }

    /**
     * Генерирует ключ для кэширования на основе имени таблицы и ID записи.
     * @param string $table Имя таблицы.
     * @param string $id ID записи.
     * @return string Сформированный ключ кэша.
     */
    private function getCacheKeyForId(string $table, string $id): string
    {
        return $table . '_' . $id; // Простой формат ключа
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllParamsFromTable(string $table): void
    {
        try {
            $this->db->execute("DELETE FROM {$table}"); // Используем плейсхолдер для имени таблицы небезопасно, но здесь $table приходит из констант моделей
            if (CacheManager::isEnabled()) {
                // Очищаем кэш для этой таблицы по префиксу
                CacheManager::deleteByTablePrefix($table . '_');
            }
        } catch (\Throwable $e) {
            LogManager::getLogger()->error("Ошибка при удалении всех параметров из таблицы {$table}", ['exception_class' => get_class($e), 'message' => $e->getMessage()]);
            throw $e; // Перебрасываем исключение для дальнейшей обработки
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getParam(string $table, string $fullKey): mixed
    {
        $paramParts = explode(".", $fullKey);
        $id = array_shift($paramParts); // Первый элемент - это ID записи

        if (empty($id)) {
            LogManager::getLogger()->warning("getParam вызван с пустым ID для таблицы {$table}: '{$fullKey}'");
            return null;
        }

        $cacheKey = $this->getCacheKeyForId($table, $id);
        $cachedParamsObject = CacheManager::get($cacheKey);

        $paramsObject = null;
        if ($cachedParamsObject !== false) { // false означает, что ключ не найден в кэше
            $paramsObject = $cachedParamsObject;
        } else {
            try {
                // Извлекаем JSON-строку из БД
                $jsonString = $this->db->fetchValue("SELECT params FROM {$table} WHERE id = :id", [':id' => $id]);
            } catch (\Throwable $e) { // Ловим любые ошибки БД
                LogManager::getLogger()->error("Ошибка БД при получении параметра для таблицы {$table}, ID {$id}", ['exception_class' => get_class($e), 'message' => $e->getMessage()]);
                throw $e; // Перебрасываем, чтобы вызывающий код знал об ошибке
            }

            if ($jsonString === null || $jsonString === false) { // Запись не найдена или params IS NULL
                return null;
            }
            
            try {
                // Декодируем JSON-строку в PHP-массив
                $paramsObject = json_decode((string)$jsonString, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                 LogManager::getLogger()->error("Ошибка декодирования JSON из БД для таблицы {$table}, ID {$id}", ['json_error' => $e->getMessage(), 'json_string_snippet' => substr((string)$jsonString, 0, 100)]);
                 // Бросаем RuntimeException, чтобы указать на проблему с данными, а не с БД
                 throw new \RuntimeException("Ошибка декодирования JSON из БД для таблицы {$table}, ID {$id}: " . $e->getMessage(), $e->getCode(), $e);
            }

            // Сохраняем полученный объект в кэш
            if (CacheManager::isEnabled()) {
                CacheManager::set($cacheKey, $paramsObject);
            }
        }
        
        // Если путь к вложенному элементу не указан (только ID), возвращаем весь объект params
        if (empty($paramParts)) {
            return $paramsObject;
        }

        // Иначе, ищем вложенное значение по пути
        $data = $paramsObject;
        foreach ($paramParts as $part) {
            if (is_array($data) && array_key_exists($part, $data)) {
                $data = $data[$part];
            } else {
                return null; // Элемент по пути не найден
            }
        }
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function setParam(string $table, string $fullKey, mixed $value): void
    {
        $paramParts = explode(".", $fullKey);
        $id = array_shift($paramParts);

        if (empty($id)) {
            throw new \InvalidArgumentException("ID не может быть пустым для setParam в таблице {$table}.");
        }
        
        $localTransaction = false;
        // Начинаем транзакцию, если она еще не активна, чтобы обеспечить атомарность операции чтения-изменения-записи
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $localTransaction = true;
        }

        try {
            // Получаем текущее состояние params из БД.
            // В идеале, для предотвращения race conditions в высоконагруженных системах, здесь нужна блокировка строки (SELECT ... FOR UPDATE),
            // но SQLite не поддерживает ее так, как PostgreSQL или MySQL. Транзакция помогает для консистентности.
            $selectQuery = "SELECT params FROM {$table} WHERE id = :id";
            $jsonString = $this->db->fetchValue($selectQuery, [':id' => $id]);
            $phpArray = []; // Массив, который будет содержать обновленные параметры

            if ($jsonString !== null && $jsonString !== false) { // Если запись существует и params не NULL
                try {
                    $phpArray = json_decode((string)$jsonString, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    if ($localTransaction) $this->db->rollBack(); // Откатываем транзакцию при ошибке
                    LogManager::getLogger()->error("setParam: Ошибка декодирования существующего JSON для таблицы {$table}, ID {$id}", ['json_error' => $e->getMessage()]);
                    throw new \RuntimeException("Ошибка декодирования существующего JSON для таблицы {$table}, ID {$id}: " . $e->getMessage(), $e->getCode(), $e);
                }
            }
            
            // Модифицируем PHP-массив
            $temp = &$phpArray; // Работаем по ссылке для изменения вложенных массивов
            if (empty($paramParts)) { // Если путь не указан, перезаписываем весь объект params
                $phpArray = $value;
            } else {
                // Иначе, идем по пути и устанавливаем значение
                foreach ($paramParts as $index => $part) {
                    if ($index === count($paramParts) - 1) { // Последний элемент пути - устанавливаем значение
                        if (!is_array($temp) && !is_null($temp)) { // Нельзя установить ключ на не-массив (кроме null)
                             if ($localTransaction) $this->db->rollBack();
                             throw new \RuntimeException("Невозможно установить ключ '{$part}' на не-массив по пути для таблицы {$table}, ID {$id}. Текущий тип: " . gettype($temp));
                        }
                        if (is_null($temp)) $temp = []; // Если родитель null, инициализируем как массив
                        $temp[$part] = $value;
                    } else { // Промежуточный элемент пути
                        if (!isset($temp[$part]) || !is_array($temp[$part])) {
                            $temp[$part] = []; // Создаем вложенный массив, если его нет или он не массив
                        }
                        $temp = &$temp[$part]; // Переходим на следующий уровень вложенности
                    }
                }
            }
            unset($temp); // Удаляем ссылку

            // Кодируем обновленный массив обратно в JSON
            try {
                $newJson = json_encode($phpArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } catch (\JsonException $e) {
                if ($localTransaction) $this->db->rollBack();
                LogManager::getLogger()->error("setParam: Ошибка кодирования значения в JSON для таблицы {$table}, ID {$id}", ['json_error' => $e->getMessage()]);
                throw new \RuntimeException("Ошибка кодирования значения в JSON для таблицы {$table}, ID {$id}: " . $e->getMessage(), $e->getCode(), $e);
            }

            // Подготовка к UPSERT
            $columns = ['id', 'params'];
            $placeholders = [':id', ':params_insert']; // Плейсхолдеры для INSERT части
            $bindings = [':id' => $id, ':params_insert' => $newJson, ':params_update' => $newJson]; // Общие биндинги
            
            $updateAssignments = "params = :params_update"; // SET часть для UPDATE

            // Специальная обработка для таблицы Product: обновляем поле title, если оно есть в корне $value
            if ($table === Product::TABLE_NAME) {
                $columns[] = 'title';
                $placeholders[] = ':title_insert'; // Для INSERT части

                $titleForInsert = 'Default Product Title'; // Значение по умолчанию для нового продукта
                $titleForUpdate = null; // Не обновляем title по умолчанию при UPDATE существующей записи

                // Если устанавливается весь объект params (нет $paramParts) и $value - массив с ключом 'title'
                if (empty($paramParts) && is_array($value) && array_key_exists('title', $value)) {
                    $titleForInsert = $value['title']; // Используем title из $value для INSERT
                    $titleForUpdate = $value['title']; // Используем title из $value для UPDATE
                } 
                elseif ($jsonString === null || $jsonString === false) { // Если это новая запись (старого $jsonString не было)
                    // $titleForInsert уже 'Default Product Title'
                }
                // Иначе (существующая запись, и title не обновляется через $value в корне) - title не меняется
                
                $bindings[':title_insert'] = $titleForInsert;

                if ($titleForUpdate !== null) { // Если title нужно обновить
                    $updateAssignments .= ", title = :title_update";
                    $bindings[':title_update'] = $titleForUpdate;
                }
            }
            
            // Формируем UPSERT запрос (специфичен для SQLite)
            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(id) DO UPDATE SET %s",
                $table, // Имя таблицы (из константы модели, считается безопасным)
                implode(', ', $columns),
                implode(', ', $placeholders),
                $updateAssignments
            );
            
            $this->db->execute($sql, $bindings);

            if ($localTransaction) $this->db->commit(); // Фиксируем транзакцию, если она была начата здесь
            
            // Удаляем старое значение из кэша
            if (CacheManager::isEnabled()) {
                CacheManager::delete($this->getCacheKeyForId($table, $id));
            }

        } catch (\Throwable $e) { // Ловим любые исключения (PDO, RuntimeException от JSON и т.д.)
            if ($localTransaction && $this->db->inTransaction()) { // Если транзакция была начата здесь и все еще активна
                 $this->db->rollBack();
            }
            // Логируем, только если это не JsonException, которую мы уже обернули и залогировали
            if (!($e instanceof \RuntimeException && $e->getPrevious() instanceof \JsonException)) {
                 LogManager::getLogger()->error("Ошибка в setParam для таблицы {$table}, ID {$id}", ['fullKey' => $fullKey, 'exception_class' => get_class($e), 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
            throw $e; // Перебрасываем исключение
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsetParam(string $table, string $fullKey): void
    {
        $paramParts = explode(".", $fullKey);
        $id = array_shift($paramParts);

        if (empty($id)) return; // Нечего делать, если ID пуст

        $localTransaction = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $localTransaction = true;
        }
        
        try {
            if (empty($paramParts)) { // Если путь не указан, удаляем всю запись
                $this->db->execute("DELETE FROM {$table} WHERE id = :id", [':id' => $id]);
                LogManager::getLogger()->info("Удалена запись из таблицы {$table} с ID {$id} (через unsetParam без пути).");
            } else { // Иначе, удаляем вложенный элемент
                $jsonString = $this->db->fetchValue("SELECT params FROM {$table} WHERE id = :id", [':id' => $id]);

                if ($jsonString === null || $jsonString === false) { // Запись не найдена
                    if ($localTransaction) $this->db->commit(); // Коммитим, так как операции не было, но транзакция могла быть начата
                    return;
                }
                
                $phpArray = [];
                try {
                    $phpArray = json_decode((string)$jsonString, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    if ($localTransaction) $this->db->rollBack();
                    LogManager::getLogger()->error("unsetParam: Ошибка декодирования JSON для таблицы {$table}, ID {$id}", ['json_error' => $e->getMessage()]);
                    throw new \RuntimeException("Ошибка декодирования JSON для удаления (таблица {$table}, ID {$id}): " . $e->getMessage(), $e->getCode(), $e);
                }

                $temp = &$phpArray;
                $keyToRemove = end($paramParts); // Последний элемент пути - ключ для удаления
                $parentOfKey = &$temp; // Ссылка на массив, содержащий ключ для удаления
                $pathValid = true;

                // Идем по пути до родительского элемента ключа, который нужно удалить
                for ($i = 0; $i < count($paramParts) - 1; $i++) {
                    $part = $paramParts[$i];
                    if (!is_array($parentOfKey) || !array_key_exists($part, $parentOfKey)) {
                        $pathValid = false; // Путь недействителен
                        break;
                    }
                    $parentOfKey = &$parentOfKey[$part];
                }
                
                // Если путь валиден и ключ существует в родительском массиве
                if ($pathValid && is_array($parentOfKey) && array_key_exists($keyToRemove, $parentOfKey)) {
                    unset($parentOfKey[$keyToRemove]); // Удаляем элемент
                    try {
                        $newJson = json_encode($phpArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                    } catch (\JsonException $e) {
                        if ($localTransaction) $this->db->rollBack();
                        LogManager::getLogger()->error("unsetParam: Ошибка кодирования JSON после удаления для таблицы {$table}, ID {$id}", ['json_error' => $e->getMessage()]);
                        throw new \RuntimeException("Ошибка кодирования JSON после удаления (таблица {$table}, ID {$id}): " . $e->getMessage(), $e->getCode(), $e);
                    }
                    $this->db->execute("UPDATE {$table} SET params = :params WHERE id = :id", [':params' => $newJson, ':id' => $id]);
                }
                unset($temp, $parentOfKey); // Удаляем ссылки
            }
            if ($localTransaction) $this->db->commit();
            
            // Очищаем кэш для этой записи
            if (CacheManager::isEnabled()) {
                CacheManager::delete($this->getCacheKeyForId($table, $id));
            }
        } catch (\Throwable $e) {
             if ($localTransaction && $this->db->inTransaction()) {
                 $this->db->rollBack();
            }
            if (!($e instanceof \RuntimeException && $e->getPrevious() instanceof \JsonException)) {
                LogManager::getLogger()->error("Ошибка в unsetParam для таблицы {$table}, ID {$id}", ['fullKey' => $fullKey, 'exception_class' => get_class($e), 'message' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function unsetParams(string $table, array|string $keys): void
    {
        $actualKeys = [];
        // Нормализуем входные ключи до плоского массива уникальных ключей
        if (is_array($keys)) {
            foreach ($keys as $keyStr) {
                if (!is_scalar($keyStr) && !$keyStr instanceof \Stringable) { // Пропускаем нестроковые ключи
                    LogManager::getLogger()->warning("Нестроковый ключ обнаружен в массиве для unsetParams (таблица {$table})", ['key_type' => gettype($keyStr)]);
                    continue;
                }
                $explodedKeys = explode(",", (string)$keyStr); // Строка может содержать несколько ключей через запятую
                foreach ($explodedKeys as $singleKey) {
                    $trimmedKey = trim($singleKey);
                    if (!empty($trimmedKey)) {
                        $actualKeys[] = $trimmedKey;
                    }
                }
            }
        } else { // Если $keys - это строка
            $explodedKeys = explode(",", (string)$keys);
            foreach ($explodedKeys as $singleKey) {
                $trimmedKey = trim($singleKey);
                if (!empty($trimmedKey)) {
                    $actualKeys[] = $trimmedKey;
                }
            }
        }
        $actualKeys = array_unique($actualKeys); // Удаляем дубликаты

        if (empty($actualKeys)) {
            return; // Нечего удалять
        }

        // Оборачиваем все операции в одну транзакцию, если она еще не начата
        $localTransactionOuter = false;
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $localTransactionOuter = true;
        }

        try {
            foreach ($actualKeys as $key) {
                $this->unsetParam($table, $key); // Вызываем индивидуальный unsetParam для каждого ключа
            }
            if ($localTransactionOuter) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($localTransactionOuter && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Ошибки из unsetParam уже должны быть залогированы.
            // Логируем здесь, если ошибка произошла на уровне этого метода (например, при commit/rollback).
             if (!($e instanceof \RuntimeException && $e->getPrevious() instanceof \JsonException) &&
                !($e instanceof \PDOException)) { // PDOException уже логируется в DB
                LogManager::getLogger()->error("Ошибка во время группового удаления (unsetParams) для таблицы {$table}", ['keys_attempted' => $actualKeys, 'exception_class' => get_class($e), 'message' => $e->getMessage()]);
            }
            throw $e;
        }
    }
}

/**
 * Абстрактный базовый класс для моделей (Order, Product),
 * которые работают с параметрами через ParamsManagerInterface.
 */
abstract class SimpleParamsModel
{
    /** Удаляет все параметры для таблицы, связанной с моделью. */
    abstract public function removeParams(): void;
    /**
     * Получает параметр по ключу.
     * @param string $key Ключ в формате "id.path.to.value" или "id".
     * @return mixed Значение параметра или null.
     */
    abstract public function getParam(string $key): mixed;
    /**
     * Устанавливает параметр.
     * @param string $key Ключ в формате "id.path.to.value" или "id".
     * @param mixed $value Значение.
     */
    abstract public function setParam(string $key, mixed $value): void;
    /**
     * Удаляет параметр или запись.
     * @param string $key Ключ в формате "id.path.to.value" или "id".
     */
    abstract public function unsetParam(string $key): void;
    /**
     * Удаляет несколько параметров или записей.
     * @param array|string $keys Массив ключей или строка ключей через запятую.
     */
    abstract public function unsetParams(array|string $keys): void;
}

// Глобальные переменные для использования в тестах для упрощения инъекции зависимостей.
// В реальном приложении следует использовать DI-контейнер.
/** @var ?DB Экземпляр DB, используемый тестами. */
$globalAppDbForTzTest = null;
/** @var ?ParamsManagerInterface Экземпляр менеджера параметров, используемый тестами. */
$globalAppParamsManagerForTzTest = null;
/** @var string Путь к файлу БД по умолчанию. Может быть переопределен в тестах. */
$defaultDbFileForTzCompatibility = __DIR__ . '/data.db';

/**
 * Модель Заказа.
 * Предоставляет методы для работы с параметрами заказов.
 */
class Order extends SimpleParamsModel
{
    /** @var string Имя таблицы в БД для заказов. */
    public const TABLE_NAME = "Orders";
    /** @var ParamsManagerInterface Менеджер параметров. Только для чтения после инициализации. */
    private readonly ParamsManagerInterface $paramsManager; // PHP 8.1+
    /** @var DB Экземпляр для работы с БД. Только для чтения после инициализации. */
    private readonly DB $dbInstance; // PHP 8.1+

    public function __construct()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest, $defaultDbFileForTzCompatibility;
        
        $dbToUse = $globalAppDbForTzTest;
        $pmToUse = $globalAppParamsManagerForTzTest;
        $logManagerExists = class_exists(LogManager::class);

        // Если глобальный экземпляр DB не предоставлен (например, при прямом инстанцировании вне тестов),
        // создаем новый экземпляр DB с путем по умолчанию.
        if ($dbToUse === null) {
            // Получаем путь к файлу БД из глобальной переменной (устанавливаемой тестами) или из дефолтного значения.
            $currentDefaultDbFile = $GLOBALS['defaultDbFileForTzCompatibility'] ?? $defaultDbFileForTzCompatibility;
            $dsn = 'sqlite:' . $currentDefaultDbFile;
            if ($logManagerExists) LogManager::getLogger()->info("Order constructor: Глобальный DB не найден, создается новый DB для '{$currentDefaultDbFile}'");
            $dbToUse = new DB($dsn);
        }
        $this->dbInstance = $dbToUse;

        // Аналогично для ParamsManager
        if ($pmToUse === null) {
            if ($logManagerExists) LogManager::getLogger()->info("Order constructor: Глобальный ParamsManager не найден, создается новый PdoParamsManager");
            $pmToUse = new PdoParamsManager($this->dbInstance);
        }
        
        if ($pmToUse === null) { // Дополнительная проверка на всякий случай
             throw new \RuntimeException("ParamsManager не был инициализирован для Order.");
        }
        $this->paramsManager = $pmToUse;
        
        // Инициализация схемы таблицы при первом создании объекта Order для данного экземпляра DB
        if (!$this->dbInstance->isTableInitialized(self::TABLE_NAME)) {
            $mainTableCommand = "CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (id TEXT PRIMARY KEY, created_at TEXT DEFAULT CURRENT_TIMESTAMP, params TEXT)";
            if ($this->dbInstance->executeSchemaStatement($mainTableCommand)) {
                // Создаем индекс только если основная таблица успешно создана
                $indexCommand = "CREATE INDEX IF NOT EXISTS idx_orders_created_at ON " . self::TABLE_NAME . "(created_at)";
                if (!$this->dbInstance->executeSchemaStatement($indexCommand)) {
                    if ($logManagerExists) LogManager::getLogger()->warning("Не удалось создать индекс idx_orders_created_at для таблицы " . self::TABLE_NAME);
                }
                $this->dbInstance->markTableAsInitialized(self::TABLE_NAME); // Помечаем таблицу как инициализированную
            } else {
                 // Критическая ошибка, если не удалось создать основную таблицу
                 if ($logManagerExists) LogManager::getLogger()->error("КРИТИЧЕСКАЯ ОШИБКА: Не удалось создать основную таблицу " . self::TABLE_NAME);
            }
        }
    }

    /** {@inheritdoc} */
    public function removeParams(): void { $this->paramsManager->removeAllParamsFromTable(self::TABLE_NAME); }
    /** {@inheritdoc} */
    public function getParam(string $key): mixed { return $this->paramsManager->getParam(self::TABLE_NAME, $key); }
    /** {@inheritdoc} */
    public function setParam(string $key, $value): void { $this->paramsManager->setParam(self::TABLE_NAME, $key, $value); }
    /** {@inheritdoc} */
    public function unsetParam(string $key): void { $this->paramsManager->unsetParam(self::TABLE_NAME, $key); }
    /** {@inheritdoc} */
    public function unsetParams(array|string $keys): void { $this->paramsManager->unsetParams(self::TABLE_NAME, $keys); }
}

/**
 * Модель Продукта.
 * Предоставляет методы для работы с параметрами продуктов.
 */
class Product extends SimpleParamsModel
{
    /** @var string Имя таблицы в БД для продуктов. */
    public const TABLE_NAME = "Products";
    /** @var ParamsManagerInterface Менеджер параметров. Только для чтения после инициализации. */
    private readonly ParamsManagerInterface $paramsManager; // PHP 8.1+
    /** @var DB Экземпляр для работы с БД. Только для чтения после инициализации. */
    private readonly DB $dbInstance; // PHP 8.1+

    public function __construct()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest, $defaultDbFileForTzCompatibility;

        $dbToUse = $globalAppDbForTzTest;
        $pmToUse = $globalAppParamsManagerForTzTest;
        $logManagerExists = class_exists(LogManager::class);

        if ($dbToUse === null) {
            $currentDefaultDbFile = $GLOBALS['defaultDbFileForTzCompatibility'] ?? $defaultDbFileForTzCompatibility;
            $dsn = 'sqlite:' . $currentDefaultDbFile;
            if ($logManagerExists) LogManager::getLogger()->info("Product constructor: Глобальный DB не найден, создается новый DB для '{$currentDefaultDbFile}'");
            $dbToUse = new DB($dsn);
        }
        $this->dbInstance = $dbToUse;

        if ($pmToUse === null) {
            if ($logManagerExists) LogManager::getLogger()->info("Product constructor: Глобальный ParamsManager не найден, создается новый PdoParamsManager");
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
                         if ($logManagerExists) LogManager::getLogger()->warning("Не удалось создать индекс для таблицы " . self::TABLE_NAME, ['command' => $command]);
                    }
                }
                $this->dbInstance->markTableAsInitialized(self::TABLE_NAME);
            } else {
                if ($logManagerExists) LogManager::getLogger()->error("КРИТИЧЕСКАЯ ОШИБКА: Не удалось создать основную таблицу " . self::TABLE_NAME);
            }
        }
    }

    /** {@inheritdoc} */
    public function removeParams(): void { $this->paramsManager->removeAllParamsFromTable(self::TABLE_NAME); }
    /** {@inheritdoc} */
    public function getParam(string $key): mixed { return $this->paramsManager->getParam(self::TABLE_NAME, $key); }
    /** {@inheritdoc} */
    public function setParam(string $key, $value): void { $this->paramsManager->setParam(self::TABLE_NAME, $key, $value); }
    /** {@inheritdoc} */
    public function unsetParam(string $key): void { $this->paramsManager->unsetParam(self::TABLE_NAME, $key); }
    /** {@inheritdoc} */
    public function unsetParams(array|string $keys): void { $this->paramsManager->unsetParams(self::TABLE_NAME, $keys); }
}

?>
