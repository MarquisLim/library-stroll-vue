# Примеры использования генератора DFD → UML

## Быстрый запуск

### Вариант 1: Запуск готового примера

```bash
cd docs/assignment-3/generator/examples
python run_example.py
```

Этот скрипт:
1. Загружает пример DFD описания из `example_dfd.json`
2. Показывает входные данные (DFD процессы)
3. Запускает генератор
4. Показывает результат (UML классы и диаграммы)
5. Сохраняет результат в `generated_uml.json`

### Вариант 2: Использование генератора напрямую

```python
from dfd_to_uml import generate_uml_from_dfd
import json

# Загрузить DFD описание
with open('example_dfd.json', 'r', encoding='utf-8') as f:
    dfd_data = json.load(f)

# Сгенерировать UML
uml_result = generate_uml_from_dfd(dfd_data)

# Вывести результат
print(json.dumps(uml_result, indent=2, ensure_ascii=False))
```

### Вариант 3: Использование встроенного примера

```bash
cd docs/assignment-3/generator
python dfd-to-uml.py
```

Генератор запустится с встроенным примером и выведет результат.

## Что вы увидите

### Входные данные (DFD):

```json
{
  "processes": [
    {
      "id": "2.1",
      "name": "2.1 Валидация файла",
      "inputs": ["Файл"],
      "outputs": ["Валидный файл"]
    }
  ]
}
```

### Выходные данные (UML):

```json
{
  "classes": [
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
  ],
  "sequence_diagrams": [...]
}
```

## Структура файлов

- `example_dfd.json` - Пример DFD описания
- `run_example.py` - Скрипт для демонстрации работы
- `generated_uml.json` - Результат генерации (создается автоматически)

## Демонстрация трансформации

Запуск `run_example.py` покажет:

1. **DFD Процессы** - исходные данные
2. **Процесс трансформации** - что происходит
3. **UML Классы** - результат трансформации
4. **Диаграммы последовательности** - автоматически созданные диаграммы
5. **Сравнение** - пример трансформации одного процесса

## Пример вывода

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

