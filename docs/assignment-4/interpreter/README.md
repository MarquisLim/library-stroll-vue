# Интерпретатор метамодели

## Описание

Интерпретатор метамодели (`MetamodelInterpreter`) - это PHP класс, который загружает метаданные из базы данных и генерирует конфигурации интерфейсов (форм и таблиц) для динамического отображения во frontend.

## Основные возможности

1. **Загрузка метаданных** - из БД с кэшированием
2. **Генерация форм** - автоматическое создание конфигурации формы
3. **Генерация таблиц** - автоматическое создание конфигурации таблицы
4. **Валидация данных** - проверка данных на основе метаданных

## Использование

### Базовое использование

```php
require_once 'interpreter.php';

// Создание подключения к БД
$db = new PDO('mysql:host=localhost;dbname=library_stroll', $user, $pass);

// Создание интерпретатора
$interpreter = new MetamodelInterpreter($db);

// Генерация конфигурации формы
$formConfig = $interpreter->generateFormConfig('Artwork', 'create');

// Генерация конфигурации таблицы
$tableConfig = $interpreter->generateTableConfig('Artwork', 'list');

// Валидация данных формы
$validation = $interpreter->validateFormData('Artwork', 'create', [
    'title' => 'Название работы',
    'description' => 'Описание'
]);
```

### Пример результата генерации формы

```php
$formConfig = $interpreter->generateFormConfig('Artwork', 'create');

// Результат:
[
    'id' => 'artwork_create',
    'entity_id' => 'Artwork',
    'name' => 'create',
    'label' => 'Создание работы',
    'fields' => [
        [
            'id' => 'artwork_title',
            'name' => 'title',
            'label' => 'Название',
            'type' => 'text',
            'required' => true,
            'order' => 1
        ],
        // ... другие поля
    ]
]
```

### Очистка кэша

При изменении метаданных в БД необходимо очистить кэш:

```php
// Очистка кэша для конкретной сущности
$interpreter->clearCache('Artwork');

// Очистка всего кэша
$interpreter->clearCache();
```

## API методов

### loadEntity($entityId)

Загружает полные метаданные сущности (включая атрибуты, связи, формы, таблицы).

**Параметры:**
- `$entityId` (string) - ID сущности

**Возвращает:** Массив с метаданными сущности или null

### generateFormConfig($entityId, $formName)

Генерирует конфигурацию формы на основе метаданных.

**Параметры:**
- `$entityId` (string) - ID сущности
- `$formName` (string) - имя формы (create, edit, view)

**Возвращает:** Массив с конфигурацией формы или null

### generateTableConfig($entityId, $tableName)

Генерирует конфигурацию таблицы на основе метаданных.

**Параметры:**
- `$entityId` (string) - ID сущности
- `$tableName` (string) - имя таблицы (обычно 'list')

**Возвращает:** Массив с конфигурацией таблицы или null

### validateFormData($entityId, $formName, $data)

Валидирует данные формы на основе метаданных.

**Параметры:**
- `$entityId` (string) - ID сущности
- `$formName` (string) - имя формы
- `$data` (array) - данные для валидации

**Возвращает:** Массив с результатом валидации:
```php
[
    'valid' => true/false,
    'errors' => ['field_name' => 'error message']
]
```

### clearCache($entityId = null)

Очищает кэш метаданных.

**Параметры:**
- `$entityId` (string, optional) - ID сущности для очистки конкретного кэша

## Интеграция с Laravel

```php
// В Laravel контроллере
class MetaController extends Controller
{
    public function getFormConfig($entityId, $formName)
    {
        $interpreter = app(MetamodelInterpreter::class);
        $config = $interpreter->generateFormConfig($entityId, $formName);
        
        return response()->json($config);
    }
    
    public function getTableConfig($entityId, $tableName)
    {
        $interpreter = app(MetamodelInterpreter::class);
        $config = $interpreter->generateTableConfig($entityId, $tableName);
        
        return response()->json($config);
    }
}
```

## Производительность

- **Кэширование:** Метаданные кэшируются в памяти для быстрого доступа
- **Ленивая загрузка:** Метаданные загружаются только при необходимости
- **Оптимизация:** Используются индексы БД для быстрой загрузки

## Ограничения

- Требуется очистка кэша при изменении метаданных
- Сложные типы данных могут требовать дополнительной обработки
- Валидация ограничена правилами, определенными в метаданных

