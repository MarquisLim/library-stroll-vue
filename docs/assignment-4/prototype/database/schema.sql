-- Схема базы данных для хранения метаданных
-- Система управления метаданными для Library Stroll

-- Таблица сущностей
CREATE TABLE IF NOT EXISTS meta_entities (
    id VARCHAR(100) PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(255) NOT NULL,
    plural_label VARCHAR(255) NOT NULL,
    table_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Таблица атрибутов
CREATE TABLE IF NOT EXISTS meta_attributes (
    id VARCHAR(100) PRIMARY KEY,
    entity_id VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(255) NOT NULL,
    type ENUM('string', 'integer', 'float', 'boolean', 'date', 'datetime', 'text', 'file', 'image') NOT NULL,
    required BOOLEAN DEFAULT FALSE,
    max_length INT,
    default_value TEXT,
    validation_rules JSON,
    form_field_config JSON,
    table_column_config JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES meta_entities(id) ON DELETE CASCADE,
    UNIQUE KEY unique_entity_attribute (entity_id, name)
);

-- Таблица связей
CREATE TABLE IF NOT EXISTS meta_relationships (
    id VARCHAR(100) PRIMARY KEY,
    entity_id VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(255) NOT NULL,
    type ENUM('belongsTo', 'hasMany', 'hasOne', 'belongsToMany') NOT NULL,
    target_entity_id VARCHAR(100) NOT NULL,
    foreign_key VARCHAR(100),
    pivot_table VARCHAR(100),
    form_field_config JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES meta_entities(id) ON DELETE CASCADE,
    FOREIGN KEY (target_entity_id) REFERENCES meta_entities(id) ON DELETE CASCADE,
    UNIQUE KEY unique_entity_relationship (entity_id, name)
);

-- Таблица форм
CREATE TABLE IF NOT EXISTS meta_forms (
    id VARCHAR(100) PRIMARY KEY,
    entity_id VARCHAR(100) NOT NULL,
    name VARCHAR(50) NOT NULL,
    label VARCHAR(255) NOT NULL,
    fields_config JSON NOT NULL,
    sections_config JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES meta_entities(id) ON DELETE CASCADE,
    UNIQUE KEY unique_entity_form (entity_id, name)
);

-- Таблица таблиц (списков)
CREATE TABLE IF NOT EXISTS meta_tables (
    id VARCHAR(100) PRIMARY KEY,
    entity_id VARCHAR(100) NOT NULL,
    name VARCHAR(50) NOT NULL,
    label VARCHAR(255) NOT NULL,
    columns_config JSON NOT NULL,
    filters_config JSON,
    pagination_config JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES meta_entities(id) ON DELETE CASCADE,
    UNIQUE KEY unique_entity_table (entity_id, name)
);

-- Индексы для оптимизации
CREATE INDEX idx_meta_attributes_entity ON meta_attributes(entity_id);
CREATE INDEX idx_meta_relationships_entity ON meta_relationships(entity_id);
CREATE INDEX idx_meta_relationships_target ON meta_relationships(target_entity_id);
CREATE INDEX idx_meta_forms_entity ON meta_forms(entity_id);
CREATE INDEX idx_meta_tables_entity ON meta_tables(entity_id);

