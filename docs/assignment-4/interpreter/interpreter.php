<?php
/**
 * Интерпретатор метамодели
 * Позволяет системе динамически генерировать интерфейсы на основе метаданных
 * без перекомпиляции приложения
 */

class MetamodelInterpreter
{
    private $db;
    private $cache = [];
    
    public function __construct($databaseConnection)
    {
        $this->db = $databaseConnection;
    }
    
    /**
     * Загружает метаданные сущности из БД
     */
    public function loadEntity($entityId)
    {
        if (isset($this->cache[$entityId])) {
            return $this->cache[$entityId];
        }
        
        // Загрузка основной информации о сущности
        $entity = $this->loadEntityMetadata($entityId);
        if (!$entity) {
            return null;
        }
        
        // Загрузка атрибутов
        $entity['attributes'] = $this->loadAttributes($entityId);
        
        // Загрузка связей
        $entity['relationships'] = $this->loadRelationships($entityId);
        
        // Загрузка форм
        $entity['forms'] = $this->loadForms($entityId);
        
        // Загрузка таблиц
        $entity['tables'] = $this->loadTables($entityId);
        
        $this->cache[$entityId] = $entity;
        return $entity;
    }
    
    /**
     * Загружает основную информацию о сущности
     */
    private function loadEntityMetadata($entityId)
    {
        $stmt = $this->db->prepare("SELECT * FROM meta_entities WHERE id = ?");
        $stmt->execute([$entityId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Загружает атрибуты сущности
     */
    private function loadAttributes($entityId)
    {
        $stmt = $this->db->prepare("SELECT * FROM meta_attributes WHERE entity_id = ? ORDER BY id");
        $stmt->execute([$entityId]);
        $attributes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Декодируем JSON конфигурации
        foreach ($attributes as &$attr) {
            if ($attr['validation_rules']) {
                $attr['validation_rules'] = json_decode($attr['validation_rules'], true);
            }
            if ($attr['form_field_config']) {
                $attr['form_field_config'] = json_decode($attr['form_field_config'], true);
            }
            if ($attr['table_column_config']) {
                $attr['table_column_config'] = json_decode($attr['table_column_config'], true);
            }
        }
        
        return $attributes;
    }
    
    /**
     * Загружает связи сущности
     */
    private function loadRelationships($entityId)
    {
        $stmt = $this->db->prepare("SELECT * FROM meta_relationships WHERE entity_id = ? ORDER BY id");
        $stmt->execute([$entityId]);
        $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Декодируем JSON конфигурации
        foreach ($relationships as &$rel) {
            if ($rel['form_field_config']) {
                $rel['form_field_config'] = json_decode($rel['form_field_config'], true);
            }
        }
        
        return $relationships;
    }
    
    /**
     * Загружает формы сущности
     */
    private function loadForms($entityId)
    {
        $stmt = $this->db->prepare("SELECT * FROM meta_forms WHERE entity_id = ? ORDER BY name");
        $stmt->execute([$entityId]);
        $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Декодируем JSON конфигурации
        foreach ($forms as &$form) {
            $form['fields_config'] = json_decode($form['fields_config'], true);
            if ($form['sections_config']) {
                $form['sections_config'] = json_decode($form['sections_config'], true);
            }
        }
        
        return $forms;
    }
    
    /**
     * Загружает таблицы сущности
     */
    private function loadTables($entityId)
    {
        $stmt = $this->db->prepare("SELECT * FROM meta_tables WHERE entity_id = ? ORDER BY name");
        $stmt->execute([$entityId]);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Декодируем JSON конфигурации
        foreach ($tables as &$table) {
            $table['columns_config'] = json_decode($table['columns_config'], true);
            if ($table['filters_config']) {
                $table['filters_config'] = json_decode($table['filters_config'], true);
            }
            if ($table['pagination_config']) {
                $table['pagination_config'] = json_decode($table['pagination_config'], true);
            }
        }
        
        return $tables;
    }
    
    /**
     * Генерирует конфигурацию формы на основе метаданных
     */
    public function generateFormConfig($entityId, $formName = 'create')
    {
        $entity = $this->loadEntity($entityId);
        if (!$entity) {
            return null;
        }
        
        // Находим нужную форму
        $form = null;
        foreach ($entity['forms'] as $f) {
            if ($f['name'] === $formName) {
                $form = $f;
                break;
            }
        }
        
        if (!$form) {
            return null;
        }
        
        // Строим конфигурацию формы
        $formConfig = [
            'id' => $form['id'],
            'entity_id' => $entityId,
            'name' => $form['name'],
            'label' => $form['label'],
            'sections' => [],
            'fields' => []
        ];
        
        // Обрабатываем секции
        $sections = [];
        if ($form['sections_config']) {
            foreach ($form['sections_config'] as $section) {
                $sections[$section['name']] = $section;
            }
        }
        
        // Обрабатываем поля
        foreach ($form['fields_config'] as $fieldConfig) {
            $field = null;
            $sectionName = $fieldConfig['section'] ?? 'default';
            
            // Ищем атрибут или связь
            if (isset($fieldConfig['attribute_id'])) {
                foreach ($entity['attributes'] as $attr) {
                    if ($attr['id'] === $fieldConfig['attribute_id']) {
                        $field = $this->buildFieldFromAttribute($attr, $fieldConfig);
                        break;
                    }
                }
            } elseif (isset($fieldConfig['relationship_id'])) {
                foreach ($entity['relationships'] as $rel) {
                    if ($rel['id'] === $fieldConfig['relationship_id']) {
                        $field = $this->buildFieldFromRelationship($rel, $fieldConfig);
                        break;
                    }
                }
            }
            
            if ($field) {
                $field['order'] = $fieldConfig['order'];
                $field['section'] = $sectionName;
                
                if (!isset($formConfig['sections'][$sectionName])) {
                    $formConfig['sections'][$sectionName] = $sections[$sectionName] ?? [
                        'name' => $sectionName,
                        'order' => 0,
                        'collapsible' => false
                    ];
                }
                
                $formConfig['fields'][] = $field;
            }
        }
        
        // Сортируем поля по порядку
        usort($formConfig['fields'], function($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        
        return $formConfig;
    }
    
    /**
     * Генерирует конфигурацию таблицы на основе метаданных
     */
    public function generateTableConfig($entityId, $tableName = 'list')
    {
        $entity = $this->loadEntity($entityId);
        if (!$entity) {
            return null;
        }
        
        // Находим нужную таблицу
        $table = null;
        foreach ($entity['tables'] as $t) {
            if ($t['name'] === $tableName) {
                $table = $t;
                break;
            }
        }
        
        if (!$table) {
            return null;
        }
        
        // Строим конфигурацию таблицы
        $tableConfig = [
            'id' => $table['id'],
            'entity_id' => $entityId,
            'name' => $table['name'],
            'label' => $table['label'],
            'columns' => [],
            'pagination' => $table['pagination_config'] ?? ['per_page' => 20]
        ];
        
        // Обрабатываем колонки
        foreach ($table['columns_config'] as $columnConfig) {
            $column = null;
            
            if (isset($columnConfig['attribute_id'])) {
                foreach ($entity['attributes'] as $attr) {
                    if ($attr['id'] === $columnConfig['attribute_id']) {
                        $column = $this->buildColumnFromAttribute($attr, $columnConfig);
                        break;
                    }
                }
            } elseif (isset($columnConfig['relationship_id'])) {
                foreach ($entity['relationships'] as $rel) {
                    if ($rel['id'] === $columnConfig['relationship_id']) {
                        $column = $this->buildColumnFromRelationship($rel, $columnConfig);
                        break;
                    }
                }
            }
            
            if ($column) {
                $column['order'] = $columnConfig['order'];
                $column['visible'] = $columnConfig['visible'] ?? true;
                $tableConfig['columns'][] = $column;
            }
        }
        
        // Сортируем колонки по порядку
        usort($tableConfig['columns'], function($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        
        return $tableConfig;
    }
    
    /**
     * Строит поле формы из атрибута
     */
    private function buildFieldFromAttribute($attribute, $fieldConfig)
    {
        $formConfig = $attribute['form_field_config'] ?? [];
        
        return [
            'id' => $attribute['id'],
            'name' => $attribute['name'],
            'label' => $attribute['label'],
            'type' => $formConfig['type'] ?? $this->mapAttributeTypeToFormType($attribute['type']),
            'required' => $attribute['required'],
            'value' => $attribute['default_value'],
            'config' => $formConfig,
            'validation' => $attribute['validation_rules'] ?? []
        ];
    }
    
    /**
     * Строит поле формы из связи
     */
    private function buildFieldFromRelationship($relationship, $fieldConfig)
    {
        $formConfig = $relationship['form_field_config'] ?? [];
        
        return [
            'id' => $relationship['id'],
            'name' => $relationship['name'],
            'label' => $relationship['label'],
            'type' => 'relationship',
            'relationship_type' => $relationship['type'],
            'target_entity' => $relationship['target_entity_id'],
            'foreign_key' => $relationship['foreign_key'],
            'config' => $formConfig,
            'required' => false
        ];
    }
    
    /**
     * Строит колонку таблицы из атрибута
     */
    private function buildColumnFromAttribute($attribute, $columnConfig)
    {
        $columnConfig_attr = $attribute['table_column_config'] ?? [];
        
        return [
            'id' => $attribute['id'],
            'name' => $attribute['name'],
            'label' => $attribute['label'],
            'type' => $attribute['type'],
            'sortable' => $columnConfig_attr['sortable'] ?? false,
            'width' => $columnConfig_attr['width'] ?? null,
            'config' => $columnConfig_attr
        ];
    }
    
    /**
     * Строит колонку таблицы из связи
     */
    private function buildColumnFromRelationship($relationship, $columnConfig)
    {
        return [
            'id' => $relationship['id'],
            'name' => $relationship['name'],
            'label' => $relationship['label'],
            'type' => 'relationship',
            'relationship_type' => $relationship['type'],
            'target_entity' => $relationship['target_entity_id'],
            'display_field' => $relationship['form_field_config']['display_field'] ?? 'id',
            'sortable' => false
        ];
    }
    
    /**
     * Маппинг типа атрибута на тип поля формы
     */
    private function mapAttributeTypeToFormType($attributeType)
    {
        $mapping = [
            'string' => 'text',
            'integer' => 'number',
            'float' => 'number',
            'boolean' => 'checkbox',
            'date' => 'date',
            'datetime' => 'datetime-local',
            'text' => 'textarea',
            'file' => 'file',
            'image' => 'file'
        ];
        
        return $mapping[$attributeType] ?? 'text';
    }
    
    /**
     * Очищает кэш (используется при изменении метамодели)
     */
    public function clearCache($entityId = null)
    {
        if ($entityId) {
            unset($this->cache[$entityId]);
        } else {
            $this->cache = [];
        }
    }
    
    /**
     * Валидирует данные формы на основе метаданных
     */
    public function validateFormData($entityId, $formName, $data)
    {
        $formConfig = $this->generateFormConfig($entityId, $formName);
        if (!$formConfig) {
            return ['valid' => false, 'errors' => ['Form not found']];
        }
        
        $errors = [];
        
        foreach ($formConfig['fields'] as $field) {
            $value = $data[$field['name']] ?? null;
            
            // Проверка обязательности
            if ($field['required'] && empty($value)) {
                $errors[$field['name']] = "Поле '{$field['label']}' обязательно для заполнения";
                continue;
            }
            
            // Валидация по правилам
            if (!empty($value) && !empty($field['validation'])) {
                foreach ($field['validation'] as $rule => $ruleValue) {
                    $error = $this->validateRule($field, $value, $rule, $ruleValue);
                    if ($error) {
                        $errors[$field['name']] = $error;
                        break;
                    }
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Валидация по конкретному правилу
     */
    private function validateRule($field, $value, $rule, $ruleValue)
    {
        switch ($rule) {
            case 'max_length':
                if (strlen($value) > $ruleValue) {
                    return "Поле '{$field['label']}' не должно превышать {$ruleValue} символов";
                }
                break;
            case 'min_length':
                if (strlen($value) < $ruleValue) {
                    return "Поле '{$field['label']}' должно содержать минимум {$ruleValue} символов";
                }
                break;
            case 'pattern':
                if (!preg_match($ruleValue, $value)) {
                    return "Поле '{$field['label']}' имеет неверный формат";
                }
                break;
        }
        
        return null;
    }
}

