#!/usr/bin/env python3
"""
CASE-инструмент для многоуровневого моделирования (M3-M0)
Поддерживает создание, валидацию и визуализацию моделей предметной области
"""

import json
import sys
from typing import List, Dict, Any, Optional
from dataclasses import dataclass, field
from enum import Enum


# ============================================================================
# M3: Лингвистическая метамодель
# ============================================================================

class DataType(Enum):
    """Типы данных для атрибутов"""
    INTEGER = "Integer"
    STRING = "String"
    TEXT = "Text"
    BOOLEAN = "Boolean"
    DATETIME = "DateTime"
    ENUM = "Enum"
    JSON = "JSON"


class RelationshipType(Enum):
    """Типы связей между сущностями"""
    ONE_TO_ONE = "OneToOne"
    ONE_TO_MANY = "OneToMany"
    MANY_TO_ONE = "ManyToOne"
    MANY_TO_MANY = "ManyToMany"
    POLYMORPHIC = "Polymorphic"


class ConstraintType(Enum):
    """Типы ограничений"""
    PK = "PK"  # Primary Key
    FK = "FK"  # Foreign Key
    REQUIRED = "Required"
    UNIQUE = "Unique"
    AUTO_INCREMENT = "AutoIncrement"
    CASCADE = "Cascade"
    INDEX = "Index"
    HASHED = "Hashed"


@dataclass
class Constraint:
    """Ограничение для атрибута или связи"""
    type: ConstraintType
    expression: Optional[str] = None
    fields: Optional[List[str]] = None
    name: Optional[str] = None


@dataclass
class Attribute:
    """Атрибут сущности"""
    name: str
    type: DataType
    constraints: List[Constraint] = field(default_factory=list)
    nullable: bool = False
    default: Optional[Any] = None
    max_length: Optional[int] = None
    values: Optional[List[str]] = None  # Для Enum
    references: Optional[str] = None  # Для FK: "Entity.attribute"
    required: bool = False


@dataclass
class Relationship:
    """Связь между сущностями"""
    type: RelationshipType
    target: str  # Имя целевой сущности или список для полиморфных
    name: str  # Имя связи
    cardinality: str  # "1:1", "1:N", "N:1", "N:M"
    source: Optional[str] = None  # Имя исходной сущности
    foreign_key: Optional[str] = None
    pivot_table: Optional[str] = None
    pivot_attributes: Optional[List[str]] = None
    polymorphic: bool = False
    morph_type: Optional[str] = None
    self_reference: bool = False
    on_delete: Optional[str] = None  # "Cascade", "SetNull", "Restrict"
    constraints: List[Constraint] = field(default_factory=list)


@dataclass
class Entity:
    """Сущность предметной области"""
    name: str
    description: Optional[str] = None
    attributes: List[Attribute] = field(default_factory=list)
    relationships: List[Relationship] = field(default_factory=list)
    constraints: List[Constraint] = field(default_factory=list)


# ============================================================================
# M1: Концептуальная модель предметной области
# ============================================================================

@dataclass
class DomainModel:
    """Модель предметной области"""
    name: str
    description: Optional[str] = None
    version: str = "1.0"
    entities: List[Entity] = field(default_factory=list)
    
    @classmethod
    def load_from_json(cls, filepath: str) -> 'DomainModel':
        """Загрузка модели из JSON файла"""
        with open(filepath, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        metamodel_data = data.get('metamodel', {})
        entities_data = data.get('entities', [])
        
        entities = []
        for entity_data in entities_data:
            entity = cls._parse_entity(entity_data)
            entities.append(entity)
        
        return cls(
            name=metamodel_data.get('name', 'Unknown Model'),
            description=metamodel_data.get('description'),
            version=metamodel_data.get('version', '1.0'),
            entities=entities
        )
    
    @staticmethod
    def _parse_entity(data: Dict) -> Entity:
        """Парсинг сущности из JSON"""
        attributes = []
        for attr_data in data.get('attributes', []):
            attr = DomainModel._parse_attribute(attr_data)
            attributes.append(attr)
        
        relationships = []
        for rel_data in data.get('relationships', []):
            rel = DomainModel._parse_relationship(rel_data)
            relationships.append(rel)
        
        return Entity(
            name=data['name'],
            description=data.get('description'),
            attributes=attributes,
            relationships=relationships
        )
    
    @staticmethod
    def _parse_attribute(data: Dict) -> Attribute:
        """Парсинг атрибута из JSON"""
        constraints = []
        for constraint_str in data.get('constraints', []):
            try:
                constraint_type = ConstraintType(constraint_str)
                constraints.append(Constraint(type=constraint_type))
            except ValueError:
                pass
        
        attr_type_str = data.get('type', 'String')
        try:
            attr_type = DataType(attr_type_str.upper())
        except ValueError:
            attr_type = DataType.STRING
        
        return Attribute(
            name=data['name'],
            type=attr_type,
            constraints=constraints,
            nullable=data.get('nullable', False),
            default=data.get('default'),
            max_length=data.get('maxLength'),
            values=data.get('values'),
            references=data.get('references'),
            required=data.get('required', False)
        )
    
    @staticmethod
    def _parse_relationship(data: Dict) -> Relationship:
        """Парсинг связи из JSON"""
        rel_type_str = data.get('type', 'OneToMany')
        try:
            rel_type = RelationshipType(rel_type_str)
        except ValueError:
            rel_type = RelationshipType.ONE_TO_MANY
        
        return Relationship(
            type=rel_type,
            target=data['target'],
            name=data['name'],
            cardinality=data.get('cardinality', '1:N'),
            foreign_key=data.get('foreignKey'),
            pivot_table=data.get('pivotTable'),
            pivot_attributes=data.get('pivotAttributes'),
            polymorphic=data.get('polymorphic', False),
            morph_type=data.get('morphType'),
            self_reference=data.get('selfReference', False),
            on_delete=data.get('onDelete')
        )
    
    def to_json(self) -> Dict:
        """Экспорт модели в JSON"""
        return {
            'metamodel': {
                'version': self.version,
                'name': self.name,
                'description': self.description
            },
            'entities': [
                {
                    'name': entity.name,
                    'description': entity.description,
                    'attributes': [
                        {
                            'name': attr.name,
                            'type': attr.type.value,
                            'constraints': [c.type.value for c in attr.constraints],
                            'nullable': attr.nullable,
                            'default': attr.default,
                            'maxLength': attr.max_length,
                            'values': attr.values,
                            'references': attr.references,
                            'required': attr.required
                        }
                        for attr in entity.attributes
                    ],
                    'relationships': [
                        {
                            'type': rel.type.value,
                            'target': rel.target,
                            'name': rel.name,
                            'cardinality': rel.cardinality,
                            'foreignKey': rel.foreign_key,
                            'pivotTable': rel.pivot_table,
                            'pivotAttributes': rel.pivot_attributes,
                            'polymorphic': rel.polymorphic,
                            'morphType': rel.morph_type,
                            'selfReference': rel.self_reference,
                            'onDelete': rel.on_delete
                        }
                        for rel in entity.relationships
                    ]
                }
                for entity in self.entities
            ]
        }
    
    def save_to_json(self, filepath: str):
        """Сохранение модели в JSON файл"""
        data = self.to_json()
        with open(filepath, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2, ensure_ascii=False)


# ============================================================================
# M2: Валидатор онтологической метамодели
# ============================================================================

class ValidationError:
    """Ошибка валидации"""
    def __init__(self, entity: str, message: str, severity: str = "error"):
        self.entity = entity
        self.message = message
        self.severity = severity
    
    def __str__(self):
        return f"[{self.severity.upper()}] {self.entity}: {self.message}"


class MetamodelValidator:
    """Валидатор правил онтологической метамодели (M2)"""
    
    def validate_model(self, model: DomainModel) -> List[ValidationError]:
        """Валидация всей модели"""
        errors = []
        
        # Проверка уникальности имен сущностей
        entity_names = [e.name for e in model.entities]
        if len(entity_names) != len(set(entity_names)):
            errors.append(ValidationError(
                "Model",
                "Дублирующиеся имена сущностей",
                "error"
            ))
        
        # Валидация каждой сущности
        for entity in model.entities:
            errors.extend(self.validate_entity(entity, model))
        
        # Проверка циклических зависимостей
        errors.extend(self.check_circular_dependencies(model))
        
        # Проверка ссылок на несуществующие сущности
        errors.extend(self.validate_entity_references(model))
        
        return errors
    
    def validate_entity(self, entity: Entity, model: DomainModel) -> List[ValidationError]:
        """Валидация сущности"""
        errors = []
        
        # Сущность должна иметь хотя бы один атрибут
        if not entity.attributes:
            errors.append(ValidationError(
                entity.name,
                "Сущность должна иметь хотя бы один атрибут",
                "warning"
            ))
        
        # Должен быть первичный ключ
        has_pk = any(
            any(c.type == ConstraintType.PK for c in attr.constraints)
            for attr in entity.attributes
        )
        if not has_pk:
            errors.append(ValidationError(
                entity.name,
                "Сущность должна иметь первичный ключ (PK)",
                "error"
            ))
        
        # Валидация атрибутов
        for attr in entity.attributes:
            errors.extend(self.validate_attribute(attr, entity))
        
        # Валидация связей
        for rel in entity.relationships:
            errors.extend(self.validate_relationship(rel, entity, model))
        
        return errors
    
    def validate_attribute(self, attr: Attribute, entity: Entity) -> List[ValidationError]:
        """Валидация атрибута"""
        errors = []
        
        # FK должен иметь references
        has_fk = any(c.type == ConstraintType.FK for c in attr.constraints)
        if has_fk and not attr.references:
            errors.append(ValidationError(
                entity.name,
                f"Атрибут {attr.name} имеет FK, но не указан references",
                "error"
            ))
        
        # Enum должен иметь values
        if attr.type == DataType.ENUM and not attr.values:
            errors.append(ValidationError(
                entity.name,
                f"Атрибут {attr.name} типа Enum должен иметь values",
                "error"
            ))
        
        return errors
    
    def validate_relationship(self, rel: Relationship, entity: Entity, model: DomainModel) -> List[ValidationError]:
        """Валидация связи"""
        errors = []
        
        # Проверка существования целевой сущности
        if isinstance(rel.target, str):
            target_exists = any(e.name == rel.target for e in model.entities)
            if not target_exists and not rel.polymorphic:
                errors.append(ValidationError(
                    entity.name,
                    f"Связь {rel.name} ссылается на несуществующую сущность: {rel.target}",
                    "error"
                ))
        
        # ManyToMany должна иметь pivot_table
        if rel.type == RelationshipType.MANY_TO_MANY and not rel.pivot_table:
            errors.append(ValidationError(
                entity.name,
                f"Связь {rel.name} типа ManyToMany должна иметь pivot_table",
                "error"
            ))
        
        # Полиморфная связь должна иметь morph_type
        if rel.polymorphic and not rel.morph_type:
            errors.append(ValidationError(
                entity.name,
                f"Полиморфная связь {rel.name} должна иметь morph_type",
                "error"
            ))
        
        return errors
    
    def check_circular_dependencies(self, model: DomainModel) -> List[ValidationError]:
        """Проверка циклических зависимостей"""
        errors = []
        # Упрощенная проверка - можно расширить
        return errors
    
    def validate_entity_references(self, model: DomainModel) -> List[ValidationError]:
        """Проверка ссылок на существующие сущности"""
        errors = []
        entity_names = {e.name for e in model.entities}
        
        for entity in model.entities:
            for attr in entity.attributes:
                if attr.references:
                    ref_parts = attr.references.split('.')
                    if ref_parts[0] not in entity_names:
                        errors.append(ValidationError(
                            entity.name,
                            f"Атрибут {attr.name} ссылается на несуществующую сущность: {ref_parts[0]}",
                            "error"
                        ))
        
        return errors


# ============================================================================
# Генератор визуализации
# ============================================================================

class GraphVisualizer:
    """Генератор графа связей в формате DOT (Graphviz)"""
    
    def generate_dot(self, model: DomainModel) -> str:
        """Генерация DOT-кода для Graphviz"""
        lines = ["digraph DomainModel {"]
        lines.append("  rankdir=LR;")
        lines.append("  node [shape=record, style=filled, fillcolor=lightblue];")
        lines.append("")
        
        # Добавление узлов (сущности)
        for entity in model.entities:
            attrs_str = "\\n".join([f"{attr.name}: {attr.type.value}" for attr in entity.attributes[:5]])
            if len(entity.attributes) > 5:
                attrs_str += "\\n..."
            lines.append(f'  {entity.name} [label="{{{entity.name}|{attrs_str}}}"];')
        
        lines.append("")
        
        # Добавление связей
        for entity in model.entities:
            for rel in entity.relationships:
                if isinstance(rel.target, str):
                    # Определение стиля стрелки по типу связи
                    arrow_style = self._get_arrow_style(rel)
                    lines.append(f'  {entity.name} -> {rel.target} [label="{rel.name}\\n{rel.cardinality}", {arrow_style}];')
        
        lines.append("}")
        return "\n".join(lines)
    
    def _get_arrow_style(self, rel: Relationship) -> str:
        """Определение стиля стрелки для связи"""
        if rel.type == RelationshipType.ONE_TO_MANY:
            return "arrowhead=normal"
        elif rel.type == RelationshipType.MANY_TO_ONE:
            return "arrowhead=normal, dir=back"
        elif rel.type == RelationshipType.MANY_TO_MANY:
            return "arrowhead=normal, style=dashed"
        elif rel.type == RelationshipType.ONE_TO_ONE:
            return "arrowhead=normal, arrowtail=normal, dir=both"
        else:
            return "arrowhead=normal"
    
    def save_dot(self, model: DomainModel, filepath: str):
        """Сохранение DOT-файла"""
        dot_content = self.generate_dot(model)
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(dot_content)
        print(f"✓ DOT файл сохранен: {filepath}")


# ============================================================================
# Главная функция
# ============================================================================

def main():
    """Главная функция CASE-инструмента"""
    if len(sys.argv) < 2:
        print("Использование: python main.py <команда> [аргументы]")
        print("\nКоманды:")
        print("  validate <model.json>     - Валидация модели")
        print("  visualize <model.json>     - Генерация DOT-графа")
        print("  export <model.json>       - Экспорт модели в JSON")
        sys.exit(1)
    
    command = sys.argv[1]
    
    if command == "validate":
        if len(sys.argv) < 3:
            print("Ошибка: укажите путь к JSON файлу модели")
            sys.exit(1)
        
        model_path = sys.argv[2]
        print(f"Загрузка модели из {model_path}...")
        model = DomainModel.load_from_json(model_path)
        print(f"✓ Модель '{model.name}' загружена ({len(model.entities)} сущностей)")
        
        print("\nВалидация модели...")
        validator = MetamodelValidator()
        errors = validator.validate_model(model)
        
        if errors:
            print(f"\nНайдено {len(errors)} ошибок/предупреждений:\n")
            for error in errors:
                print(f"  {error}")
        else:
            print("✓ Модель валидна!")
    
    elif command == "visualize":
        if len(sys.argv) < 3:
            print("Ошибка: укажите путь к JSON файлу модели")
            sys.exit(1)
        
        model_path = sys.argv[2]
        output_path = sys.argv[3] if len(sys.argv) > 3 else "diagram.dot"
        
        print(f"Загрузка модели из {model_path}...")
        model = DomainModel.load_from_json(model_path)
        
        print("Генерация графа связей...")
        visualizer = GraphVisualizer()
        visualizer.save_dot(model, output_path)
        print(f"\nДля визуализации выполните:")
        print(f"  dot -Tsvg {output_path} -o diagram.svg")
        print(f"  dot -Tpng {output_path} -o diagram.png")
    
    elif command == "export":
        if len(sys.argv) < 3:
            print("Ошибка: укажите путь к JSON файлу модели")
            sys.exit(1)
        
        model_path = sys.argv[2]
        output_path = sys.argv[3] if len(sys.argv) > 3 else "exported_model.json"
        
        print(f"Загрузка модели из {model_path}...")
        model = DomainModel.load_from_json(model_path)
        
        print(f"Экспорт модели в {output_path}...")
        model.save_to_json(output_path)
        print("✓ Модель экспортирована!")
    
    else:
        print(f"Неизвестная команда: {command}")
        sys.exit(1)


if __name__ == "__main__":
    main()

