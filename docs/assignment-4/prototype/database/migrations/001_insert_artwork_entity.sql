-- Миграция: Добавление метаданных для сущности Artwork

-- Вставка сущности Artwork
INSERT INTO meta_entities (id, name, label, plural_label, table_name) VALUES
('Artwork', 'Artwork', 'Художественная работа', 'Художественные работы', 'artworks');

-- Вставка атрибутов Artwork
INSERT INTO meta_attributes (id, entity_id, name, label, type, required, max_length, form_field_config, table_column_config) VALUES
('artwork_id', 'Artwork', 'id', 'ID', 'integer', TRUE, NULL, 
 '{"type": "hidden", "readonly": true}', 
 '{"visible": true, "sortable": true, "width": 80}'),

('artwork_title', 'Artwork', 'title', 'Название', 'string', TRUE, 255,
 '{"type": "text", "placeholder": "Введите название работы"}',
 '{"visible": true, "sortable": true, "width": 200}'),

('artwork_description', 'Artwork', 'description', 'Описание', 'text', FALSE, NULL,
 '{"type": "textarea", "rows": 5, "placeholder": "Введите описание работы"}',
 '{"visible": true, "sortable": false, "width": 300}'),

('artwork_file_path', 'Artwork', 'file_path', 'Файл', 'file', TRUE, NULL,
 '{"type": "file", "accept": "image/*", "multiple": false}',
 '{"visible": true, "sortable": false, "width": 150}'),

('artwork_created_at', 'Artwork', 'created_at', 'Дата создания', 'datetime', FALSE, NULL,
 '{"type": "datetime", "readonly": true}',
 '{"visible": true, "sortable": true, "width": 150}');

-- Вставка связи с User
INSERT INTO meta_relationships (id, entity_id, name, label, type, target_entity_id, foreign_key, form_field_config) VALUES
('artwork_user', 'Artwork', 'user', 'Автор', 'belongsTo', 'User', 'user_id',
 '{"type": "select", "display_field": "name", "searchable": true}');

-- Вставка формы создания
INSERT INTO meta_forms (id, entity_id, name, label, fields_config, sections_config) VALUES
('artwork_create', 'Artwork', 'create', 'Создание работы',
 '[
   {"attribute_id": "artwork_title", "order": 1, "section": "Основная информация"},
   {"attribute_id": "artwork_description", "order": 2, "section": "Основная информация"},
   {"attribute_id": "artwork_file_path", "order": 3, "section": "Основная информация"},
   {"relationship_id": "artwork_user", "order": 4, "section": "Основная информация"}
 ]',
 '[
   {"name": "Основная информация", "order": 1, "collapsible": false}
 ]');

-- Вставка таблицы списка
INSERT INTO meta_tables (id, entity_id, name, label, columns_config, pagination_config) VALUES
('artwork_list', 'Artwork', 'list', 'Список работ',
 '[
   {"attribute_id": "artwork_id", "order": 1, "visible": true},
   {"attribute_id": "artwork_title", "order": 2, "visible": true},
   {"relationship_id": "artwork_user", "order": 3, "visible": true},
   {"attribute_id": "artwork_created_at", "order": 4, "visible": true}
 ]',
 '{"per_page": 20, "show_pagination": true}');

