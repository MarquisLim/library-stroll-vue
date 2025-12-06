# Генератор UML из DFD

## Описание

Автоматический генератор UML диаграмм из DFD описания. Реализован на Python.

## Установка

Требования:
- Python 3.7+

Установка зависимостей не требуется (используются только стандартные библиотеки).

## Использование

### Базовое использование

```python
from dfd_to_uml import generate_uml_from_dfd

# Загрузить DFD описание из JSON
with open('dfd_description.json', 'r', encoding='utf-8') as f:
    dfd_data = json.load(f)

# Сгенерировать UML
uml_result = generate_uml_from_dfd(dfd_data)

# Сохранить результат
with open('uml_output.json', 'w', encoding='utf-8') as f:
    json.dump(uml_result, f, indent=2, ensure_ascii=False)
```

### Формат входного JSON (DFD)

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
      "process_ids": ["2.1", "2.2", "2.3"]
    }
  ]
}
```

### Формат выходного JSON (UML)

```json
{
  "classes": [
    {
      "name": "FileValidator",
      "methods": [
        {
          "name": "validate",
          "parameters": [{"name": "file", "type": "Object"}],
          "return_type": "ValidationResult",
          "visibility": "public"
        }
      ],
      "attributes": [],
      "associations": []
    }
  ],
  "sequence_diagrams": [
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
  ]
}
```

## Примеры

См. папку `examples/` для примеров использования.

## Правила трансформации

1. **Процесс → Класс**: Процесс с состоянием становится классом
2. **Процесс → Метод**: Элементарный процесс становится методом
3. **Поток данных → Параметр/Возврат**: Входной поток → параметр, выходной → возврат
4. **Хранилище → Repository**: Хранилище данных становится Repository классом
5. **Последовательность → Sequence Diagram**: Цепочка процессов → диаграмма последовательности

## Ограничения

- Не поддерживает сложные условия и ветвления
- Не генерирует диаграммы активностей автоматически
- Требует ручной настройки типов данных

