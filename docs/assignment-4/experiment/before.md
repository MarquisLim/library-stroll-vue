# Состояние системы ДО изменения метамодели

## Метаданные сущности Artwork

### Атрибуты

1. **id** (integer)
   - Тип: integer
   - Обязательный: да
   - Форма: скрытое поле

2. **title** (string)
   - Тип: string
   - Обязательный: да
   - Максимальная длина: 255
   - Форма: текстовое поле

3. **description** (text)
   - Тип: text
   - Обязательный: нет
   - Форма: многострочное текстовое поле

4. **file_path** (file)
   - Тип: file
   - Обязательный: да
   - Форма: поле загрузки файла

5. **created_at** (datetime)
   - Тип: datetime
   - Обязательный: нет
   - Форма: только для чтения

## Форма создания работы

**Поля формы:**
1. title (текстовое поле)
2. description (многострочное поле)
3. file_path (загрузка файла)
4. user (выпадающий список)

**Секции:**
- Основная информация

## Таблица списка работ

**Колонки:**
1. id
2. title
3. user (связь)
4. created_at

## JSON конфигурация формы (до изменения)

```json
{
  "id": "artwork_create",
  "entity_id": "Artwork",
  "name": "create",
  "label": "Создание работы",
  "fields": [
    {
      "id": "artwork_title",
      "name": "title",
      "label": "Название",
      "type": "text",
      "required": true
    },
    {
      "id": "artwork_description",
      "name": "description",
      "label": "Описание",
      "type": "textarea",
      "required": false
    },
    {
      "id": "artwork_file_path",
      "name": "file_path",
      "label": "Файл",
      "type": "file",
      "required": true
    }
  ]
}
```

