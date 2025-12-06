# Прототип системы управления метаданными

## Описание

Прототип метаданных-управляемой системы для Library Stroll, реализующий DSM-подход.

## Структура

```
prototype/
├── database/           # База данных метаданных
│   ├── schema.sql     # Схема БД
│   └── migrations/    # Миграции с примерами метаданных
├── backend/           # Backend код (Laravel)
└── frontend/          # Frontend код (Vue.js)
```

## Установка

### 1. Создание базы данных

```sql
-- Создать БД
CREATE DATABASE library_stroll_meta;

-- Выполнить схему
mysql -u root library_stroll_meta < database/schema.sql

-- Выполнить миграции
mysql -u root library_stroll_meta < database/migrations/001_insert_artwork_entity.sql
```

### 2. Настройка подключения

В Laravel `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=library_stroll_meta
DB_USERNAME=root
DB_PASSWORD=
```

### 3. Использование интерпретатора

```php
use App\Services\MetamodelInterpreter;

$interpreter = new MetamodelInterpreter(DB::connection()->getPdo());

// Генерация конфигурации формы
$formConfig = $interpreter->generateFormConfig('Artwork', 'create');

// Генерация конфигурации таблицы
$tableConfig = $interpreter->generateTableConfig('Artwork', 'list');
```

## API Endpoints

### GET /api/meta/{entityId}/form/{formName}

Получить конфигурацию формы.

**Пример:**
```
GET /api/meta/Artwork/form/create
```

**Ответ:**
```json
{
  "id": "artwork_create",
  "entity_id": "Artwork",
  "name": "create",
  "label": "Создание работы",
  "fields": [...]
}
```

### GET /api/meta/{entityId}/table/{tableName}

Получить конфигурацию таблицы.

**Пример:**
```
GET /api/meta/Artwork/table/list
```

### POST /api/meta/{entityId}/validate/{formName}

Валидация данных формы.

**Пример:**
```
POST /api/meta/Artwork/validate/create
Body: {
  "title": "Название работы",
  "description": "Описание"
}
```

## Frontend компоненты

### DynamicForm.vue

```vue
<template>
  <form @submit.prevent="submit">
    <div v-for="section in formConfig.sections" :key="section.name">
      <h3>{{ section.name }}</h3>
      <div v-for="field in getFieldsForSection(section.name)" :key="field.id">
        <label>{{ field.label }}</label>
        <input 
          v-if="field.type === 'text'"
          :type="field.type"
          v-model="formData[field.name]"
          :required="field.required"
        />
        <!-- другие типы полей -->
      </div>
    </div>
    <button type="submit">Сохранить</button>
  </form>
</template>

<script>
export default {
  data() {
    return {
      formConfig: null,
      formData: {}
    }
  },
  async mounted() {
    const response = await axios.get(`/api/meta/${this.entityId}/form/${this.formName}`);
    this.formConfig = response.data;
  }
}
</script>
```

### DynamicTable.vue

```vue
<template>
  <table>
    <thead>
      <tr>
        <th v-for="column in tableConfig.columns" :key="column.id">
          {{ column.label }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="item in items" :key="item.id">
        <td v-for="column in tableConfig.columns" :key="column.id">
          {{ getCellValue(item, column) }}
        </td>
      </tr>
    </tbody>
  </table>
</template>
```

## Примеры использования

См. файлы в папке `experiment/` для примеров изменения метамодели.

