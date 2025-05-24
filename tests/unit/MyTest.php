<?php
declare(strict_types=1);

// 1. Подключаем АВТОЗАГРУЗЧИК COMPOSER
// Предполагается, что папка vendor находится на два уровня выше (в корне проекта).
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../vendor/autoload.php')) { // Альтернативный путь, если тесты глубже
    require_once __DIR__ . '/../../../vendor/autoload.php';
}


// 2. Подключаем ФАЙЛ С ОПРЕДЕЛЕНИЯМИ ОСНОВНЫХ КЛАССОВ МОДУЛЯ
// Предполагается, что ParamsManagerModuleV3.php находится в корне проекта.
if (file_exists(__DIR__ . '/../../ParamsManagerModuleV3.php')) {
    require_once __DIR__ . '/../../ParamsManagerModuleV3.php';
} elseif (file_exists(__DIR__ . '/../../../ParamsManagerModuleV3.php')) { // Альтернативный путь
    require_once __DIR__ . '/../../../ParamsManagerModuleV3.php';
}

/**
 * Класс ParamsTest содержит юнит-тесты для проверки функциональности
 * управления параметрами моделей Order и Product.
 * Наследуется от \Codeception\Test\Unit для использования возможностей Codeception/PHPUnit.
 */
class ParamsTest extends \Codeception\Test\Unit
{
    /**
     * Метод, выполняемый перед каждым тестом (_before hook).
     * Инициализирует окружение для теста:
     * - Определяет путь к файлу БД.
     * - Удаляет старые файлы БД (data.db, data.db-wal, data.db-shm) для чистоты теста.
     * - Инициализирует LogManager и CacheManager (с очисткой кэша).
     * - Создает и устанавливает глобальные экземпляры DB и PdoParamsManager.
     * - Инстанцирует Order и Product для создания их таблиц в БД.
     * - Очищает таблицы Order и Product от возможных данных.
     */
    protected function _before()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest, $defaultDbFileForTzCompatibility;

        // Путь к файлу БД относительно директории, где лежит MyTest.php (tests/unit/)
        // ../../data.db указывает на файл data.db в корне проекта.
        $defaultDbFileForTzCompatibility = __DIR__ . '/../../data.db'; 
        $GLOBALS['defaultDbFileForTzCompatibility'] = $defaultDbFileForTzCompatibility; // Устанавливаем глобально для доступа из конструкторов моделей

        // Очистка предыдущих файлов БД для обеспечения изоляции тестов
        $filesToUnlink = [
            $defaultDbFileForTzCompatibility,
            $defaultDbFileForTzCompatibility . '-wal', // Файл Write-Ahead Log для SQLite
            $defaultDbFileForTzCompatibility . '-shm'  // Файл Shared Memory для SQLite
        ];
        foreach ($filesToUnlink as $file) {
            if (file_exists($file)) {
                @unlink($file); // Подавляем ошибку, если файл заблокирован (хотя _after должен это решать)
            }
        }

        // Инициализация менеджеров
        if (class_exists(LogManager::class)) {
             LogManager::getLogger(); // Убедимся, что логгер инициализирован и глобальные обработчики установлены
        }
        if (class_exists(CacheManager::class)) {
            // Для тестов лучше всегда инициализировать с известным состоянием (APCu или null)
            CacheManager::initialize(class_exists(ApcuSimpleCache::class) ? new ApcuSimpleCache() : null);
            if (CacheManager::isEnabled()) {
                CacheManager::clear(); // Очищаем кэш перед каждым тестом
            }
        }

        // Инициализация основного соединения с БД и менеджера параметров для этого теста
        $dsn = 'sqlite:' . $defaultDbFileForTzCompatibility;
        $globalAppDbForTzTest = new DB($dsn); 
        $globalAppParamsManagerForTzTest = new PdoParamsManager($globalAppDbForTzTest);

        // Принудительное создание экземпляров моделей для выполнения DDL (создания таблиц и индексов)
        // Конструкторы Order и Product содержат логику инициализации своих таблиц.
        new Order(); 
        new Product();
        
        // Очистка данных из таблиц после их возможного создания (на случай, если DDL не выполнился ранее)
        $orderForCleanup = new Order();
        if ($globalAppDbForTzTest->isTableInitialized(Order::TABLE_NAME)) {
            $orderForCleanup->removeParams();
        }
        
        $productForCleanup = new Product();
        if ($globalAppDbForTzTest->isTableInitialized(Product::TABLE_NAME)) {
            $productForCleanup->removeParams();
        }
    }
    
    /**
     * Метод, выполняемый после каждого теста (_after hook).
     * Очищает ресурсы, использованные в тесте:
     * - Закрывает соединение с БД.
     * - Обнуляет глобальные экземпляры DB и ParamsManager.
     * - Опционально (закомментировано): удаляет файлы БД.
     */
    protected function _after()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest;
        
        // Явно закрываем соединение с БД, чтобы освободить файл (особенно важно для SQLite на Windows)
        if ($globalAppDbForTzTest instanceof DB) {
            $globalAppDbForTzTest->close();
        }
        // Обнуляем глобальные переменные, чтобы избежать их влияния на последующие тесты (хотя _before должен их переинициализировать)
        $globalAppDbForTzTest = null; 
        $globalAppParamsManagerForTzTest = null;
        
        // Опциональное удаление файлов БД после теста.
        // Закомментировано, чтобы файл data.db оставался для анализа после выполнения тестов.
        /*
        $dbFileToClean = $GLOBALS['defaultDbFileForTzCompatibility'] ?? null;
        if ($dbFileToClean) {
            $filesToUnlink = [
                $dbFileToClean,
                $dbFileToClean . '-wal',
                $dbFileToClean . '-shm'
            ];
            foreach ($filesToUnlink as $file) {
                if (file_exists($file)) {
                    @unlink($file); 
                }
            }
        }
        */
         unset($GLOBALS['defaultDbFileForTzCompatibility']); // Удаляем глобальную переменную пути к БД
    }

    /**
     * Тестирует функциональность управления параметрами для модели Order.
     * @throws \Exception Если возникают ошибки при выполнении теста.
     */
    public function testOrderParams(): void // Добавлен void return type (PHP 7.1+)
    {
        $order = new Order(); // Модель будет использовать глобальные DB и ParamsManager, настроенные в _before()
        $this->processParamsTest($order);
    }

    /**
     * Тестирует функциональность управления параметрами для модели Product.
     * @throws \Exception Если возникают ошибки при выполнении теста.
     */
    public function testProductParams(): void // Добавлен void return type
    {
        $product = new Product(); // Модель будет использовать глобальные DB и ParamsManager
        $this->processParamsTest($product);
    }

    /**
     * Общий метод для тестирования операций с параметрами для заданной модели.
     * Выполняет последовательность операций set, get, unset, remove и проверяет результаты.
     *
     * @param SimpleParamsModel $model Экземпляр модели (Order или Product).
     * @throws \Exception Если возникают ошибки при выполнении ассертов или операций.
     */
    protected function processParamsTest(SimpleParamsModel $model): void // Добавлен void return type
    {
        $modelNameForMessages = ($model instanceof Order) ? "Order" : "Product";
        // Вывод отладочной информации о начале теста для конкретной модели
        if (class_exists(\Codeception\Util\Debug::class)) { // Используем Codeception Debug, если доступен
            \Codeception\Util\Debug::debug("\n--- Тестирование {$modelNameForMessages} с PdoParamsManager (Кэширование APCu: ".(CacheManager::isEnabled() ? "Включено" : "Отключено/Недоступно").") ---");
        } else { // Резервный вывод через echo
            echo "\n--- Тестирование {$modelNameForMessages} с PdoParamsManager (Кэширование APCu: ".(CacheManager::isEnabled() ? "Включено" : "Отключено/Недоступно").") ---\n";
        }
        
        // Начальная очистка таблицы для данной модели, чтобы тест начинался с чистого состояния
        // (дублирует очистку в _before, но гарантирует чистоту именно для этой модели перед началом операций)
        $currentTableName = ($model instanceof Order) ? Order::TABLE_NAME : Product::TABLE_NAME;
        if (isset($GLOBALS['globalAppDbForTzTest']) && $GLOBALS['globalAppDbForTzTest']->isTableInitialized($currentTableName)) {
             $model->removeParams();
        }

        // 1. Тест простой установки и получения параметра (один уровень вложенности)
        $model->setParam('simple_id.value', 1);
        $this->assertEquals(1, $model->getParam('simple_id.value'), "{$modelNameForMessages}: Простое значение ключа 'simple_id.value'");
        $this->assertEquals(['value' => 1], $model->getParam('simple_id'), "{$modelNameForMessages}: Получение всего объекта параметров для 'simple_id'");
        // Повторное получение для проверки возможного влияния кэша (если он активен)
        $this->assertEquals(1, $model->getParam('simple_id.value'), "{$modelNameForMessages}: Простое значение 'simple_id.value' (проверка кэша)");

        // 2. Тест установки/получения массива и вложенной установки новых элементов в массив
        $model->setParam('array_id.data', ['one' => 1, 'two' => 2]);
        $model->setParam('array_id.data.three', 3);
        $model->setParam('array_id.data.five', 5);
        
        $expectedArrayData = ['one' => 1, 'two' => 2, 'three' => 3, 'five' => 5];
        $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "{$modelNameForMessages}: Полный массив 'array_id.data' после добавлений");
        $this->assertEquals(2, $model->getParam('array_id.data.two'), "{$modelNameForMessages}: Значение ключа 'two' из массива 'array_id.data'");
        
        $arrayIdData = $model->getParam('array_id.data');
        $this->assertIsArray($arrayIdData, "{$modelNameForMessages}: 'array_id.data' должен быть массивом");
        if(is_array($arrayIdData)) { // Дополнительная проверка для статических анализаторов
            $this->assertArrayHasKey('one', $arrayIdData, "{$modelNameForMessages}: Массив 'array_id.data' содержит ключ 'one'");
        }
        // Проверка, что assertArraySubset (если доступен) или assertEquals корректно сравнивают массивы
        if (method_exists($this, 'assertArraySubset')) { // Для старых версий PHPUnit, используемых Codeception 3.x
             $this->assertArraySubset($expectedArrayData, $model->getParam('array_id.data'), false, "{$modelNameForMessages}: 'array_id.data' содержит ожидаемые данные (subset)");
        } else { // Для более новых версий PHPUnit
            $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "{$modelNameForMessages}: 'array_id.data' содержит ожидаемые данные (equals)");
        }
        $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "{$modelNameForMessages}: Полный массив 'array_id.data' (проверка кэша)");

        // 3. Тест удаления вложенного ключа из массива
        $model->unsetParam('array_id.data.three');
        $this->assertNull($model->getParam('array_id.data.three'), "{$modelNameForMessages}: 'array_id.data.three' должен быть null после удаления");
        $expectedAfterUnsetThree = ['one' => 1, 'two' => 2, 'five' => 5];
        $this->assertEquals($expectedAfterUnsetThree, $model->getParam('array_id.data'), "{$modelNameForMessages}: Массив 'array_id.data' после удаления ключа 'three'");

        // 4. Тест попытки удаления несуществующего глубоко вложенного ключа (не должно вызывать ошибок)
        $model->unsetParam('array_id.data.nonexistent.key'); 
        $this->assertNull($model->getParam('array_id.data.nonexistent'), "{$modelNameForMessages}: 'array_id.data.nonexistent' должен быть null (родительский ключ не существует)");
        $this->assertEquals($expectedAfterUnsetThree, $model->getParam('array_id.data'), "{$modelNameForMessages}: Массив 'array_id.data' не должен измениться после попытки удаления несуществующего ключа");

        // 5. Тест группового удаления (unsetParams) записей и вложенных ключей
        $model->setParam('a.value', 100); // Запись 'a' с параметром
        $model->setParam('b.value', 200); // Запись 'b' с параметром
        
        // Удаляем записи 'a', 'b', вложенный ключ 'array_id.data.five' и несуществующую запись 'non_existent_record'
        $model->unsetParams(['a','b','array_id.data.five', 'non_existent_record']);
        $this->assertNull($model->getParam('a'), "{$modelNameForMessages}: Запись 'a' должна быть null после unsetParams");
        $this->assertNull($model->getParam('b'), "{$modelNameForMessages}: Запись 'b' должна быть null после unsetParams");
        $this->assertNull($model->getParam('array_id.data.five'), "{$modelNameForMessages}: 'array_id.data.five' должен быть null после unsetParams");
        $expectedAfterUnsetFive = ['one' => 1, 'two' => 2];
        $this->assertEquals($expectedAfterUnsetFive, $model->getParam('array_id.data'), "{$modelNameForMessages}: 'array_id.data' после удаления 'five' через unsetParams");
        $this->assertNull($model->getParam('non_existent_record'), "{$modelNameForMessages}: Несуществующая запись 'non_existent_record' должна оставаться null после unsetParams");

        // 6. Тест группового удаления (unsetParams) более сложных путей, включая сохранение части структуры
        $model->setParam('c.value', 300);
        $model->setParam('d.another.key', 400);
        $model->setParam('d.another.level2.val', 500);

        $model->unsetParams(['c', 'd.another.key']); // Удаляем запись 'c' и ключ 'key' из 'd.another'
        $this->assertNull($model->getParam('c'), "{$modelNameForMessages}: Запись 'c' должна быть null после unsetParams (удаление по ID)");
        $this->assertNull($model->getParam('d.another.key'), "{$modelNameForMessages}: 'd.another.key' должен быть null после unsetParams (удаление вложенного ключа)");
        
        $dAnotherParams = $model->getParam('d.another'); // 'd.another' все еще должен существовать
        $this->assertIsArray($dAnotherParams, "{$modelNameForMessages}: 'd.another' все еще должен быть массивом");
        if(is_array($dAnotherParams)) { 
            $this->assertEquals(['level2' => ['val' => 500]], $dAnotherParams, "{$modelNameForMessages}: Содержимое 'd.another' после удаления 'key' (должен остаться 'level2')");
        }
        
        // 7. Тест установки параметра для нового ID (проверка логики UPSERT - случай INSERT)
        $model->setParam('new_id.name', 'Тестер');
        $this->assertEquals('Тестер', $model->getParam('new_id.name'), "{$modelNameForMessages}: Установка параметра для нового ID 'new_id'");
        $this->assertEquals(['name' => 'Тестер'], $model->getParam('new_id'), "{$modelNameForMessages}: Получение всего объекта параметров для 'new_id'");

        // 8. Тест перезаписи всего объекта параметров для существующего ID (проверка UPSERT - случай UPDATE)
        //    Также проверяется специальная обработка поля 'title' для модели Product.
        $model->setParam('overwrite_id', ['initial' => 'data']); // Создаем начальную запись
        $this->assertEquals(['initial' => 'data'], $model->getParam('overwrite_id'), "{$modelNameForMessages}: Исходные параметры для 'overwrite_id'");
        
        $baseOverwriteValue = ['new_data' => true, 'value' => 42];
        $expectedParamsContent = $baseOverwriteValue; // Ожидаемое содержимое поля 'params' по умолчанию

        if ($model instanceof Product) {
             $valueToSetForProduct = $baseOverwriteValue; 
             $valueToSetForProduct['title'] = 'Product Title Overwritten by Full Set'; // Добавляем title для Product
             $model->setParam('overwrite_id', $valueToSetForProduct); // Устанавливаем весь объект, включая title
             // Для Product, поле 'params' будет содержать 'title', если он был в $valueToSetForProduct
             $expectedParamsContent = $valueToSetForProduct;
        } else { // Для Order
            $model->setParam('overwrite_id', $baseOverwriteValue);
            // $expectedParamsContent остается $baseOverwriteValue
        }
        
        // Проверяем, что getParam для ID возвращает именно то, что было сохранено в поле 'params'
        $this->assertEquals($expectedParamsContent, $model->getParam('overwrite_id'), "{$modelNameForMessages}: Перезаписанные параметры для 'overwrite_id' (содержимое JSON поля 'params')");
        
        // Дополнительная проверка для Product: убеждаемся, что поле 'title' в отдельной колонке также обновилось
        if ($model instanceof Product) {
            global $globalAppDbForTzTest; // Используем глобальный экземпляр DB из _before()
            if ($globalAppDbForTzTest instanceof DB) {
                $productTitleFromDb = $globalAppDbForTzTest->fetchValue("SELECT title FROM ".Product::TABLE_NAME." WHERE id = :id", [':id' => 'overwrite_id']);
                $this->assertEquals('Product Title Overwritten by Full Set', $productTitleFromDb, "{$modelNameForMessages}: Поле 'title' продукта в БД должно обновиться");
            } else {
                $this->fail("Экземпляр DB недоступен для проверки поля 'title' продукта.");
            }
        }

        // 9. Тест установки параметра для создания новой вложенной структуры у существующего ID
        $model->setParam('new_id.profile.age', 30); // Добавляем 'profile.age' к 'new_id'
        $expectedNewIdParams = ['name' => 'Тестер', 'profile' => ['age' => 30]];
        $this->assertEquals($expectedNewIdParams, $model->getParam('new_id'), "{$modelNameForMessages}: Полные параметры для 'new_id' после добавления вложенной структуры 'profile'");

        // 10. Тест удаления всей записи через unsetParam, передав только ID (без пути к ключу)
        $model->setParam('to_delete_record.data', 'некие данные для удаления');
        $this->assertNotNull($model->getParam('to_delete_record'), "{$modelNameForMessages}: Запись 'to_delete_record' существует перед удалением");
        $model->unsetParam('to_delete_record'); // Удаляем всю запись по ID
        $this->assertNull($model->getParam('to_delete_record'), "{$modelNameForMessages}: Запись 'to_delete_record' должна быть null после удаления по ID");

        // 11. Тест полной очистки всех параметров для таблицы данной модели
        // Закомментировано, чтобы данные оставались в БД после тестов для анализа.
        // Раскомментируйте, если нужна проверка очистки в конце каждого processParamsTest.
        /*
        $model->setParam('temp1.val', 1);
        $model->setParam('temp2.val', 2);
        $model->removeParams(); 
        $this->assertNull($model->getParam('temp1'), "{$modelNameForMessages}: 'temp1' должен быть null после removeParams");
        $this->assertNull($model->getParam('temp2'), "{$modelNameForMessages}: 'temp2' должен быть null после removeParams");
        */

        // Финальное отладочное сообщение
        if (class_exists(\Codeception\Util\Debug::class)) {
            \Codeception\Util\Debug::debug("--- Тестирование {$modelNameForMessages} завершено ---");
        } else {
            echo "--- Тестирование {$modelNameForMessages} завершено ---\n";
        }
    }
}
?>
