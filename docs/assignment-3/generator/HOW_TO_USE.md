# Как использовать генератор DFD → UML

## Быстрый старт

### 1. Запуск демонстрационного примера

```bash
cd docs/assignment-3/generator/examples
python run_example.py
```

Этот скрипт покажет:
- ✅ Входные DFD процессы
- ✅ Процесс трансформации
- ✅ Результат - UML классы и диаграммы
- ✅ Сохранение результата в JSON

### 2. Использование в своем коде

```python
from dfd_to_uml import generate_uml_from_dfd
import json

# Загрузить DFD описание
with open('my_dfd.json', 'r', encoding='utf-8') as f:
    dfd_data = json.load(f)

# Сгенерировать UML
uml_result = generate_uml_from_dfd(dfd_data)

# Использовать результат
for uml_class in uml_result['classes']:
    print(f"Класс: {uml_class['name']}")
    for method in uml_class['methods']:
        print(f"  Метод: {method['name']}")
```

## Формат входных данных

Создайте JSON файл с DFD описанием:

```json
{
  "processes": [
    {
      "id": "2.1",
      "name": "2.1 Валидация файла",
      "inputs": ["Файл"],
      "outputs": ["Валидный файл"],
      "data_stores": [],
      "external_entities": []
    }
  ],
  "data_stores": ["База данных"],
  "process_chains": [
    {
      "name": "Загрузка работы",
      "process_ids": ["2.1", "2.2"]
    }
  ]
}
```

## Что получается на выходе

### UML Классы

Каждый DFD процесс преобразуется в UML класс:

```json
{
  "name": "FileValidator",
  "methods": [
    {
      "name": "validate",
      "parameters": [{"name": "file", "type": "Object"}],
      "return_type": "Валидный файл",
      "visibility": "public"
    }
  ]
}
```

### Диаграммы последовательности

Цепочки процессов преобразуются в диаграммы последовательности:

```json
{
  "name": "Sequence_Валидация",
  "participants": ["FileValidator", "ImageProcessor"],
  "messages": [
    {
      "from": "FileValidator",
      "to": "ImageProcessor",
      "message": "process",
      "parameters": ["Изображение"]
    }
  ]
}
```

## Пример вывода

При запуске `run_example.py` вы увидите:

```
============================================================
  ГЕНЕРАТОР DFD → UML
============================================================

📋 Процесс 2.1: 2.1 Валидация файла
   Входы: Файл
   Выходы: Валидный файл

   ↓ ТРАНСФОРМАЦИЯ ↓

🏛️  Класс: FileValidator
   Методы:
     public validate(file: Object): Валидный файл
```

## Правила трансформации

1. **Процесс → Класс:** "2.1 Валидация файла" → `FileValidator`
2. **Входы → Параметры:** ["Файл"] → `file: Object`
3. **Выходы → Возврат:** ["Валидный файл"] → `return_type: "Валидный файл"`
4. **Хранилище → Repository:** "База данных" → `БазаДанныхRepository`
5. **Цепочка → Sequence:** [2.1, 2.2, 2.3] → диаграмма последовательности

## Где найти примеры

- `examples/example_dfd.json` - пример DFD описания
- `examples/run_example.py` - скрипт демонстрации
- `examples/generated_uml.json` - результат генерации (создается автоматически)

## Дополнительная информация

- Подробное объяснение работы: `EXPLANATION.md`
- Инструкции по использованию: `README.md`
- Примеры: папка `examples/`

