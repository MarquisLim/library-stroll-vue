# Структура CASE-инструмента для многоуровневого моделирования

## Архитектура инструмента

```
case-tool/
├── metamodel/           # Мета-модели (M2, M3)
│   ├── m3_linguistic.py    # Лингвистическая метамодель
│   ├── m2_ontological.py    # Онтологическая метамодель
│   └── validators.py       # Валидаторы правил
├── models/              # Модели предметной области (M1)
│   └── library_stroll.json  # JSON модель Library Stroll
├── generators/          # Генераторы кода
│   ├── sql_generator.py    # Генерация SQL миграций
│   ├── laravel_generator.py # Генерация Laravel моделей
│   └── json_exporter.py    # Экспорт в JSON
├── visualizers/         # Визуализация
│   ├── graph_generator.py  # Генерация графа связей
│   └── diagram_exporter.py # Экспорт диаграмм
├── parser/              # Парсеры
│   ├── json_parser.py      # Парсинг JSON
│   └── xml_parser.py       # Парсинг XML
└── main.py              # Главный скрипт
```

## Основные классы

### M3: Лингвистическая метамодель

```python
class Entity:
    name: str
    attributes: List[Attribute]
    relationships: List[Relationship]
    
class Attribute:
    name: str
    type: DataType
    constraints: List[Constraint]
    default_value: Optional[Any]
    
class Relationship:
    type: RelationshipType  # OneToOne, OneToMany, ManyToMany, Polymorphic
    source: Entity
    target: Entity
    cardinality: Cardinality
    constraints: List[Constraint]
    
class Constraint:
    type: ConstraintType  # Required, Unique, ForeignKey, Cascade, Index
    expression: str
```

### M2: Онтологическая метамодель

```python
class MetamodelValidator:
    def validate_entity(self, entity: Entity) -> List[ValidationError]
    def validate_relationship(self, rel: Relationship) -> List[ValidationError]
    def check_circular_dependencies(self, entities: List[Entity]) -> List[Error]
    def validate_cardinality(self, rel: Relationship) -> bool
```

### M1: Концептуальная модель

```python
class DomainModel:
    entities: List[Entity]
    relationships: List[Relationship]
    
    def to_json(self) -> dict
    def to_xml(self) -> str
    def validate(self) -> List[ValidationError]
```

### Генераторы

```python
class SQLGenerator:
    def generate_migrations(self, model: DomainModel) -> List[str]
    def generate_table(self, entity: Entity) -> str
    def generate_foreign_keys(self, relationships: List[Relationship]) -> List[str]
    
class LaravelGenerator:
    def generate_model(self, entity: Entity) -> str
    def generate_relationships(self, entity: Entity) -> str
    
class GraphVisualizer:
    def generate_dot(self, model: DomainModel) -> str
    def generate_svg(self, dot_content: str) -> str
    def generate_png(self, dot_content: str) -> bytes
```

## Пример использования

```python
# 1. Загрузка модели
model = DomainModel.load_from_json('models/library_stroll.json')

# 2. Валидация
validator = MetamodelValidator()
errors = validator.validate_model(model)
if errors:
    print("Ошибки валидации:", errors)
    exit(1)

# 3. Генерация SQL
sql_gen = SQLGenerator()
migrations = sql_gen.generate_migrations(model)
for migration in migrations:
    print(migration)

# 4. Визуализация
viz = GraphVisualizer()
dot = viz.generate_dot(model)
svg = viz.generate_svg(dot)
with open('diagram.svg', 'w') as f:
    f.write(svg)

# 5. Экспорт в JSON
json_export = model.to_json()
with open('export.json', 'w') as f:
    json.dump(json_export, f, indent=2)
```

