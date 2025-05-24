<?php
declare(strict_types=1);

// 1. Подключаем АВТОЗАГРУЗЧИК COMPOSER из КОРНЯ ПРОЕКТА CODECEPTION
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../vendor/autoload.php')) { // Если тесты в tests/unit/
    require_once __DIR__ . '/../../../vendor/autoload.php';
}


// 2. Подключаем ФАЙЛ С ОПРЕДЕЛЕНИЯМИ НАШИХ КЛАССОВ
if (file_exists(__DIR__ . '/../../ParamsManagerModuleV3.php')) {
    require_once __DIR__ . '/../../ParamsManagerModuleV3.php';
} elseif (file_exists(__DIR__ . '/../../../ParamsManagerModuleV3.php')) {
    require_once __DIR__ . '/../../../ParamsManagerModuleV3.php';
}


class ParamsTest extends \Codeception\Test\Unit
{
    protected function _before()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest, $defaultDbFileForTzCompatibility;

        $defaultDbFileForTzCompatibility = __DIR__ . '/../../data.db'; 
        $GLOBALS['defaultDbFileForTzCompatibility'] = $defaultDbFileForTzCompatibility;

        $filesToUnlink = [
            $defaultDbFileForTzCompatibility,
            $defaultDbFileForTzCompatibility . '-wal',
            $defaultDbFileForTzCompatibility . '-shm'
        ];
        foreach ($filesToUnlink as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        if (class_exists(LogManager::class)) {
             LogManager::getLogger(); 
        }
        if (class_exists(CacheManager::class)) {
            CacheManager::initialize(class_exists(ApcuSimpleCache::class) ? new ApcuSimpleCache() : null);
            if (CacheManager::isEnabled()) {
                CacheManager::clear(); 
            }
        }

        $dsn = 'sqlite:' . $defaultDbFileForTzCompatibility;
        $globalAppDbForTzTest = new DB($dsn); 
        $globalAppParamsManagerForTzTest = new PdoParamsManager($globalAppDbForTzTest);

        new Order(); 
        new Product();
        
        $orderForCleanup = new Order();
        if ($globalAppDbForTzTest->isTableInitialized(Order::TABLE_NAME)) {
            $orderForCleanup->removeParams();
        }
        
        $productForCleanup = new Product();
        if ($globalAppDbForTzTest->isTableInitialized(Product::TABLE_NAME)) {
            $productForCleanup->removeParams();
        }
    }
    
    protected function _after()
    {
        global $globalAppDbForTzTest, $globalAppParamsManagerForTzTest, $defaultDbFileForTzCompatibility;
        
        if ($globalAppDbForTzTest instanceof DB) {
            $globalAppDbForTzTest->close();
        }
        $globalAppDbForTzTest = null; 
        $globalAppParamsManagerForTzTest = null;
        
        // Закомментируем удаление файлов БД, чтобы data.db оставался
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
         unset($GLOBALS['defaultDbFileForTzCompatibility']);
    }

    /**
     * @throws Exception
     */
    public function testOrderParams()
    {
        $order = new Order(); 
        $this->processParamsTest($order);
    }

    /**
     * @throws Exception
     */
    public function testProductParams()
    {
        $product = new Product(); 
        $this->processParamsTest($product);
    }

    /**
     * @param SimpleParamsModel $model (Order или Product)
     * @throws Exception
     */
    protected function processParamsTest(SimpleParamsModel $model): void
    {
        $modelNameForMessages = ($model instanceof Order) ? "Order" : "Product";
        if (class_exists(\Codeception\Util\Debug::class)) {
            \Codeception\Util\Debug::debug("\n--- Тестирование {$modelNameForMessages} с PdoParamsManager (Кэширование APCu: ".(CacheManager::isEnabled() ? "Включено" : "Отключено/Недоступно").") ---");
        } else {
            echo "\n--- Тестирование {$modelNameForMessages} с PdoParamsManager (Кэширование APCu: ".(CacheManager::isEnabled() ? "Включено" : "Отключено/Недоступно").") ---\n";
        }
        
        if ($model instanceof Order && $GLOBALS['globalAppDbForTzTest']->isTableInitialized(Order::TABLE_NAME)) {
             $model->removeParams();
        } elseif ($model instanceof Product && $GLOBALS['globalAppDbForTzTest']->isTableInitialized(Product::TABLE_NAME)) {
             $model->removeParams();
        }

        // 1. Простая установка/получение
        $model->setParam('simple_id.value', 1);
        $this->assertEquals(1, $model->getParam('simple_id.value'), "{$modelNameForMessages}: Простое значение ключа");
        $this->assertEquals(['value' => 1], $model->getParam('simple_id'), "{$modelNameForMessages}: Получение полного объекта параметров для simple_id");
        $this->assertEquals(1, $model->getParam('simple_id.value'), "{$modelNameForMessages}: Простое значение ключа (проверка кэша)");

        // 2. Установка/получение массива, вложенная установка
        $model->setParam('array_id.data', ['one' => 1, 'two' => 2]);
        $model->setParam('array_id.data.three', 3);
        $model->setParam('array_id.data.five', 5);
        
        $expectedArrayData = ['one' => 1, 'two' => 2, 'three' => 3, 'five' => 5];
        $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "{$modelNameForMessages}: Полный массив данных после добавлений");
        $this->assertEquals(2, $model->getParam('array_id.data.two'), "{$modelNameForMessages}: Простое значение ключа 'two' из массива");
        
        $arrayIdData = $model->getParam('array_id.data');
        $this->assertIsArray($arrayIdData, "{$modelNameForMessages}: array_id.data должен быть массивом");
        if(is_array($arrayIdData)) { 
            $this->assertArrayHasKey('one', $arrayIdData, "{$modelNameForMessages}: Массив данных содержит ключ 'one'");
        }
        
        if (method_exists($this, 'assertArraySubset')) {
             $this->assertArraySubset($expectedArrayData, $model->getParam('array_id.data'), false, "{$modelNameForMessages}: Array Contains Data (subset)");
        } else {
            $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "{$modelNameForMessages}: Array Contains Data (equals)");
        }
        $this->assertEquals($expectedArrayData, $model->getParam('array_id.data'), "{$modelNameForMessages}: Полный массив данных (проверка кэша)");

        // 3. Удаление вложенного ключа
        $model->unsetParam('array_id.data.three');
        $this->assertNull($model->getParam('array_id.data.three'), "{$modelNameForMessages}: array.data.three должен быть null после удаления");
        $expectedAfterUnsetThree = ['one' => 1, 'two' => 2, 'five' => 5];
        $this->assertEquals($expectedAfterUnsetThree, $model->getParam('array_id.data'), "{$modelNameForMessages}: Полный массив данных после удаления 'three'");

        // 4. Попытка удаления несуществующего глубоко вложенного ключа
        $model->unsetParam('array_id.data.nonexistent.key'); 
        $this->assertNull($model->getParam('array_id.data.nonexistent'), "{$modelNameForMessages}: array.data.nonexistent должен быть null");
        $this->assertEquals($expectedAfterUnsetThree, $model->getParam('array_id.data'), "{$modelNameForMessages}: Полный массив данных после попытки удаления несуществующего ключа");

        // 5. Групповое удаление (unsetParams)
        $model->setParam('a.value', 100);
        $model->setParam('b.value', 200);
        
        $model->unsetParams(['a','b','array_id.data.five', 'non_existent_record']);
        $this->assertNull($model->getParam('a'), "{$modelNameForMessages}: Запись 'a' должна быть null после unsetParams");
        $this->assertNull($model->getParam('b'), "{$modelNameForMessages}: Запись 'b' должна быть null после unsetParams");
        $this->assertNull($model->getParam('array_id.data.five'), "{$modelNameForMessages}: 'array_id.data.five' должен быть null после unsetParams");
        $expectedAfterUnsetFive = ['one' => 1, 'two' => 2];
        $this->assertEquals($expectedAfterUnsetFive, $model->getParam('array_id.data'), "{$modelNameForMessages}: 'array_id.data' после удаления 'five'");
        $this->assertNull($model->getParam('non_existent_record'), "{$modelNameForMessages}: Проверка несуществующей записи после unsetParams");

        // 6. Групповое удаление (unsetParams) более сложных путей
        $model->setParam('c.value', 300);
        $model->setParam('d.another.key', 400);
        $model->setParam('d.another.level2.val', 500);

        $model->unsetParams(['c', 'd.another.key']);
        $this->assertNull($model->getParam('c'), "{$modelNameForMessages}: Запись 'c' должна быть null после unsetParams (массив)");
        $this->assertNull($model->getParam('d.another.key'), "{$modelNameForMessages}: 'd.another.key' должен быть null после unsetParams (массив)");
        
        $dAnotherParams = $model->getParam('d.another');
        $this->assertIsArray($dAnotherParams, "{$modelNameForMessages}: 'd.another' все еще должен быть массивом");
        if(is_array($dAnotherParams)) { 
            $this->assertEquals(['level2' => ['val' => 500]], $dAnotherParams, "{$modelNameForMessages}: Содержимое 'd.another' после удаления 'key'");
        }
        
        // 7. Установка параметра для нового ID (проверка UPSERT - случай INSERT)
        $model->setParam('new_id.name', 'Тестер');
        $this->assertEquals('Тестер', $model->getParam('new_id.name'), "{$modelNameForMessages}: Установка параметра для нового ID");
        $this->assertEquals(['name' => 'Тестер'], $model->getParam('new_id'), "{$modelNameForMessages}: Получение полного объекта параметров для new_id");

        // 8. Перезапись всего объекта параметров для существующего ID
        $model->setParam('overwrite_id', ['initial' => 'data']);
        $this->assertEquals(['initial' => 'data'], $model->getParam('overwrite_id'), "{$modelNameForMessages}: Исходные параметры для overwrite_id");
        
        $baseOverwriteValue = ['new_data' => true, 'value' => 42];
        $expectedParamsContent = $baseOverwriteValue; 

        if ($model instanceof Product) {
             $valueToSetForProduct = $baseOverwriteValue; 
             $valueToSetForProduct['title'] = 'Product Title Overwritten by Full Set';
             $model->setParam('overwrite_id', $valueToSetForProduct);
             $expectedParamsContent = $valueToSetForProduct;
        } else {
            $model->setParam('overwrite_id', $baseOverwriteValue);
        }
        
        $this->assertEquals($expectedParamsContent, $model->getParam('overwrite_id'), "{$modelNameForMessages}: Перезаписанные параметры для overwrite_id (содержимое JSON поля 'params')");
        
        if ($model instanceof Product) {
            global $globalAppDbForTzTest;
            if ($globalAppDbForTzTest instanceof DB) {
                $productTitleFromDb = $globalAppDbForTzTest->fetchValue("SELECT title FROM ".Product::TABLE_NAME." WHERE id = :id", [':id' => 'overwrite_id']);
                $this->assertEquals('Product Title Overwritten by Full Set', $productTitleFromDb, "{$modelNameForMessages}: Title продукта в БД должен обновиться");
            } else {
                $this->fail("Экземпляр DB недоступен для проверки title продукта.");
            }
        }

        // 9. Установка параметра для создания вложенной структуры у существующего ID
        $model->setParam('new_id.profile.age', 30);
        $expectedNewIdParams = ['name' => 'Тестер', 'profile' => ['age' => 30]];
        $this->assertEquals($expectedNewIdParams, $model->getParam('new_id'), "{$modelNameForMessages}: Полные параметры для new_id после добавления вложенной структуры");

        // 10. Удаление всей записи через unsetParam, передав только ID
        $model->setParam('to_delete_record.data', 'некие данные');
        $this->assertNotNull($model->getParam('to_delete_record'), "{$modelNameForMessages}: Запись to_delete_record существует перед удалением");
        $model->unsetParam('to_delete_record'); 
        $this->assertNull($model->getParam('to_delete_record'), "{$modelNameForMessages}: Запись to_delete_record должна быть null после удаления по ID");

        // 11. Полная очистка параметров таблицы
        // Если вы хотите, чтобы данные оставались в БД после теста, закомментируйте следующие 3 строки
        $model->setParam('temp1.val', 1);
        $model->setParam('temp2.val', 2);
        $model->removeParams(); 
        $this->assertNull($model->getParam('temp1'), "{$modelNameForMessages}: temp1 должен быть null после removeParams");
        $this->assertNull($model->getParam('temp2'), "{$modelNameForMessages}: temp2 должен быть null после removeParams");

        if (class_exists(\Codeception\Util\Debug::class)) {
            \Codeception\Util\Debug::debug("--- Тестирование {$modelNameForMessages} завершено ---");
        } else {
            echo "--- Тестирование {$modelNameForMessages} завершено ---\n";
        }
    }
}
?>
