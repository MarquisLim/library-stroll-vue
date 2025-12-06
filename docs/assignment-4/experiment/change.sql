-- Шаг 1: Добавление нового атрибута "tags" в метаданные
INSERT INTO meta_attributes (
    id,
    entity_id,
    name,
    label,
    type,
    required,
    max_length,
    form_field_config,
    table_column_config
) VALUES (
    'artwork_tags',
    'Artwork',
    'tags',
    'Теги',
    'string',
    FALSE,
    500,
    '{"type": "text", "placeholder": "Введите теги через запятую", "help_text": "Разделяйте теги запятыми"}',
    '{"visible": true, "sortable": false, "width": 200}'
);

-- Шаг 2: Обновление формы создания - добавление поля tags
UPDATE meta_forms
SET fields_config = JSON_ARRAY_APPEND(
    fields_config,
    '$',
    JSON_OBJECT(
        'attribute_id', 'artwork_tags',
        'order', 5,
        'section', 'Основная информация'
    )
)
WHERE id = 'artwork_create';

-- Шаг 3: Обновление таблицы списка - добавление колонки tags
UPDATE meta_tables
SET columns_config = JSON_ARRAY_APPEND(
    columns_config,
    '$',
    JSON_OBJECT(
        'attribute_id', 'artwork_tags',
        'order', 5,
        'visible', true
    )
)
WHERE id = 'artwork_list';

-- Шаг 4: Очистка кэша


