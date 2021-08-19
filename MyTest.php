<?php

    class DB
    {
        // Установить соединение с БД, создать таблицу, если её нет
        function __construct(string $file, string $tableCreationCommand) 
        {
            global $db;
            $db = new SQLite3($file);
            $db->exec($tableCreationCommand);
        }

        // Выполнить чтение из БД
        function read(string $query)
        {
            global $db;
            $query = $db->query($query);
            if ($query == false)
            {
                return null; // Запрос составлен некорректно
            }
            $result = $query->fetchArray();
            return $result == false ? null : $result[0]; // Если по запросу ничего не найдено, то возвращается null
        }

        // Записать в БД
        function write(string $query)
        {
            global $db;
            $db->exec($query);
        }
    }

    trait WorkWithParams
    {
        // Очистить таблицу
        function rParams(string $table)
        {
            parent::write("DELETE FROM $table");
        }

        // Получить поле params по определённому ключу id
        function gParam(string $key, string $table)
        {
            $paramParts = explode(".", $key);
            $query = parent::read("SELECT params FROM $table WHERE id = '" . $paramParts[0] . "'"); // Считать поле params
            if ($query == null)
            {
                return null; // Нужной записи нет
            }
            $data = unserialize($query);
            if (!is_array($data) && count($paramParts) > 1) // Обработка случая, когда ожидается массив, а на самом деле тип элемента другой
            {
                return null;
            }

            // Данный цикл получает значение из ассоциативого массива. Каждая итерация цикла соответствует уровню вложенности массива
            for ($i = 1; $i < count($paramParts); $i++)
            {
                $key = $paramParts[$i];
                if (!array_key_exists($key, $data))
                {
                    return null; // Отсутствует нужный элемент
                }
                $data = $data[$key]; // Присвоить переменной с массивом тот из его элементов, который требовалось найти.
                // Этот элемент может быть вложенным массивом, в котором содержится искомое значение
            }
            return $data;
        }

        // Заполнить поле params по определённому ключу id
        function sParam(string $key, $value, string $table)
        {
            $paramParts = explode(".", $key);
            $query = parent::read("SELECT params FROM $table WHERE id = '" . $paramParts[0] . "'"); // Считать поле params, которое требуется изменить

            // Предположим, что в трёхмерном массиве должно быть изменено значение (в массиве 2-го уровня вложенности).
            // $arrayDimensions[0] хранит исходный массив, $arrayDimensions[1] - массив 1-го уровня вложенности, $arrayDimensions[2] - массив 2-го уровня вложенности,
            // содержащий нужный элемент.
            // В $arrayDimensions[2] искомый элемент меняется на новый, $arrayDimensions[2] копируется в качестве вложенного массива в $arrayDimensions[1],
            // а $arrayDimensions[1] - в массив $arrayDimensions[0], который сохраняется в БД после сериализации
            
            if ($query == null)
            {
                $arrayDimensions[0] = array(); // В БД нет записи
            }
            else
            {
                $arrayDimensions[0] = unserialize($query); // Десериализовать данные
            }

            // Данный цикл получает значение из ассоциативного массива и создаёт его в случае отсутствия. Каждая итерация цикла соответствует уровню вложенности массива
            for ($i = 1; $i < count($paramParts); $i++)
            {
                $key = $paramParts[$i];
                if (is_array($arrayDimensions[$i - 1]))
                {
                    if (!array_key_exists($key, $arrayDimensions[$i - 1]))
                    {
                        $arrayDimensions[$i - 1][$key] = array(); // Если нужный элемент отсутствует, создать вложенный массив.
                        // Если окажется, что вместо массива должны быть данные другого типа, тип автоматически изменится при присваивании.
                    }
                }
                $arrayDimensions[$i] = $arrayDimensions[$i - 1][$key]; // Вложенный массив с нужным элементом
            }
            $arrayDimensions[$i - 1] = $value;
            for ($i = count($paramParts) - 1; $i > 0; $i--)
            {
                $arrayDimensions[$i - 1][$paramParts[$i]] = $arrayDimensions[$i]; // Обновить вложенные массивы:
                // $arrayDimensions[2] скопировать в качестве вложенного массива в $arrayDimensions[1],
                // а $arrayDimensions[1] - в массив $arrayDimensions[0], который сохраняется в БД
            }
            if ($query == null) // Если записи не было в БД, то добавить новую, иначе обновить старую
            {
                parent::write("INSERT INTO $table(id, params) VALUES('" . $paramParts[0] . "', '" . serialize($arrayDimensions[0]) . "')");
            }
            else
            {
                parent::write("UPDATE $table SET params = '" . serialize($arrayDimensions[0]) . "' WHERE id = '" . $paramParts[0] . "'");
            }
        }

        // Удалить запись из БД или элемент массива params по определённому ключу id
        function uParam(string $key, string $table)
        {
            $paramParts = explode(".", $key);
            $query = parent::read("SELECT params FROM $table WHERE id = '" . $paramParts[0] . "'"); // Считать поле params
            if ($query != null)
            {
                if (count($paramParts) == 1) // Если это условие выполняется, то нужно просто удалить запись, иначе удалить элемент из params
                {
                    parent::write("DELETE FROM $table WHERE id = '" . $paramParts[0] . "'");
                    return;
                }
                $arrayDimensions[0] = unserialize($query);

                // Обработка массива $arrayDimensions, аналогичная обработке в sParam

                // Данный цикл получает значение из ассоциативного массива. Каждая итерация цикла соответствует уровню вложенности массива
                for ($i = 1; $i < count($paramParts) - 1; $i++)
                {
                    $key = $paramParts[$i];
                    if (!is_array($arrayDimensions[$i - 1]) || !array_key_exists($key, $arrayDimensions[$i - 1]))
                    {
                        return; // Нет элемента, который требуется удалить
                    }
                    $arrayDimensions[$i] = $arrayDimensions[$i - 1][$key]; // Вложенный массив с нужным элементом
                }
                $key = $paramParts[$i];
                $i = $i - 1; // После этого оператора $arrayDimensions[$i][$key] - элемент, который необходимо удалить

                if (!is_array($arrayDimensions[$i]) || !array_key_exists($key, $arrayDimensions[$i]))
                {
                    return; // Нет элемента, который требуется удалить
                }
                unset($arrayDimensions[$i][$key]); // Удалить элемент
                for ($i = count($paramParts) - 2; $i > 0; $i--)
                {
                    $arrayDimensions[$i - 1][$paramParts[$i]] = $arrayDimensions[$i]; // Последовательно обновить вложенные массивы
                }
                parent::write("UPDATE $table SET params = '" . serialize($arrayDimensions[0]) . "' WHERE id = '" . $paramParts[0] . "'"); // Внести изменения в БД
            }
        }

        // Удалить записи из БД или элементы массивов params по определённым ключам id
        function uParams($keys, string $table)
        {
            if (is_array($keys))
            {
                foreach($keys as $keyStr) // Массив ключей id
                {
                    $str = explode(",", $keyStr); // Строка может содержать несколько ключей
                    foreach($str as $key)
                    {
                        $this->unsetParam($key, $table); // Вызвать unsetParam для каждого ключа
                    }
                }
            }
            else
            {
                $str = explode(",", $keys); // Строка может содержать несколько ключей
                foreach($str as $key)
                {
                    $this->unsetParam($key, $table); // Вызвать unsetParam для каждого ключа
                }
            }
        }
    }

    class Order extends DB
    {
        use WorkWithParams;
        function __construct()
        {
            parent::__construct("data.db", "CREATE TABLE IF NOT EXISTS Orders(id TEXT, created_at TEXT, params TEXT)");
        }

        // Методам в трейте нужно передать название таблицы ("Orders"), с которой они работают в этом классе    

        function removeParams()
        {
            $this->rParams("Orders");
        }
        function getParam(string $key)
        {
            return $this->gParam($key, "Orders");
        }
        function setParam(string $key, $value)
        {
            $this->sParam($key, $value, "Orders");
        }
        function unsetParam(string $key)
        {
            $this->uParam($key, "Orders");
        }
        function unsetParams($keys)
        {
            $this->uParams($keys, "Orders");
        }
    }

    class Product extends DB
    {
        use WorkWithParams;
        function __construct()
        {
            parent::__construct("data.db", "CREATE TABLE IF NOT EXISTS Products(id TEXT, title TEXT, created_at TEXT, params TEXT)");
        }

        // Методам в трейте нужно передать название таблицы ("Products"), с которой они работают в этом классе    

        function removeParams()
        {
            $this->rParams("Products");
        }
        function getParam($key)
        {
            return $this->gParam($key, "Products");
        }
        function setParam($key, $value)
        {
            $this->sParam($key, $value, "Products");
        }
        function unsetParam($key)
        {
            $this->uParam($key, "Products");
        }
        function unsetParams($keys)
        {
            $this->uParams($keys, "Products");
        }
    }

    $test = new ParamsTest();
    $test->testOrderParams();
    $test->testProductParams();

    class ParamsTest extends \Codeception\Test\Unit
    {

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
        * @param SimpleParamsModel $model
        * @throws Exception
        */
        protected function processParamsTest($model)
        {
            $model->removeParams();
            $model->setParam('simple', 1);
            
            // Если используется ключ вида "x.y.z", то "x" - значение поля id, а соответствующее ему поле params является ассоциативным массивом.
            // "y" - ключ одного из элементов этого массива. В нём содержится элемент с ключом "z", который и нужно обработать.
            $model->setParam('array.data', [
                'one' => 1,
                'two' => 2
            ]);
            $model->setParam('array.data.three', 3);
            $model->setParam('array.data.five', 5);
            $model->unsetParam('array.data.three');
            $model->unsetParam('array.data.four.five'); //Unset will false
            $model->unsetParams('a,b,array.data.five');
            $model->unsetParams(['c', 'd']);

            $this->assertEquals($model->getParam('simple'), 1, 'Simple Key');
            $this->assertEquals($model->getParam('array.data.two'), 2, 'Simple Array Key');
            $this->assertArrayHasKey('one', $model->getParam('array.data'), 'Simple Array Type');
            $this->assertArraySubset([
                'one' => 1,
                'two' => 2
            ], $model->getParam('array.data'), false, 'Array Contains Data');
            $this->assertNull($model->getParam('array.data.three'));
            $this->assertNull($model->getParam('array.data.four'));
            $this->assertNull($model->getParam('array.data.five'));
            $this->assertNull($model->getParam('a'));
            $this->assertNull($model->getParam('b'));
            $this->assertNull($model->getParam('c'));
            $this->assertNull($model->getParam('d'));
        }
    }
?>